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
| T4 | Frontend: Vite + React + TS (pnpm), Tailwind, shadcn/ui, TanStack Router/Query, ESLint/Prettier, Vitest, Storybook; `src/{play,studio,admin,shared}` | delegated writer B | [ ] | |
| T5 | OpenAPI export (backend) + generated TS client; health shown in the SPA; Playwright smoke test | writer B | [ ] | |
| T6 | GitHub Actions CI: backend QA + tests, frontend lint/test/build, Playwright | writer B | [ ] | |
| T7 | Docs: `CLAUDE.md` commands section, README quick start; `codegraph init` | writer B + parent | [ ] | |

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

## Next step
T4–T7 (writer B).
