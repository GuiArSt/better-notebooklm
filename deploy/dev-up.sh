#!/usr/bin/env bash
# Builds and (re)starts the dev environment on this Mac (D-025). Run it after every merge into the dev base:
#   deploy/dev-up.sh [--force "<reason>"] [--check-only] [git ref]   (ref default: the branch named in ~/application-test-dev/base-ref, else HEAD)
#
# THE GUARD (D-050, enforced in code): after the build and immediately BEFORE anything is stopped, this asks the RUNNING stack's database
# whether something is in flight, and refuses (exit 3) if there is: any row in `jobs` (waiting or reserved), any open ledger reservation, or
# any usage_events row in the last 2 minutes. Studio items and sources still pending or running are printed as a WARNING, never a refusal
# (a killed worker leaves some behind with no job; see orphans:sweep). A refusal is lifted only by  --force "<reason>"  (the reason is
# printed, and written with the time to $dev_home/force.log), or by waiting. One "clear" from the gate keeper covers ONE restart.
# --check-only runs the guard and nothing else (exit 0 clear, 3 refused). No running stack: nothing to check. A running stack whose database
# cannot be asked: refused (the check fails closed).
# CLEAN-UP (D-055): after a successful deploy it keeps the LAST THREE dev-* tags (newest first, app and media together), every image a container
# still uses, and never 087b607 or 0732205; it removes the other application-test/{app,media}:dev-* tags, then runs `docker builder prune -f`
# (the dev deploys of 18-19 Nov filled the Docker VM's disk). --no-prune skips it. Removing an image a container uses is refused by Docker itself.
# --select-prune reads image refs (newest first) from stdin, the refs in use from $DEV_UP_IN_USE (space separated), prints the refs it WOULD
# remove, and exits: no Docker, nothing deleted; the same function does the real selection.
# For tests only: DEV_UP_FAKE_INFLIGHT='jobs|holds|recent|item ids|source ids', for example '1|0|0|107 110|', replaces the database query. It is
# honoured ONLY together with --check-only (which never builds or restarts anything): with a real deploy the script stops at once, so the hook
# cannot let a restart through. When it is honoured, a line says the real stack was not asked.
#
# What it does: takes a clean `git archive` of the ref (no history, no untracked files, no secrets), builds the images natively for this
# machine, tags them dev-<short commit id>, starts the project `application-test-devenv`, runs the migrations, and waits for /up.
# The compose project is `application-test-devenv`, NOT `application-test-dev`: that name belongs to deploy/compose.dev.yaml (the Mac's local
# development database, with its own volume); sharing it would attach this stack to that database.
# The secrets are in files OUTSIDE the repository: ~/application-test-dev/.env (or DEV_ENV_FILE). This script passes it to docker
# compose with --env-file and never reads, prints or copies it.
#
# Optional, for a local test only: DEV_EXTRA_COMPOSE=<a compose file> adds one more -f (for example one that publishes a host port).
set -euo pipefail

dev_home="${DEV_HOME:-$HOME/application-test-dev}"

force_reason=""
check_only=0
no_prune=0
select_prune=0
ref_arg=""
while [ $# -gt 0 ]; do
  case "$1" in
    --force)
      if [ -z "${2:-}" ] || [[ "$2" == --* ]]; then
        echo '--force needs a reason in quotes: --force "why this restart cannot wait".' >&2
        exit 2
      fi
      force_reason="$2"
      shift 2
      ;;
    --check-only) check_only=1; shift ;;
    --no-prune) no_prune=1; shift ;;
    --select-prune) select_prune=1; shift ;;
    -*) echo "Unknown option $1. Usage: deploy/dev-up.sh [--force \"<reason>\"] [--check-only] [git ref]" >&2; exit 2 ;;
    *) ref_arg="$1"; shift ;;
  esac
done

# Which dev-* image refs to remove (D-055). stdin: refs "repo:tag", newest first. $DEV_UP_IN_USE: refs a container uses. Prints the refs to remove.
# Only application-test/app and application-test/media tags that start with dev- are ever candidates. Keep: the newest three distinct tags, any ref
# in use, and tags 087b607 and 0732205 (kept for good, whatever their prefix).
select_prune() {
  local keep_tags=3 seen="" n=0 ref repo tag
  while IFS= read -r ref; do
    [ -n "$ref" ] || continue
    repo="${ref%:*}"; tag="${ref##*:}"
    case "$repo" in application-test/app|application-test/media) ;; *) continue ;; esac
    case "$tag" in dev-*) ;; *) continue ;; esac
    case "$tag" in *087b607|*0732205) continue ;; esac
    case " $seen " in
      *" $tag "*) ;;
      *) seen="$seen $tag"; n=$((n + 1)) ;;
    esac
    [ "$n" -le "$keep_tags" ] && continue
    case " ${DEV_UP_IN_USE:-} " in *" $ref "*) continue ;; esac
    echo "$ref"
  done
}

if [ "$select_prune" = "1" ]; then
  select_prune
  exit 0
fi

# The test hook can never be part of a real deploy.
if [ -n "${DEV_UP_FAKE_INFLIGHT:-}" ] && [ "$check_only" != "1" ]; then
  echo "DEV_UP_FAKE_INFLIGHT is a test hook and is only allowed with --check-only. Unset it: a real deploy always asks the real stack." >&2
  exit 2
fi

# Which commit to deploy: the argument, else the branch named in $dev_home/base-ref (the dev base: one line, kept outside the repository),
# else the current HEAD.
if [ -n "$ref_arg" ]; then
  ref="$ref_arg"
elif [ -f "$dev_home/base-ref" ]; then
  ref="$(head -n 1 "$dev_home/base-ref")"
else
  ref="HEAD"
fi
env_file="${DEV_ENV_FILE:-$dev_home/.env}"
project="application-test-devenv"

if [ -z "$dev_home" ] || [ "$dev_home" = "/" ]; then
  echo "DEV_HOME must be a real folder." >&2
  exit 1
fi
# --- The guard (D-050) -------------------------------------------------------------------------------------------------------------------
# Prints what is in flight and returns 3 if the restart must be refused. Reads only; never changes anything.
inflight_guard() {
  local row=""
  if [ -n "${DEV_UP_FAKE_INFLIGHT:-}" ]; then
    echo "guard skipped: test hook (the counts below are fake; the real stack was NOT asked)" >&2
    row="$DEV_UP_FAKE_INFLIGHT"
  else
    local db="${project}-db-1"
    if [ "$(docker inspect -f '{{.State.Running}}' "$db" 2>/dev/null || true)" != "true" ]; then
      echo "No running dev database ($db): nothing in flight."
      return 0
    fi
    # UTC, because the app stores its timestamps in UTC.
    if ! row="$(docker exec "$db" psql -U app -d application_test -tA -c "SELECT (SELECT count(*) FROM jobs) ||'|'|| (SELECT count(*) FROM ledger_reservations) ||'|'|| (SELECT count(*) FROM usage_events WHERE created_at > (now() AT TIME ZONE 'UTC') - interval '2 minutes') ||'|'|| (SELECT coalesce(string_agg(id::text, ' '),'') FROM studio_items WHERE status IN ('pending','running')) ||'|'|| (SELECT coalesce(string_agg(id::text, ' '),'') FROM sources WHERE status='pending');" 2>/dev/null)" || [ -z "$row" ]; then
      echo "REFUSED: the running dev database could not be asked what is in flight, so it is not safe to restart. Use --force \"<reason>\" only if you know it is." >&2
      return 3
    fi
  fi

  local jobs holds recent items sources
  IFS='|' read -r jobs holds recent items sources <<<"$row"
  jobs="${jobs:-0}"; holds="${holds:-0}"; recent="${recent:-0}"

  if [ -n "${items// /}" ]; then
    echo "WARNING (not a refusal): studio items still pending or running: $items" >&2
  fi
  if [ -n "${sources// /}" ]; then
    echo "WARNING (not a refusal): sources still pending: $sources" >&2
  fi

  if [ "$jobs" != "0" ] || [ "$holds" != "0" ] || [ "$recent" != "0" ]; then
    {
      echo "REFUSED: something is in flight on dev. Restarting now would kill it."
      echo "  jobs in the queue (waiting or reserved): $jobs"
      echo "  open ledger reservations (a paid call started and not settled): $holds"
      echo "  ledger rows written in the last 2 minutes: $recent"
      echo 'Wait, or get the explicit clear for THIS restart, or use --force "<reason>".'
    } >&2
    return 3
  fi
  echo "Clear: no job, no open reservation, no ledger row in the last 2 minutes."
}

run_guard() {
  local rc=0
  inflight_guard || rc=$?
  if [ "$rc" != "0" ]; then
    if [ -n "$force_reason" ]; then
      echo "FORCED past the guard. Reason: $force_reason" >&2
      mkdir -p "$dev_home"
      printf '%s\t%s\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$ref" "$force_reason" >> "$dev_home/force.log"
      return 0
    fi
    return "$rc"
  fi
}

if [ "$check_only" = "1" ]; then
  run_guard || exit $?
  exit 0
fi

if [ ! -f "$env_file" ]; then
  echo "The secrets file $env_file does not exist yet. Write it from deploy/.env.example (fresh values, dev keys, a NEW tunnel's token), outside the repository." >&2
  exit 1
fi

# What the stack itself needs, made once and kept (a stable APP_KEY keeps the sessions valid): the owner's file does not have to hold it,
# and wins wherever it does. Random secrets generated here; nothing is read from the owner's file.
stack_env="$dev_home/stack.env"
if [ ! -f "$stack_env" ]; then
  umask 077
  db_password=$(openssl rand -hex 16)
  {
    echo "APP_NAME=\"Better NotebookLM\""
    echo "APP_KEY=base64:$(openssl rand -base64 32)"
    echo "APP_DEBUG=false"
    echo "RUN_MIGRATIONS=true"
    echo "DB_CONNECTION=pgsql"
    echo "DB_HOST=db"
    echo "DB_PORT=5432"
    echo "DB_DATABASE=application_test"
    echo "DB_USERNAME=app"
    echo "DB_PASSWORD=$db_password"
    echo "SESSION_DRIVER=database"
    echo "CACHE_STORE=database"
    echo "QUEUE_CONNECTION=database"
    echo "FILESYSTEM_DISK=local"
    echo "LOG_CHANNEL=stderr"
    echo "MAIL_MAILER=log"
  } > "$stack_env"
  echo "Made $stack_env (generated values for the stack itself)."
fi

repo=$(git rev-parse --show-toplevel)
sha=$(git -C "$repo" rev-parse --short "$ref")
tag="dev-$sha"

# A clean copy of the ref, kept in place so that `docker compose down`, `logs` and `exec` keep working between runs.
current="$dev_home/current"
mkdir -p "$dev_home"
rm -rf -- "$current"
mkdir -p "$current"
git -C "$repo" archive "$ref" | tar -x -C "$current"

cd "$current/deploy"
export IMAGE_TAG="$tag" DEV_ENV_FILE="$env_file" DEV_STACK_ENV_FILE="$stack_env"
compose=(docker compose -p "$project" --env-file "$stack_env" --env-file "$env_file" -f compose.yaml -f compose.demo.yaml -f compose.dev-stack.yaml)
if [ -n "${DEV_EXTRA_COMPOSE:-}" ]; then
  compose+=(-f "$DEV_EXTRA_COMPOSE")
fi

echo "Building $tag natively (no emulation) ..."
"${compose[@]}" build app worker-default

# Asked again here, after the build and just before anything is stopped: the build takes minutes, and what was true before it is not
# what is true now (a call started in the meantime was killed by a restart that had checked too early).
run_guard || exit $?

echo "Starting $project ..."
"${compose[@]}" up -d

echo "Waiting for /up ..."
ok=0
for _ in $(seq 1 90); do
  if "${compose[@]}" exec -T app curl -fsS -o /dev/null http://localhost/up 2>/dev/null; then ok=1; break; fi
  sleep 2
done
if [ "$ok" != 1 ]; then
  echo "The app did not answer /up within 3 minutes. Look at: docker compose -p $project logs app" >&2
  exit 1
fi

"${compose[@]}" exec -T app php artisan migrate --force

echo "Dev is up: images application-test/app:$tag and application-test/media:$tag, project $project."
if [ "$no_prune" != "1" ]; then
  # Never fails the deploy, which already succeeded: whatever Docker refuses (an image still in use) simply stays.
  in_use="$(docker ps -a --format '{{.Image}}' | tr '\n' ' ')"
  doomed="$(docker images --format '{{.Repository}}:{{.Tag}}' | DEV_UP_IN_USE="$in_use" bash "$0" --select-prune 2>/dev/null || true)"
  if [ -n "$doomed" ]; then
    echo "Removing old dev images (kept: the last three tags, anything a container uses, 087b607, 0732205):"
    # shellcheck disable=SC2086
    echo "$doomed" | xargs -n 1 docker rmi 2>&1 | sed 's/^/  /' || true
  fi
  docker builder prune -f 2>&1 | tail -1 || true
fi

echo "Test users (from $current/deploy, with the same variables): IMAGE_TAG=$tag DEV_ENV_FILE=$env_file DEV_STACK_ENV_FILE=$stack_env ${compose[*]} exec app php artisan dev:seed-testers"
