# Feature: studio-atomic-write-read

- **Locator:** `odd/tasks/studio-atomic-write-read.md` · Engram topic `odd/studio-atomic-write-read/tasks`
- **Issue:** #195 · **Branch:** `fix/studio-atomic-write-read-1-transactions` · **Status:** slice 1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-release-upgrade` (PRs #208–#210)

## Objective

Every Studio endpoint that writes and then reads the result back for its response runs the command and the read in one DBAL transaction, as `LibraryImportController::writeThenRead` already does (fixed in `studio-library` slice 5, #190). A read that fails rolls the write back, and the response never carries the version of a save that interleaved.

Also: the regression API test noted in the `studio-library` slice 4 review (#189), a GameSystem row stored before the `kind` column reads as `game-system`.

Out of scope:

- a malformed library pack row making its catalog answer 500 (fail closed is intended; only hand-edited rows can be malformed);
- Play or other contexts' controllers;
- endpoints that write without a read-back (`DELETE /api/studio/game-systems/{key}`, 204);
- API contract changes (none expected: no `make api` diff).

## Problem and why

Most Studio write endpoints dispatch the command (its own `doctrine_transaction`) and then ask a separate query, in two transactions:

- **Leaked version.** A save landing between the write and the read puts its version into the response. The client sends it as its next `expectedVersion`, so the optimistic lock no longer catches the interleaved change and the user silently overwrites it.
- **Failure after a committed write.** If the read-back fails, the response is a 404/409/500 although the change was saved.

Likelihood is low (drafts are owner-only: same user, two tabs), but the fix is mechanical and already proven in `LibraryImportController`.

## Model summary (today)

- Reference: `backend/src/Studio/Infrastructure/Http/LibraryImportController.php` `writeThenRead()` wraps `commandBus->dispatch` and the read in `Connection::transactional`; the command bus's `doctrine_transaction` middleware nests inside (savepoint); a refusal rolls both back; `refusal()` maps domain errors after the transaction.
- Reference test: `backend/tests/Integration/Studio/Http/LibraryImportApiTest.php::aReadThatFailsAfterAWriteRollsTheWriteBack` — a `postFlush` listener corrupts the stored row and clears the entity manager, the request throws (`catchExceptions(false)`), the listener fired, the draft is unchanged.
- Write-then-read sites (all in `backend/src/Studio/Infrastructure/Http/`):

| Controller | Write → read |
|---|---|
| `GameSystemCheckController` | `write()` helper (all check writes) → `GetGameSystemChecks` |
| `GameSystemSheetController` | `write()` helper (fields, move, layout save/declare/remove) → `GetGameSystemSheet` |
| `GameSystemNarrativeController` | `SaveNarrativeItem`, `DeleteNarrativeItem` → `GetGameSystemNarrative` |
| `GameSystemOracleController` | `SaveOracleTable`, `DeleteOracleTable`, `SaveLikelihoodOracle`, `DeleteLikelihoodOracle` → `GetGameSystemOracles` |
| `GameSystemController` | `CreateGameSystem`, `EditGameSystemMetadata` → `GetGameSystem` |
| `GameSystemReleaseController` | `PublishGameSystemDraft` → `GetPublishedReleaseById` + `GetGameSystemRelease`; `RestoreGameSystemRelease` → `GetGameSystem` |
| `LibraryPackController` | `CreateLibraryPack` → `GetGameSystem` |

- `kind` column: `backend/migrations/Version20261018100000.php` adds `kind VARCHAR(32) DEFAULT 'game-system' NOT NULL` to `studio_gamesystem`.

## Decisions

| Question | Decision |
|---|---|
| Pattern | Same as `LibraryImportController`: inject `Doctrine\DBAL\Connection`, one private helper per controller running dispatch + read inside `$this->connection->transactional(...)`; domain refusals caught outside the closure and mapped exactly as today (status codes and messages unchanged) |
| Response contract | Unchanged: no OpenAPI or TypeScript diff |
| Test per controller | One API test per controller in the style of `aReadThatFailsAfterAWriteRollsTheWriteBack`, on one representative write; for create endpoints "unchanged" means no GameSystem was created; for publish, no release was stored and the draft is unchanged |
| `kind` regression | API test inserts a `studio_gamesystem` row through SQL without the `kind` column (the migration default applies, as for rows stored before it) and reads `kind: game-system` from the GameSystem endpoint |

## Constraints

- Framework-free Domain; the change stays in `Infrastructure/Http`.
- Error mapping and status codes per endpoint stay as today; existing API tests stay green untouched.
- Planning heuristic only: the forecast below is not a cap.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `fix/studio-atomic-write-read-1-transactions` | T1–T3 | ~500–700 changed lines (7 controllers ~150, 7 rollback tests ~400, `kind` test ~40, this doc ~120), no generated files |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Editor controllers: check, sheet, narrative, oracle write + read in one transaction; one rollback API test each | delegated writer (slice 1; feature doc inline by parent) | [x] | |
| T2 | 1 | GameSystem, release (publish, restore) and library pack controllers: write + read in one transaction; one rollback API test each | delegated writer (slice 1) | [ ] | |
| T3 | 1 | API test: a GameSystem row stored before the `kind` column reads as `game-system` | delegated writer (slice 1) | [ ] | |

## Progress

- 2026-10-19: feature doc created (parent); 7 controllers mapped; one slice.
- 2026-10-19: T1 done: check, sheet, narrative and oracle writes run with their read-back in one transaction; four rollback API tests (RED: the write persisted and left the draft unreadable; GREEN after the change). The unreachable `CHANGED_AFTER_SAVE` branch of check and sheet is gone: the draft row is locked until the commit, so the read-back cannot miss it.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer implements T1–T3 on `fix/studio-atomic-write-read-1-transactions`.
