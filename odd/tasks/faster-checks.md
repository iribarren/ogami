# Feature: faster-checks

- **Locator:** `odd/tasks/faster-checks.md` · Engram topic `odd/faster-checks/tasks`
- **Issue:** opened with the PR · **Branch:** `chore/faster-checks-1-scoped-verify` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-pbta` (PRs #235–#236)

## Objective

Cut the time a slice spends on local checks. A full `make qa` + `make test` takes about 5.5 minutes and runs at least twice per slice (writer, then CI), plus reruns after flaky failures. Local checks should cover what the slice touched; CI stays the full gate.

Out of scope: rewriting the Studio editor Vitest tests to type faster (keystroke-by-keystroke `user.type`); auditing overlap between handler unit tests, Behat and API tests; CI changes.

## Problem and why

Measured 2026-10-21 on a warm stack, each suite on its own:

| Suite | Size | Wall time |
|---|---|---|
| PHPUnit unit | ~1,570 tests | 7s |
| PHPUnit integration | 1,880 cases | 99s, of which `PlayRequestSchemasTest` 23s for 18 cases |
| Behat | 165 scenarios | 1s |
| Vitest | 2,089 tests, 105 files | 126s; 6 load-related `findByRole` timeouts |
| ESLint | type-aware, no cache | 78s |
| tsc | — | 15s |
| PHPStan | cached | 1s |

- `PlayRequestSchemasTest` dumps the whole OpenAPI spec through the kernel once per data-provider case (~1.3s each).
- `findBy*` waits 1s by default (Testing Library `asyncUtilTimeout`); under the full parallel run some Play and Studio tests take longer and fail, then pass alone. Each flake costs a rerun.
- ESLint re-lints every file on every run.
- Writers run both sides in full even for a backend-only or frontend-only slice.

## Decisions

| Question | Decision |
|---|---|
| Who runs the full suites | CI, on every PR (unchanged). Locally the writer runs `make verify`, which picks the checks from the files changed since `main` |
| `make verify` backend scope | Any backend change: `backend-qa`, Behat, `api-check`. PHPUnit by context: Play → Play; Studio → Studio + Play (Play reads Studio releases); Admin → Admin; anything else in `backend/` (Shared, Identity, Formula, Randomness, config, migrations, presets, composer) → full PHPUnit |
| `make verify` frontend scope | Any frontend change: `frontend-qa`. Only `frontend/src/**` changed: `vitest related` on those files. Other frontend files (config, `package.json`, lockfile, setup) → full Vitest. `frontend/e2e/**` only: no Vitest (CI runs Playwright) |
| `api-check` | When `backend/` or `frontend/src/shared/api/` changed |
| Git in containers | The node container has no git and mounts only `frontend/`, so the host script computes the changed files and passes them to the containers |
| Failures | `make verify` runs every selected step, prints a summary and exits non-zero if any failed (no rerun to discover the next failure) |
| Flakes | `asyncUtilTimeout` 5s in `src/test/setup.ts`; Vitest `testTimeout` 20s so a 5s wait cannot hit the 5s default |
| ESLint cache | `--cache --cache-strategy content --cache-location .eslintcache` in `frontend/`, gitignored. Outside `node_modules`, so the CI `node_modules` cache never carries it and CI always lints fresh (type-aware results of unchanged files can go stale when an imported type changes) |
| Schema test | Fetch `/api/doc.json` once per class (static), keep it an HTTP test of the real spec |

## Constraints

- CI and the aggregate targets (`make qa`, `make test`) keep running everything; `make backend-test` without arguments stays the full backend run.
- Safe on `main` alone: tooling and tests only, no production code.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `chore/faster-checks-1-scoped-verify` | `chore(dev): faster-checks 1/1 scoped verification` | T1–T6 | ~300 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; `CLAUDE.md` (commands table: `make verify`; directory layout: `scripts/`; workflow: CI is the full gate); README command list | delegated writer (slice 1) | [x] | — |
| T2 | 1 | `make verify [BASE=main]` → `scripts/verify-changed.sh` (scope rules above, `--dry-run`, `VERIFY_FILES` override for testing, summary, exit code); `backend-test` takes `PHPUNIT_ARGS`; `frontend-test-related FILES=…` | delegated writer (slice 1) | [x] | — |
| T3 | 1 | Vitest flakes: `configure({ asyncUtilTimeout: 5000 })` in `src/test/setup.ts`, `testTimeout: 20000` | delegated writer (slice 1) | [x] | — |
| T4 | 1 | ESLint cache in `lint` / `lint:fix`; `.eslintcache` gitignored | delegated writer (slice 1) | [x] | — |
| T5 | 1 | `PlayRequestSchemasTest` fetches the spec once per class | delegated writer (slice 1) | [x] | — |
| T6 | 1 | `ogami-slice` skill (outside the repo): writer verification is `make verify`; parent watches CI after opening the PR | parent | [ ] | — |

## Progress

- 2026-10-21: measured every suite (table above); user approved quick wins 1–4 (scoped checks, flakes, ESLint cache, schema test).
- 2026-10-21 T5: exception to RED (behaviour unchanged); `PlayRequestSchemasTest` 18/18 pass, 23s before, 1.4s after.
- 2026-10-21 T3: exception to RED (flake, no deterministic test); two full Vitest runs 2089/2089 pass (110s and 131s), 0 timeouts (6 before).
- 2026-10-21 T4: exception to RED (tooling); warm `pnpm lint` 1.9s (78s cold), `.eslintcache` ignored by git and Prettier, `format:check` passes.
- 2026-10-21 T2: no shell test runner (exception to RED); `DRY_RUN=1` plans checked for docs-only, Play, Studio, Shared, frontend src, `package.json`, e2e-only, `schema.d.ts`, `Makefile`; shellcheck clean; PHPUnit 13.4 accepts several directory arguments; real `make verify` (Makefile in diff, so full scope) passed in 4m04 (qa 3s/24s, backend-test 87s, api-check 2s, Vitest 128s).
- 2026-10-21 T1: `CLAUDE.md` (commands, layout, workflow) and `README.md` document `make verify`; `make qa` and `make test` pass (`make test` 3m49).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer builds T1–T5.
