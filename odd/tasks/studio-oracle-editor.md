# Feature: studio-oracle-editor

- **Locator:** `odd/tasks/studio-oracle-editor.md` · Engram topic `odd/studio-oracle-editor/tasks`
- **Issue:** #141 · **Branch:** `feat/studio-oracle-editor-1-domain` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-gamesystem-drafts` (PRs #132–#139)

## Objective

Roadmap feature 15 (milestone M4). In a GameSystem draft, a GAME_MANAGER creates, edits and deletes oracle tables and likelihood oracles with a rich editor, and test-rolls them live through Randomness before publishing.

Out of scope: editing entry `sceneType`, entry `effects` and a likelihood oracle's `chaos.tracker` (kept as they are, shown read-only; their editors come with the flow and sheet features), the library (18b), reordering tables, collecting every validation error at once, v1 → v2 conversion.

## Problem and why

- A draft's oracles can only come from a copied or restored release (ADR 0028); the Sections tab is a placeholder. Oracles are the simplest section and the one every flow depends on, so their editor comes first (roadmap 15 before 16–18).
- The draft API has no read of the document and no client concurrency token: metadata saves are last-write-wins across requests. Item editors make lost updates likely, so they need an expected version.

## Decisions

| Question | Decision |
|---|---|
| Rename and delete (user, 2026-10-16) | **Refused while referenced.** Renaming or deleting a table or likelihood oracle whose key is referenced anywhere in the draft is refused (422) and the error lists where it is used. Unreferenced oracles rename and delete freely |
| Test rolls (user, 2026-10-16) | **Roll the saved draft, server-side.** A Studio endpoint rolls a table or asks a likelihood oracle of the stored draft by key, through the Randomness application queries; nested tables resolve. Unsaved edits are saved first. A test roll never changes the draft |
| v2 extensions (user, 2026-10-16) | **Core fields and keys are edited; the rest is preserved.** Tables: key, name, ranged (dice) or weighted, entries (min/max or weight, text, nested table, entry key). Likelihood oracles: key, name, sides, levels (key, label, target), chaos range (min, max, neutral, shiftPerPoint), exceptional %, random event table. Entry `sceneType`, entry `effects` and `chaos.tracker` round-trip unchanged and show read-only |
| References (Claude) | A domain service finds every place in the draft document that references an oracle key: Scene Type `oracles`, flow `oracles`, phase `selection {rule: oracle, table}`, `oracle` steps, `table` steps (and their branch entry keys), prompt `inspiration`, `randomEvent.table`, nested entry `table`. It reads defensively (the rest of the draft may be invalid) and returns readable paths (e.g. `flows[0].phases[1].steps[2]`) |
| Entry keys (Claude) | Removing or renaming an entry key used by a `table` step branch is a reference too, refused the same way |
| Save granularity (Claude) | One command per item: save a table (create or replace, with its original key for a rename), delete a table, save a likelihood oracle, delete a likelihood oracle. Each is all-or-nothing. A replaced item keeps its position; a new one is appended |
| Section invariant (Claude) | After every oracle command the `oracles` section is valid on its own for the draft's schema version: the same rules the publisher applies to the section (shape, dice coverage, unique keys across tables and likelihood oracles, nesting depth, `randomEvent.table` exists, entry keys unique per table, caps). Checks that need other sections (entry `sceneType` → Scene Type, `effects` targets, `chaos.tracker` → tracker) stay in the full draft validation. Publish and the editor share one rule: extracted, not duplicated. Invalid item → 422 with the path |
| Key collision (Claude) | A key already used by another table or likelihood oracle in the draft → 409 |
| Schema version 1 (Claude) | A v1 draft edits v1 fields only; v2-only fields (entry `key`, `sceneType`, `effects`, `randomEvent`, `chaos.tracker`) are refused (422) |
| Concurrency (Claude) | Oracle commands carry the `expectedVersion` the client read; a mismatch → 409 (`GameSystemModifiedConcurrently`, as today). The oracles read model exposes `version`; every oracle command's response returns the new one. Metadata editing is unchanged in this feature |
| Read model (Claude) | `GET` the draft's oracles: `version`, `schemaVersion`, `tables` and `likelihood` as stored, each with `usedBy` (reference paths) |
| Test-roll results (Claude) | A table roll returns the Randomness result (roll, entry, nested steps). A likelihood test takes a level key and an optional chaos factor and returns the Randomness answer; when the answer signals a random event and the oracle has `randomEvent.table`, the event table is rolled too and returned with it |
| API (Claude) | Under `/api/studio/game-systems/{key}`: `GET /oracles`; `POST /oracle-tables`, `PUT` and `DELETE /oracle-tables/{table}`; `POST /likelihood-oracles`, `PUT` and `DELETE /likelihood-oracles/{oracle}`; `POST /oracle-tables/{table}/test-rolls`; `POST /likelihood-oracles/{oracle}/test-answers`. Owner only (404 for others, as ADR 0028) |
| UI (Claude) | GameSystem page gains an **Oracles** tab: tables and likelihood oracles with entry/level counts and "used by"; create, delete (disabled with the reason while referenced). Each item opens an editor route (`/studio/game-systems/$key/oracle-tables/$table`, `…/likelihood-oracles/$oracle`): form on the left, test-roll panel on the right (stacked on phones) with the last results. Ranged tables show live coverage gaps; server 422 paths map to fields; 409 offers "Reload the latest version" |

## Design

### Backend (slices 1–3)

- `Studio/Domain`: `OracleReferences` (reference scan), oracle commands on the `GameSystem` aggregate (copy-on-write document), the shared oracles-section validator, new errors (`OracleNotFound`, `OracleKeyTaken`, `OracleInUse` with paths, invalid oracle → existing `InvalidReleaseContent` path style).
- `Studio/Application`: `SaveOracleTable`, `DeleteOracleTable`, `SaveLikelihoodOracle`, `DeleteLikelihoodOracle` (with `expectedVersion`), `GetGameSystemOracles`, `TestRollOracleTable`, `TestAskLikelihoodOracle` (dispatch the Randomness queries with fragments built from the stored draft; Randomness is shared kernel, Play is not touched).
- `Studio/Infrastructure/Http`: a `GameSystemOracleController`, error mapping and OpenAPI attributes as in `GameSystemController`; API tests; `make api`.

### Frontend (slices 4–5)

- `frontend/src/studio/oracles/`: hooks (`useGameSystemOracles`, save/delete/test mutations updating the cached `version`), Oracles tab, table editor, likelihood editor, test-roll panel, result views (extract Play's journal result renderers to `shared` only if the shapes match).

## Constraints

- ADR 0028: drafts are owner-only (404 otherwise) and may be invalid while work is in progress; the oracles section never becomes invalid through this editor.
- ADR 0010: Play reads only published releases; nothing here touches Play.
- Randomness stays pure: the randomness source is injected; test rolls go through its application queries.
- Shared UI from `frontend/src/shared/ui`; add a component only when a slice needs it (ADR 0004).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice (reviewer refuses ~1,850). Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-oracle-editor-1-domain` | `feat(studio): studio-oracle-editor 1/5 oracle editing domain` | T1, T2 | ~1,400 |
| 2 | `feat/studio-oracle-editor-2-application` | `feat(studio): studio-oracle-editor 2/5 oracle commands, read model and test rolls` | T3, T4 | ~1,400 |
| 3 | `feat/studio-oracle-editor-3-api` | `feat(studio): studio-oracle-editor 3/5 oracle editor API` | T5 | ~1,600 incl. ~600 generated |
| 4 | `feat/studio-oracle-editor-4-table-editor` | `feat(studio): studio-oracle-editor 4/5 oracles tab and table editor` | T6, T7 | ~1,600 |
| 5 | `feat/studio-oracle-editor-5-likelihood-editor` | `feat(studio): studio-oracle-editor 5/5 likelihood editor and e2e` | T8, T9 | ~1,400 |

Shippable: slices 1–3 add backend unused by the UI; slice 4 makes the Oracles tab usable for tables alone.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0029 (Studio oracle editor: per-item commands with expected version, section invariant, refuse rename/delete while referenced, test rolls of the saved draft, v2 extensions preserved) and ADR index row; glossary (Test roll); context map if Studio → Randomness is not yet drawn | delegated writer (slice 1) | [x] | |
| T2 | 1 | Domain: `OracleReferences`; shared oracles-section validator extracted from the publisher's checks; `GameSystem` oracle commands (save/delete table, save/delete likelihood oracle; rename; position kept; v1 field rules; extensions preserved); errors; unit tests incl. every reference kind and a publish regression | | [ ] | |
| T3 | 2 | Application: four oracle command handlers with `expectedVersion` (409), `GetGameSystemOracles` read model with `version` and `usedBy`; unit tests; Behat scenarios | | [ ] | |
| T4 | 2 | Application: `TestRollOracleTable`, `TestAskLikelihoodOracle` (random event table rolled on a signalled random event); unit tests with a fixed randomness source; Behat | | [ ] | |
| T5 | 3 | HTTP: `GameSystemOracleController` for the API above; every error mapped (400/401/403/404/409/415/422) with API tests incl. another owner's draft and a stale `expectedVersion`; `make api` (generated files in their own commit) | | [ ] | |
| T6 | 4 | Oracles tab: list of tables and likelihood oracles with counts and "used by", create (key, name; table ranged or weighted), delete with the in-use reason; hooks; Vitest; stories | | [ ] | |
| T7 | 4 | Table editor route: entries editor (ranged min/max with live coverage gaps, or weights), text, nested table, entry key, read-only `sceneType`/`effects` badges, rename; 409/422 handling; test-roll panel with nested steps; Vitest; stories | | [ ] | |
| T8 | 5 | Likelihood oracle editor route: sides, levels, chaos, exceptional %, random event table, read-only `chaos.tracker`; test-answer panel (level, chaos factor, random event result); Vitest; stories | | [ ] | |
| T9 | 5 | e2e: a GAME_MANAGER copies a preset, edits a table entry, test-rolls it, publishes; wrap-up: roadmap 15 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-16: Exploration done (release contract oracle shape and validators, every oracle reference kind, Randomness queries and stateless endpoints, Studio draft aggregate and concurrency, GameSystem page and hooks, presets: 4–9 tables, ≤ ~30 entries each, one likelihood oracle). Three decisions taken with the user (rename/delete, test rolls, v2 scope); slice plan of 5.
- 2026-10-16: T1 done: ADR 0029, ADR index row, glossary Test roll; context map unchanged (Studio — Randomness already drawn). Docs only, no RED/GREEN applicable; structural readback.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1 (T1, T2) on `feat/studio-oracle-editor-1-domain`.
