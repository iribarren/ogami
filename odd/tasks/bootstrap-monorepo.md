# Feature: bootstrap-monorepo

- **Locator:** `odd/tasks/bootstrap-monorepo.md` · Engram topic `odd/bootstrap-monorepo/tasks`
- **Issue:** #3 · **Branch:** `chore/bootstrap-monorepo` (branch point `081c2d6` on `main`)
- **Delivery strategy:** single PR + `size:exception` (user choice) · merge commit
- **RDD:** off during this feature; enable for the clone after it closes ([ADR 0011](../../docs/adr/0011-gentle-ai-workflow.md))
- **Previous feature:** `foundation-docs`, delivered in PR #2 (closes #1)

## Objective
A runnable monorepo skeleton, so every domain feature starts from working Docker, backend, frontend, quality tooling and CI.

## Scope
- In: Docker Compose + Makefile, Symfony skeleton with the DDD module layout, backend QA + PHPat, a dice-expression walking skeleton, the React frontend toolchain, OpenAPI client generation, a Playwright smoke test, GitHub Actions CI.
- Out: real domain features (Studio, Play, auth flows), production deployment.

## Constraints
- Follow `CLAUDE.md` and ADRs 0002–0009 and 0012.
- Symfony: latest stable. Frontend package manager: pnpm. All tooling runs in Docker (the local composer is old).
- Frontend libraries beyond the baseline (React Flow, dnd-kit, CodeMirror, Tiptap…) are NOT added now (ADR 0004).

## Forecast
About 1,500 authored changed lines, generated files excluded. Single PR with `size:exception`.

## Tasks
| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Docker Compose (FrankenPHP, Postgres, Node, Mailpit) + Makefile (`up`, `down`, `sh`, `test`, `qa`) | delegated writer A (multi-file, needs research) | [x] | de932b5 |
| T2 | Symfony skeleton: Doctrine, Messenger command/query buses, `src/{Play,Studio,Randomness,Identity,Admin,Shared}/{Domain,Application,Infrastructure}`, `GET /api/health` | writer A | [x] | c42f7b6 |
| T3 | Backend QA: PHPStan max + PHPat rules (ADR 0012), CS-Fixer, Rector, PHPUnit, Behat; walking skeleton: Randomness `DiceExpression` (`2d6+1`) with an injected random source, RED→GREEN | writer A | [x] | 3aa1048 |
| T4 | Frontend: Vite + React + TS (pnpm), Tailwind, shadcn/ui, TanStack Router/Query, ESLint/Prettier, Vitest, Storybook; `src/{play,studio,admin,shared}` | delegated writer B | [x] | 850fb35 |
| T5 | OpenAPI export (backend) + generated TS client; health shown in the SPA; Playwright smoke test | writer B | [x] | fd2f757 |
| T6 | GitHub Actions CI: backend QA + tests, frontend lint/test/build, Playwright | writer B | [x] | 444e835 |
| T7 | Docs: `CLAUDE.md` commands section, README quick start; `codegraph init` | writer B + parent | [x] | 1cdfecb (docs) · codegraph: local index, nothing to commit |

## Acceptance criteria
- `make up` starts every service; `make qa` and `make test` pass on a clean clone.
- A PHPat rule violation fails `make qa` (demonstrated once, then reverted).
- The Behat scenario for the dice expression passes; its unit tests were seen failing first.
- The SPA calls `/api/health` through the generated client; the Playwright smoke test passes.
- CI is green on the PR.

## Checks
`make qa`, `make test`, `make e2e` (or the equivalent targets), CI run on the PR.

## Progress / Evidence
- Issue #3 created, branch created.
- **T1** (`de932b5`): `make build && make up` → `database`, `mailpit`, `php` healthy, `node` running (`docker compose ps`). Versions observed in the containers: PHP 8.5.11 (FrankenPHP `1-php8.5`, Debian trixie) with intl, pdo_pgsql, opcache, zip, Xdebug (mode `off`); Composer 2.10.3; PostgreSQL 18.6 (`postgres:18-alpine`); Node 24.21.0 LTS with pnpm 12.9.1 via corepack; Mailpit 1.31. Containers run as the host UID/GID (`id` → `uid=1001(app)`). Host ports: app 8080, Postgres 5433, Mailpit UI 8026 (all overridable in a root `.env`).
  - Deviation: `.env.example` could not be written (agent permission rule on `.env*` files); the variables and defaults are documented in the `compose.yaml` header instead.
  - Pitfall found: `docker compose build` without exported `UID`/`GID` builds for UID 1000 and breaks the Caddy data volume permissions; always go through `make` (it exports both).
- **T2** (`c42f7b6`): Symfony 8.1 (`symfony/skeleton`) created in the php container; Doctrine ORM + migrations (`symfony/orm-pack`), Messenger, serializer installed. `bin/console debug:messenger` → `CheckHealth` handled by `CheckHealthHandler` on `query.bus`; buses `command.bus` (default, `doctrine_transaction`), `query.bus`, `event.bus` (`allow_no_handlers`), sync transport. `debug:router` → `api_health GET /api/health`. `curl localhost:8080/api/health` → `{"status":"ok","database":"ok"}`; with `database` stopped → `{"status":"ok","database":"down"}`.
  - Decisions: handlers implement framework-free marker interfaces (`CommandHandler`, `QueryHandler`, `EventHandler` in `Shared/Application/Bus`) that `services.yaml` tags for the right bus, so Application code needs no Symfony attribute. Health lives in **Shared**: it is a technical, cross-cutting probe with no Admin ubiquitous language (Admin = users, roles, settings). Doctrine maps each context with XML files under `<Context>/Infrastructure/Persistence/Doctrine/Mapping` (framework-free Domain). Recipe-generated `backend/AGENTS.md`, `backend/CLAUDE.md` and `backend/.editorconfig` removed: root `CLAUDE.md` and `.editorconfig` are the single source.
  - Deviation: `backend/.env` keeps the recipe's placeholder `DATABASE_URL` (agents may not edit `.env*` files); compose injects the real `DATABASE_URL` into the php container, which takes precedence.
- **T3** (`3aa1048`): PHPStan 2.2 (level max) + phpstan-symfony/doctrine/phpunit + PHPat 0.12 (`tests/Architecture/BoundedContextRulesTest.php`: layer rule per context, framework-free Domain, context isolation; shared kernel `Randomness`/`Shared` exempt), PHP-CS-Fixer 3.95 (`@PER-CS` + `@Symfony` + `@Symfony:risky`), Rector 2.6 (PHP 8.5, Symfony/Doctrine/PHPUnit composer-based sets, dead code, code quality, type declarations), PHPUnit 13.4 (`unit` + `integration` suites), Behat 4.0 (PHP config `behat.dist.php`, suite `randomness`, no Symfony extension needed for a domain-only suite).
  - RED (tests written first, before any Randomness class existed): `vendor/bin/phpunit --testsuite unit` → `ERRORS! Tests: 20, Assertions: 11, Errors: 9, Failures: 11` (`Class "App\Randomness\Domain\DiceExpression" not found`).
  - GREEN: `DiceExpression` (NdM±K, 1–100 dice, 2–1000 sides, `InvalidDiceExpression` otherwise), `Roll` (dice + total), `RandomNumberGenerator` port, `SecureRandomNumberGenerator` adapter (`random_int`) → `OK (20 tests, 2442 assertions)`. Refactor with Rector + CS-Fixer, tests stayed green.
  - `make test` → PHPUnit `OK (21 tests, 2447 assertions)` (incl. `/api/health` integration test against `ogami_test`), Behat `4 scenarios (4 passed), 14 steps (14 passed)` for `features/randomness/dice_expression.feature`.
  - `make qa` → PHPStan `[OK] No errors`, CS-Fixer `Found 0 of 35 files that can be fixed`, Rector `[OK] Rector is done!` (exit 0).
  - PHPat proof (not committed): `src/Play/Domain/PhpatViolationDemo.php` using `Symfony\Component\HttpFoundation\Request` → `make qa` exit 2 with `testDomainDependsOnlyOnDomainPlay: App\Play\Domain\PhpatViolationDemo should not depend on Symfony\Component\HttpFoundation\Request` and `testDomainIsFrameworkFree: …` (`Found 2 errors`). A `Play\Application` class using a `Studio\Domain` class → `testContextsAreIsolatedPlay` and `testApplicationDependsOnlyOnDomainAndApplicationPlay` errors. Both removed; `make qa` green again.
  - Follow-up (`e7126e1`): `make qa`/`make test` depend on `backend-install`, which runs `composer install` when `backend/vendor/autoload.php` is missing or older than `composer.lock`, so a clean clone needs only `make build up qa test`.
  - Notes: `symfony/translation` arrives only as a Behat dev dependency, so its recipe config was dropped (a `--no-dev` install would break on it). Composer `php` constraint raised to `>=8.5` to match the image.
- **T4** (`850fb35`): Vite 8.3, React 19.3, TypeScript 6.0 (strict + `noUncheckedIndexedAccess`), Tailwind CSS 4.3 (`@tailwindcss/vite`), shadcn/ui 4.21 (`radix-nova` preset; Button, Card), TanStack Router 1.170 (file-based) + Query 5.104 (+ devtools), ESLint 10 (flat config, `typescript-eslint` 8.71 `strictTypeChecked`, `react-hooks` 7, `react-refresh`, `eslint-config-prettier`), Prettier 3.9 (+ Tailwind class sorting), Vitest 5.0 + Testing Library + jsdom, Storybook 10.6 (`@storybook/react-vite`, one Button story). pnpm 12.9 via corepack in the node container.
  - Layout: `src/routes/` (thin file routes) → `src/app/` (router, query client, `AppShell`, landing page) and `src/{play,studio,admin}/` (area pages), `src/shared/{ui,lib}` (shadcn components, `cn`). Generated `src/routeTree.gen.ts` is committed so `tsc` works without a Vite run.
  - Router choice: **file-based** (`@tanstack/router-plugin`). The generated route tree gives typed links and params with no hand-kept registry, and automatic per-route code splitting; with three product areas that will grow many routes, this scales better than a code-based tree.
  - Wiring: node service mounts `./frontend`, runs `pnpm install --frozen-lockfile && pnpm dev`, has a healthcheck (`make up` waits for Vite). `docker/php/frontend.d/vite.caddyfile` proxies every non-API request to `node:5173`. Storybook port 6006 published in `compose.override.yaml`.
  - Makefile: `pnpm`, `frontend-install`, `frontend-qa` (eslint, prettier --check, `tsc -b`), `frontend-fix`, `frontend-test`, `frontend-build`, `storybook`, `storybook-build`; `frontend-qa` → `qa`, `frontend-test` → `test`.
  - RED (test written first, before router/shell existed): `pnpm test` → `Failed to resolve import "./router" from "src/app/AppShell.test.tsx"`. GREEN: `AppShell` nav + routes → `Tests 2 passed (2)`.
  - Checks: `curl localhost:8080/` → Vite-served SPA HTML; `curl localhost:8080/api/health` → `{"status":"ok","database":"ok"}`; HMR through Caddy: a WebSocket client from the node container to `ws://php/?token=…` (protocol `vite-hmr`) received `{"type":"connected"}`. `make qa` exit 0 (backend + ESLint + Prettier + tsc), `make test` → PHPUnit `OK (21 tests)`, Behat 4/4, Vitest 2/2. `pnpm build` and `pnpm build-storybook` succeed.
  - Deviations: the Vite template now ships oxlint; replaced by ESLint as the task asks. TypeScript 7.0 is out but `typescript-eslint` supports `<6.1`, so TS is pinned `~6.0` (same as the template). No `server.hmr.clientPort`: Vite's client connects back to the page origin, so HMR works both at `localhost:8080` and at `http://php` (Playwright); a fixed `8080` would break the latter. `allowedHosts: ['php']` lets the in-network origin through. shadcn CLI 4.21 rewrote the `cn` import to an unrelated npm package `cn` and installed it; removed it and restored the standard `clsx` + `tailwind-merge` helper. `pnpm-workspace.yaml` allows only `esbuild`'s build script (pnpm blocks dependency build scripts by default).
  - Pitfall: `docker/php/frontend.d/*.caddyfile` is read when Caddy starts; after adding a snippet, restart php (`docker compose restart php`).
- **T5** (`fd2f757`): NelmioApiDocBundle 5.13 (swagger-php 6.11). Chosen because it is the maintained, Symfony-native generator that reads route + OpenAPI attributes and supports Symfony 8 (`^6.4 || ^7.2 || ^8.0`); API Platform would replace our own controllers/CQRS style. Its contrib recipe is ignored by Flex, so the bundle, `config/packages/nelmio_api_doc.yaml` and the `/api/doc.json` route were added by hand. `HealthController` carries the OpenAPI attributes; the JSON body moved to an Infrastructure DTO `HealthResponse` (`#[OA\Schema]`, enums `ok` / `ok|down`), so Domain/Application stay attribute-free. The spec excludes `/api/doc.json` itself.
  - Frontend: openapi-typescript 7.13 → `src/shared/api/schema.d.ts`, openapi-fetch 0.17 client (`createApiClient`, same-origin base URL), `ApiClientProvider`/`useApiClient` so tests inject a client with a fake `fetch`, `useHealth` (TanStack Query, 30 s polling), `HealthStatus` card on the landing page.
  - Make: `api-spec` (`nelmio:apidoc:dump` → `frontend/src/shared/api/openapi.json`), `api-client`, `api` (both), `api-check` (in `qa`): diffs a fresh dump and fresh types against the committed files. Proof: editing the controller summary → `make api-check` exit 2 with `openapi.json is stale: run 'make api' …`; appending a line to `schema.d.ts` → exit 2 with `… run 'make api-client' …`; reverted → `API spec and types are up to date.`
  - Playwright 1.63.0: `frontend/e2e/smoke.spec.ts`, `playwright` compose service (profile `e2e`, image `mcr.microsoft.com/playwright:v1.63.0-noble`, host UID/GID, `ipc: host`) against `http://php`; `make e2e`. Result: `1 passed`.
  - RED (tests first): backend `ApiDocumentationTest` → `No route found for "GET http://localhost/api/doc.json"`; frontend `HealthStatus.test.tsx` → `Failed to resolve import "./HealthStatus"`. GREEN: integration suite `OK (2 tests, 58 assertions)`; Vitest `Tests 5 passed (5)`.
  - Checks: `make qa` exit 0 (incl. `api-check`), `make test` → PHPUnit `OK (22 tests, 2500 assertions)`, Behat 4/4, Vitest 5/5; `make e2e` → 1 passed.
  - Notes: Rector needed two `backend-fix` passes (its `LocallyCalledStaticMethodToNonStaticRector` fires only after a first change). `openapi-typescript` declares a `typescript ^5` peer; it works with TS 6.0 (peer warning only). `@playwright/test` is pinned exactly so it matches the image tag; bump both together. Symfony regenerated `config/reference.php` for the new bundle (committed).
- **T6** (`444e835`): `.github/workflows/ci.yml` on `pull_request` and `push` to `main`, `concurrency` with `cancel-in-progress`, `permissions: contents: read`. Three parallel jobs, all through the Makefile + compose (parity with local; `make` exports the runner's UID/GID): **backend** (`make build up SERVICES=php`, `backend-qa`, `backend-test` incl. Behat and integration on PostgreSQL), **frontend** (`SERVICES=node`, `frontend-qa`, `frontend-test`, `frontend-build storybook-build`), **e2e** (full stack, `api-check`, `make e2e`, uploads `playwright-report/` + `test-results/` on failure). `DOCKER_COMPOSE=docker compose -f compose.yaml` skips the dev override. `actions/cache` for `backend/vendor` and `frontend/node_modules` (the pnpm store lives inside it). Service logs are dumped on failure.
  - New Makefile variable `SERVICES` limits `build`/`up`.
  - Image layers are not cached: compose-built images would need a buildx bake + GHA cache setup whose image naming cannot be validated locally; left as a follow-up if CI time hurts.
  - `actionlint` 1.7.12 (`docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest -no-color -verbose`) → `Found total 0 errors` (shellcheck included).
  - Local CI simulation in an isolated project (`DOCKER_COMPOSE="docker compose -p ogami-ci -f compose.yaml" HTTP_PORT=18080 CI=true`): `make build up`, `backend-qa`, `backend-test` (PHPUnit `OK (22 tests)`, Behat 4/4), `frontend-qa frontend-test` (Vitest 5/5), `api-check`, `e2e` (1 passed) → exit 0; project removed afterwards (`down -v`). The real run happens on the PR.
- **T7** (`1cdfecb`): `CLAUDE.md` "Commands" (make targets by task, URLs/ports) and the real directory layout; README "Quick start" (Docker + make, `make build up`, http://localhost:8080, `make qa test e2e`) and a ports/environment table (stands in for the `.env.example` agents cannot write). `make up` now also runs `backend-install` when php is running, so a fresh clone needs only `make build up` (the node service installs pnpm deps itself). `codegraph init` is left to the parent.
- **Final verification (writer B, after `1cdfecb`):** `make build up` exit 0, all four services healthy; `curl localhost:8080/` → Vite SPA HTML; `curl localhost:8080/api/health` → `{"status":"ok","database":"ok"}`; `make qa` exit 0 (PHPStan, CS-Fixer 0/37, Rector, ESLint, Prettier, tsc, `api-check`); `make test` exit 0 (PHPUnit `OK (22 tests, 2500 assertions)`, Behat 4 scenarios / 14 steps, Vitest 5/5); `make e2e` → 1 passed; `make frontend-build` and `make storybook-build` exit 0; actionlint 0 errors.
- Engram mirror: not updated by writer B (parent owns it).

- **Parent (T7 codegraph + spot check):** `gentle-ai codegraph init` → index 94 files / 437 nodes (`.codegraph/` gitignored). Spot checks: `make e2e` → 1 passed; `make qa` → ok.
- Size: ~4,650 authored changed lines (lock files and generated `routeTree.gen.ts`, `schema.d.ts`, `openapi.json` excluded), well above the 1,500 forecast, mostly Symfony recipe config and frontend tool config. Single PR + `size:exception` as chosen.

## Next step
Push and open the PR (closes #3) after user confirmation; CI must be green; then enable RDD for the clone.
