# Ogami — conventions for agents and developers

Ogami is a web app for solo tabletop RPG play: **Play** (solo player runs campaigns with a guided narrative flow, sheets, checks and oracles), **Studio** (game managers author game systems) and **Admin** (owner manages the app). Symfony modular monolith + React SPA.

## Read first

| Topic | Doc |
|---|---|
| Product vision, roles, non-goals | [docs/vision.md](docs/vision.md) |
| Bounded contexts and relationships | [docs/domain/context-map.md](docs/domain/context-map.md) |
| Ubiquitous language | [docs/domain/glossary.md](docs/domain/glossary.md) |
| Architecture decisions | [docs/adr/README.md](docs/adr/README.md) |
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

## Directory layout (planned, Feature 2 `bootstrap-monorepo`)

```
backend/              Symfony app
  src/<Context>/      Play, Studio, Randomness, Identity, Admin, Shared
    Domain/
    Application/
    Infrastructure/
frontend/             Vite + React + TS
  src/{play,studio,admin,shared}
docker/               container config
compose.yaml          full dev environment
Makefile              common commands
docs/                 vision, domain, ADRs
odd/tasks/            ODD feature docs
```

## Workflow

| Practice | Rule |
|---|---|
| Method | ODD (gentle-ai): explore → feature doc in `odd/tasks/<feature>.md` (+ Engram mirror) → task by task ([ADR 0011](docs/adr/0011-gentle-ai-workflow.md)) |
| Tests | Test first (RED → GREEN → refactor) when a runnable deterministic test exists |
| Branches | One branch per feature; nothing lands on `main` directly |
| Commits | One work-unit commit per task, Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`…) |
| Delivery | Never push, open a PR or merge without the user |

## Commands

TBD in `bootstrap-monorepo` (planned Make targets: `up`, `down`, `test`, `qa`, `sh`).
