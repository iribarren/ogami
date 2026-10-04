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
| T1 | Docker Compose (FrankenPHP, Postgres, Node, Mailpit) + Makefile (`up`, `down`, `sh`, `test`, `qa`) | delegated writer A (multi-file, needs research) | [ ] | |
| T2 | Symfony skeleton: Doctrine, Messenger command/query buses, `src/{Play,Studio,Randomness,Identity,Admin,Shared}/{Domain,Application,Infrastructure}`, `GET /api/health` | writer A | [ ] | |
| T3 | Backend QA: PHPStan max + PHPat rules (ADR 0012), CS-Fixer, Rector, PHPUnit, Behat; walking skeleton: Randomness `DiceExpression` (`2d6+1`) with an injected random source, RED→GREEN | writer A | [ ] | |
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

## Next step
T1–T3 (writer A).
