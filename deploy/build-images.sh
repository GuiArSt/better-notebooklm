#!/usr/bin/env bash
# Builds the two images for the box (linux/amd64) from a CLEAN copy of the commit HEAD, and tags them with that commit.
# Run on a developer machine, never on the box:   deploy/build-images.sh
#
# Why a copy: an image tagged with a commit must contain exactly that commit. A build from the working tree could include
# uncommitted edits, local files or a stray upload and still carry the commit's name. So the script refuses to run when a
# tracked file has changes, and builds from `git archive HEAD` (no history, no untracked or ignored files, no .env).
set -euo pipefail

repo=$(git rev-parse --show-toplevel)
cd "$repo"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Refusing to build: tracked files have uncommitted changes, so the image would not be the commit it is tagged with." >&2
  git status --short --untracked-files=no >&2
  exit 1
fi

tag=$(git rev-parse --short HEAD)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

git archive HEAD | tar -x -C "$work"

# Compose wants the .env file to exist and some variables to have a value. The template holds names only and the value below
# is a placeholder the build never uses, so the build reads no secret.
cp "$work/deploy/.env.example" "$work/deploy/.env"
printf 'POSTGRES_PASSWORD=build-only-placeholder\n' >> "$work/deploy/.env"

cd "$work/deploy"
IMAGE_TAG="$tag" docker compose -f compose.yaml -f compose.build.yaml build app worker-media

echo "Built application-test/app:$tag and application-test/media:$tag from a clean copy of commit $tag."
echo "Next: docker save application-test/app:$tag application-test/media:$tag | gzip > images-$tag.tar.gz"
