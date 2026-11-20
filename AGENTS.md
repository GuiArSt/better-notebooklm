# AGENTS.md

Facts every agent needs to build and run this project.

## Project

- What it is: a NotebookLM clone ("Better NotebookLM"): sources, grounded chat with citations, notes, audio overviews; a job application test
- Layout: `src/`: the Laravel 13 + Inertia/React app · `deploy/`: Dockerfile and compose files · `docs/`: decisions, plans, research
- Boundary: only the Laravel backend touches data, keys and files; the browser never sees a key. Every model call goes through `src/app/Ai/Router`

## Commands

| Task | Command |
|---|---|
| Install | `docker compose -f deploy/compose.dev.yaml up -d db && cd src && composer install && npm ci && cp -n .env.example .env && php artisan key:generate && php artisan migrate` |
| Run | `cd src && composer run dev` (server, queue and Vite together) |
| Test | `cd src && npm run build && php artisan test` (needs the pgvector `db` container on host port 5433; the build is needed by the page tests) |
| Lint / format | `cd src && vendor/bin/pint --test && npm run check && npm run types:check` |

## Exceptions the code does not make obvious

- No real AI call in tests: `laravel/ai` fakes only. Every model call goes through `App\Ai\Router` (cap, ledger, trace).
- The embedding dimension is fixed in `config/embeddings.php`; changing it means re-embedding everything.
- A model id or a key never goes in code: `config/*.php` and the untracked `.env` only.
- Tests run against the database `phpunit.xml` names; use your own copy of it to keep from wiping another test run.
- Page tests fail with "Not a valid Inertia response" when `public/build` is missing: run `npm run build` first.
- The app runs behind Cloudflare and Caddy: test anything about URLs, IPs or cookies with `APP_URL=https://…` and trusted proxies.
- Sentry (D-043) is on only when `SENTRY_LARAVEL_DSN` is set; empty means off, and tests run without it. Every event passes `App\Support\Sentry\Scrubber` (PHP) or `resources/js/sentry/init.ts` (browser): error class, file and line, path without query, user id. Never text, emails, bodies or breadcrumbs: do not add any to an event.

## Where to look

| For | Read |
|---|---|
