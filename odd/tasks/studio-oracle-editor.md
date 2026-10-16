# Feature: studio-oracle-editor

- **Locator:** `odd/tasks/studio-oracle-editor.md` · Engram topic `odd/studio-oracle-editor/tasks`
- **Issue:** #141 · **Branch:** `feat/studio-oracle-editor-3-table-api` · **Status:** in progress (slice 3)
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

### Backend (slices 1–4)

- `Studio/Domain`: `OracleReferences` (reference scan), oracle commands on the `GameSystem` aggregate (copy-on-write document), the shared oracles-section validator, new errors (`OracleNotFound`, `OracleKeyTaken`, `OracleInUse` with paths, invalid oracle → existing `InvalidReleaseContent` path style).
- `Studio/Application`: `SaveOracleTable`, `DeleteOracleTable`, `SaveLikelihoodOracle`, `DeleteLikelihoodOracle` (with `expectedVersion`), `GetGameSystemOracles`, `TestRollOracleTable`, `TestAskLikelihoodOracle` (dispatch the Randomness queries with fragments built from the stored draft; Randomness is shared kernel, Play is not touched).
- `Studio/Infrastructure/Http`: a `GameSystemOracleController`, error mapping and OpenAPI attributes as in `GameSystemController`; API tests; `make api`.

### Frontend (slices 5–6)

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
| 3 | `feat/studio-oracle-editor-3-table-api` | `feat(studio): studio-oracle-editor 3/6 oracles read and table API` | T5 | ~1,600 incl. ~700 generated |
| 4 | `feat/studio-oracle-editor-4-likelihood-api` | `feat(studio): studio-oracle-editor 4/6 likelihood oracle API` | T6 | ~1,000 incl. ~400 generated |
| 5 | `feat/studio-oracle-editor-5-table-editor` | `feat(studio): studio-oracle-editor 5/6 oracles tab and table editor` | T7, T8 | ~1,600 |
| 6 | `feat/studio-oracle-editor-6-likelihood-editor` | `feat(studio): studio-oracle-editor 6/6 likelihood editor and e2e` | T9, T10 | ~1,400 |

Shippable: slices 1–4 add backend unused by the UI; slice 5 makes the Oracles tab usable for tables alone. The API was split in two on 2026-10-16 (slice 3 forecast ~2,700 lines with 8 endpoints and their generated spec and types, over the ceiling).

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0029 (Studio oracle editor: per-item commands with expected version, section invariant, refuse rename/delete while referenced, test rolls of the saved draft, v2 extensions preserved) and ADR index row; glossary (Test roll); context map if Studio → Randomness is not yet drawn | delegated writer (slice 1) | [x] | `e0af8eb` |
| T2 | 1 | Domain: `OracleReferences`; shared oracles-section validator extracted from the publisher's checks; `GameSystem` oracle commands (save/delete table, save/delete likelihood oracle; rename; position kept; v1 field rules; extensions preserved); errors; unit tests incl. every reference kind and a publish regression | delegated writer (slice 1) | [x] | `7719525` |
| T3 | 2 | Application: four oracle command handlers with `expectedVersion` (409), `GetGameSystemOracles` read model with `version` and `usedBy`; unit tests; Behat scenarios | delegated writer (slice 2) | [x] | `481bbfb` |
| T4 | 2 | Application: `TestRollOracleTable`, `TestAskLikelihoodOracle` (random event table rolled on a signalled random event); unit tests with a fixed randomness source; Behat | delegated writer (slice 2) | [x] | `ca58392` |
| T5 | 3 | HTTP: `GameSystemOracleController` with `GET /oracles` (tables and likelihood oracles), `POST /oracle-tables`, `PUT`/`DELETE /oracle-tables/{table}`, `POST /oracle-tables/{table}/test-rolls`; every error mapped (400/401/403/404/409/415/422) with API tests incl. another owner's draft and a stale `expectedVersion`; `make api` (generated files in their own commit) | | [ ] | |
| T6 | 4 | HTTP: `POST /likelihood-oracles`, `PUT`/`DELETE /likelihood-oracles/{oracle}`, `POST /likelihood-oracles/{oracle}/test-answers` on the same controller; errors and API tests as T5; `make api` | | [ ] | |
| T7 | 5 | Oracles tab: list of tables and likelihood oracles with counts and "used by", create (key, name; table ranged or weighted), delete with the in-use reason; hooks; Vitest; stories | | [ ] | |
| T8 | 5 | Table editor route: entries editor (ranged min/max with live coverage gaps, or weights), text, nested table, entry key, read-only `sceneType`/`effects` badges, rename; 409/422 handling; test-roll panel with nested steps; Vitest; stories | | [ ] | |
| T9 | 6 | Likelihood oracle editor route: sides, levels, chaos, exceptional %, random event table, read-only `chaos.tracker`; test-answer panel (level, chaos factor, random event result); Vitest; stories | | [ ] | |
| T10 | 6 | e2e: a GAME_MANAGER copies a preset, edits a table entry, test-rolls it, publishes; wrap-up: roadmap 15 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-16: Exploration done (release contract oracle shape and validators, every oracle reference kind, Randomness queries and stateless endpoints, Studio draft aggregate and concurrency, GameSystem page and hooks, presets: 4–9 tables, ≤ ~30 entries each, one likelihood oracle). Three decisions taken with the user (rename/delete, test rolls, v2 scope); slice plan of 5.
- 2026-10-16: T1 done: ADR 0029, ADR index row, glossary Test roll; context map unchanged (Studio — Randomness already drawn). Docs only, no RED/GREEN applicable; structural readback.
- 2026-10-16: T2 done: `OracleReferences`, `OraclesSection` (one validator; `ReleaseContent` and `ReleaseVersion2` call it; entry-key checks moved into `ReleaseOracles`), `GameSystem` oracle commands, errors. RED observed for the command tests (methods missing); `OracleReferences` and the section extraction were written before their tests (GREEN on first run, one test fixed); publish regression in `OraclesSectionTest`. Commands take `$now` like `editMetadata`; entry-key duplicate errors now surface before tracker/Scene Type errors in a document with several faults.
- 2026-10-16: Slice 1 review fixes (slice 2, commit 1): publish-level test for a `table` step branch on an entry key no table has (passed first time, code was right); `OracleInUse` now lists the paths of every dropped referenced entry key (RED observed: only the first path came back).
- 2026-10-16: T3 done: four oracle commands and handlers (`OwnedGameSystems::getAtVersion` does the 404 then the `expectedVersion` 409 check), `GetGameSystemOracles` (`version`, `schemaVersion`, `tables`, `likelihood` as `OracleView` with `usedBy`; items that are not arrays with a string key are left out). Decision: commands and handlers return `void` (the command bus returns nothing); slice 3's controller reads the new `version` back with `GetGameSystemOracles` after dispatching. Handlers were written before their tests, which passed first time (the only RED was a test bug); Behat `gamesystem_oracles.feature` (11 scenarios) added.
- 2026-10-16: T4 done: `TestRollOracleTable` (returns `OracleTableResultView`) and `TestAskLikelihoodOracle` (returns `LikelihoodTestView`: answer + nullable random event roll) dispatch the Randomness queries with the stored draft's whole table set; `StoredOracles` reads the section raw so an invalid draft fails in Randomness (`InvalidOracleTable`, `InvalidLikelihoodOracle` for an unknown level or chaos factor: slice 3 maps both to 422) rather than being repaired; unknown table or oracle key: `OracleNotFound`. Test rolls take no repository, so a roll cannot save; tests assert document and version unchanged. v1 drafts roll fine (Randomness accepts the v1 table shape; tested on `contract-doc-example`). Handlers were written before their tests; one test was RED for a wrong scripted roll, the rest passed first time. Test double `tests/Unit/Studio/Application/RandomnessQueryBus.php` (the two Randomness queries over a scripted RNG) is used by unit tests and Behat.
- 2026-10-16: Slice 2 review fixes (slice 3, commit 1): `InMemoryGameSystemRepository::save()` now advances `version` like Doctrine's optimistic lock; handler tests use the real version sequence, refusals assert the version unchanged, new test: two clients read version N, one saves, the other is refused with `GameSystemModifiedConcurrently`; `OracleInUse` messages asserted for one and several dropped entry keys. Written after the fix, GREEN on first run (the fake change itself turned two tests RED, fixed).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-b6727da40a526672` | `e0af8eb`, `7719525` | approved, acknowledged. Suggestions, fixed in slice 2: publish-level test for a `table` step branch naming an unknown entry key (`ReleaseVersion2` `tableEntryKeys` now indexes raw keys); `OracleInUse` for entry keys collects the paths of every dropped referenced entry key, not just the first | [#142](https://github.com/iribarren/ogami/pull/142) |
| 2 | `review-1f8d4baa63e3e49b` | `86db4ea`, `481bbfb`, `ca58392` | approved, acknowledged. Fixed in slice 3: (warning) the in-memory GameSystem repository never advanced `version` on save, so no test proved a real stale-version refusal (A reads N, B saves, A refused) nor that a test roll never saves; (suggestion) assert the `OracleInUse` singular and plural messages | [#143](https://github.com/iribarren/ogami/pull/143) |

## Next step

Slice 3 (slice 2 review fixes, T5) on `feat/studio-oracle-editor-3-table-api`.
