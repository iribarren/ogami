# Ogami — conventions for agents and developers

Ogami is a web app for solo tabletop RPG play: **Play** (solo player runs campaigns with a guided narrative flow, sheets, checks and oracles), **Studio** (game managers author game systems) and **Admin** (owner manages the app). Symfony modular monolith + React SPA.

## Read first

| Topic | Doc |
|---|---|
| Product vision, roles, non-goals | [docs/vision.md](docs/vision.md) |
| Bounded contexts and relationships | [docs/domain/context-map.md](docs/domain/context-map.md) |
| Ubiquitous language | [docs/domain/glossary.md](docs/domain/glossary.md) |
| Architecture decisions | [docs/adr/README.md](docs/adr/README.md) |
| Roadmap, milestones and feature prompts | [docs/roadmap.md](docs/roadmap.md) |
| Current feature work | [odd/tasks/](odd/tasks/) |

## Architecture rules

| Rule | Detail |
|---|---|
| Dependency rule | Domain ← Application ← Infrastructure. Inner layers never import outer ones |
| Framework-free Domain | No Symfony, Doctrine or HTTP types in `Domain/` |
| Context boundaries | Contexts talk only via application services, published contracts or domain events. Never import another context's `Domain/` or `Infrastructure/` |
| Randomness | Pure shared kernel: no framework, no I/O, randomness source injected |
| Studio → Play | Play reads only published GameSystem releases through its anti-corruption layer, never Studio drafts ([ADR 0010](docs/adr/0010-versioned-gamesystem-releases.md)) |
| CQRS | Commands and queries go through Messenger buses; domain events in-process; no event sourcing ([ADR 0002](docs/adr/0002-modular-monolith-hexagonal-ddd.md)) |
| API | REST + OpenAPI; frontend uses only the generated typed client ([ADR 0005](docs/adr/0005-rest-openapi-typed-client.md)) |
| Enforcement | Dependency rule, framework-free Domain and context boundaries are checked by PHPat rules in `backend/tests/Architecture/`, run with PHPStan ([ADR 0012](docs/adr/0012-phpat-boundary-enforcement.md)) |
| Frontend libraries | Add a library only when the feature that needs it starts ([ADR 0004](docs/adr/0004-react-vite-spa-ui-toolkit.md)) |

## Naming

- Use the [glossary](docs/domain/glossary.md) terms in code, Gherkin, docs and UI: `GameSystem`, `GameSystem release`, `Check`, `FlowRun`, `Oracle`…
- It is **Studio**, never "backoffice". Studio is a first-class product surface, not an admin panel.
- Roles: `SOLO_PLAYER`, `GAME_MANAGER`, `OWNER`.
- Technical artifacts (code, comments, docs, commits) are in English.

## Directory layout

```
backend/                     Symfony app (PHP 8.5, Symfony 8.1)
  src/<Context>/             Play, Studio, Randomness, Identity, Admin, Shared
    Domain/                  framework-free model
    Application/             commands, queries, handlers, ports
    Infrastructure/          Doctrine, HTTP controllers (Http/), adapters
  tests/                     Unit/, Integration/, Architecture/ (PHPat), Behat/
  features/                  Gherkin specs (Behat)
frontend/                    Vite + React + TypeScript SPA
  src/routes/                TanStack Router file routes (thin; generate routeTree.gen.ts)
  src/app/                   router, query client, app shell, landing page
  src/{play,studio,admin}/   product areas
  src/shared/                ui/ (shadcn), api/ (generated client), lib/, health/
  e2e/                       Playwright tests
docker/                      container config (php: FrankenPHP + Caddy, node)
compose.yaml                 full dev environment (+ compose.override.yaml locally)
Makefile                     every command, see below
.github/workflows/ci.yml     CI: the same make targets
docs/                        vision, domain, ADRs
odd/tasks/                   ODD feature docs
```

## Workflow

| Practice | Rule |
|---|---|
| Method | ODD (gentle-ai): explore → feature doc in `odd/tasks/<feature>.md` (+ Engram mirror) → task by task ([ADR 0011](docs/adr/0011-gentle-ai-workflow.md)) |
| Tests | Test first (RED → GREEN → refactor) when a runnable deterministic test exists |
| Branches | One branch per feature, named `type/description` (`feat/`, `fix/`, `docs/`, `chore/`…); nothing lands on `main` directly |
| Issues and PRs | One GitHub issue per feature; one PR per feature with `Closes #N` and one type label. Above ~400 changed lines, split per the `ask-on-risk` strategy; passive docs may take a size exception |
| Merging | Merge commit only (no squash, no rebase), so the commit hashes in feature docs stay valid |
| Commits | One work-unit commit per task, Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`…) |
| Delivery | Never push, open a PR or merge without the user |

## Commands

Everything runs in Docker through `make` (it exports your UID/GID; a bare `docker compose build` breaks file ownership). `make help` lists every target.

| When | Command | Does |
|---|---|---|
| First run | `make build up` | Build images, start every service, install dependencies |
| Daily | `make up` / `make down` | Start / stop the stack |
| Daily | `make logs ARGS=php`, `make ps` | Follow logs, show status |
| Shells | `make sh`, `make node-sh` | Shell in the php / node container |
| Tools | `make composer ARGS=…`, `make console ARGS=…`, `make pnpm ARGS=…` | Composer, Symfony console, pnpm |
| QA | `make qa` | Backend (PHPStan + PHPat, CS-Fixer, Rector) + frontend (ESLint, Prettier, tsc) + `api-check` |
| Fix style | `make backend-fix`, `make frontend-fix` | Apply Rector/CS-Fixer, ESLint/Prettier (Rector may need two passes) |
| Tests | `make test` | PHPUnit (unit + integration on `ogami_test`), Behat, Vitest |
| E2E | `make e2e` | Playwright smoke tests in the `playwright` container against the running stack |
| Presets | `make presets` | Publish the GameSystem presets in `backend/presets/` as releases (skips unchanged ones) |
| API contract | `make api` | After changing an endpoint: export the OpenAPI spec and regenerate `frontend/src/shared/api/schema.d.ts`; commit both |
| Frontend builds | `make frontend-build`, `make storybook`, `make storybook-build` | Production SPA build; Storybook dev server / static build |

| URL | What |
|---|---|
| http://localhost:8080 | SPA (Vite, HMR) and API under `/api` — one origin, served by Caddy |
| http://localhost:8080/api/health | Health check |
| http://localhost:8080/api/doc.json | OpenAPI spec |
| http://localhost:8026 | Mailpit (captured email) |
| http://localhost:6006 | Storybook (while `make storybook` runs) |
| localhost:5433 | PostgreSQL (`ogami` / `ogami`) |

Ports and credentials are overridable; see the README.
