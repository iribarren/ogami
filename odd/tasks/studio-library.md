# Feature: studio-library

- **Locator:** `odd/tasks/studio-library.md` · Engram topic `odd/studio-library/tasks`
- **Issue:** #185 · **Branch:** `feat/studio-library-7-packs-ui` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-check-editor` (PRs #178–#184)

## Objective

Roadmap feature 18b (milestone M4). Any GAME_MANAGER authors generic content in **library packs**: oracle tables, likelihood oracles, trackers, tags, fact slots and Scene Types. A schema 2 GameSystem draft imports pack items. Each import copies the item and the pack items it references, and a key the draft already has wins (override by key). Imports remember their pack and content hash, so Studio flags drafts whose imported items changed or were removed in the library, and the GM updates or detaches them. Releases stay self-contained: publishing copies the draft as today, and the imports are kept as Studio-only provenance on the release row ([ADR 0033](../../docs/adr/0033-studio-library-packs.md), ADR 0010, ADR 0017 §12).

Out of scope:

- pack versions or pack releases;
- generic flows (settled: flows stay per GameSystem);
- pack credits or theme;
- packs made from a release copy;
- library presets in `backend/presets/`;
- importing into a pack or a schema 1 draft;
- any change to the release contract, its hashes or Play;
- author display names (Identity), since a pack only says whether it is `mine`.

## Problem and why

- Generic content (a fantasy `npc-name` table, a Mythic-style chaos tracker, a "Social" Scene Type) is re-authored in every draft or carried along by copying a whole release (ADR 0028). A GM cannot share part of a system.
- ADR 0017 §12 promised a Studio-only library with key overrides and publish-time resolution, and left its organisation, import semantics, generic flows and provenance open. Those are settled in ADR 0033.
- The vision's open question "can flows be generic library content" is closed as *no*.

## Model summary (today)

- **Draft.** A draft is the `GameSystem` aggregate (`backend/src/Studio/Domain/GameSystem/GameSystem.php`, about 1,076 lines). It is owner-only through `OwnedGameSystems` (anyone else gets 404) and lives in table `studio_gamesystem`: id, unique `game_system_key`, `owner_id`, JSONB `document` (a release document), timestamps and a Doctrine `version` lock.
- **Commands.** Every editor command changes one item, runs `ReleaseContent::checkItemRules` on the whole document and is all-or-nothing. The commands are oracle tables and likelihood oracles (`saveOracleTable`…), the narrative sections trackers/tags/fact-slots/scene-types/flows (`saveNarrativeItem`/`deleteNarrativeItem`, `NarrativeSection` enum), the sheet fields and layout, checks, metadata, and `restore`.
- **Item rules resolve references** (the `Catalog` in `ReleaseVersion2::validate`). A Scene Type naming an unknown table, tracker, tag, fact slot, Scene Type, check or field is refused. Only publish adds flow coherence (`FlowReferences::check`).
- **Key namespaces.** Keys follow `^[a-z0-9-]{1,64}$`. Oracle tables and likelihood oracles share one namespace; trackers, tags, fact slots, Scene Types and flows have one each.
- **`DraftReferences`** (`…/GameSystem/DraftReferences.php`) lists every path that names a key of each kind: oracle, table entry, tracker, Scene Type, tag, fact slot, sheet field, check, band. The rename/delete guards and the `usedBy` fields use it.
- **Publish.** `PublishGameSystemDraftHandler` runs `ReleaseContent::fromArray`, then `ReleasePublisher::publish(…, unlessUnchanged)`. Releases live in table `studio_gamesystem_release`, insert-only, with `notes` outside the hash.
- **Studio API.** `/api/studio/game-systems/{key}/…` (`GameSystemController`, `…OracleController`, `…NarrativeController`, `…SheetController`, `…CheckController`, `…ReleaseController`), under `ROLE_GAME_MANAGER` (`security.yaml`).
- **Frontend.** `GameSystemPage` tabs: Overview, Oracles, Trackers & tags, Scene Types, Flows, Sheet, Checks, Validation, Releases, Sections. Full-page editors for tables, likelihood oracles, Scene Types and flows. Hooks `useGameSystem*` save per item and `setQueryData` the returned view.

## Decisions

| Question | Decision |
|---|---|
| Structure (user, 2026-10-18) | **Library packs**: each has its own key space and holds oracle tables, likelihood oracles, trackers, tags, fact slots and Scene Types; references resolve inside the pack. Any GM creates packs; every GM browses and imports; only the author edits or deletes (others get 404 on edit) |
| Import (user, 2026-10-18) | **Copy, pinned.** Import copies the selected items and their pack closure into the draft. A key the draft has is kept (override). A requested key the draft has is a conflict. The draft records a library import per copied item `{section, key, pack, hash}`. Statuses: current / changed / removed, plus edited locally. Update from library (re-copy, confirm over local edits) and Detach |
| Generic flows (user, 2026-10-18) | **No.** Flows stay per GameSystem; copying a preset release covers starting from a generic flow |
| Provenance (user, 2026-10-18) | **Studio only.** At publish, the draft's imports go on the release row (`library_sources`, JSONB, nullable, outside the hash), shown in the release history. No contract change |
| Provenance after detach (Claude, slice 4) | `library_sources` is a publish-time record. A detach (or any change that leaves the content unchanged) does not rewrite an earlier release's sources and does not make a release; the next release with changed content records the imports of that moment |
| Aggregate (Claude) | A pack is a `GameSystem` of kind `library-pack` (new `GameSystemKind` enum, column `kind` with default `game-system`). It is blank at creation: schema 2, empty sections, no theme or credits. The oracle editor, the narrative editor (trackers, tags, fact slots, Scene Types) and metadata name/description work unchanged. A pack refuses flows, sheet fields and layout, checks, theme, credits, publish, restore and imports with `LibraryPackRefuses` (409, naming the operation). A pack key follows the draft key rules (unused by any GameSystem or release) |
| Sections (Claude) | `LibrarySection` enum: `oracles` (tables and likelihood oracles, one namespace), `trackers`, `tags`, `fact-slots`, `scene-types` |
| Item hash (Claude) | sha256 of the item's canonical JSON, with the same canonical encoding as `ReleaseContent` (sorted keys, normalized numbers). It compares a pack item, the recorded import and the draft item |
| Dependencies (Claude) | A pack item's direct dependencies are the pack keys that `DraftReferences` finds at paths inside that item (path prefix of the item, e.g. `sceneTypes[3]`). The closure follows them transitively within the pack. A reference to a key the draft already has stops there (override) |
| Import command (Claude) | `GameSystem::importFromLibrary(GameSystem $pack, list<{section, key}> $items)` works only on a schema 2 draft of kind game-system: a pack target is `LibraryPackRefuses` and a schema 1 draft is refused as for the sheet. Errors: an unknown pack item is `LibraryItemNotFound` (404); a requested key the draft already has, or a requested item already imported, is `LibraryItemKeyTaken` (409) listing every clashing key. Copied items are appended to their sections in pack order, then the item rules run (422 with path) and the imports are recorded; all or nothing. Imports live in a new `library_imports` JSONB column (a list, default `[]`), outside the document |
| Plan / preview (Claude) | `GameSystem::planLibraryImport(pack, items)` returns `{imports [{section, key}], overrides [{section, key}], conflicts [{section, key}]}` plus the item-rule error (path and message) of a dry run, if any. The import command and the preview query share it |
| Update and detach (Claude) | `updateFromLibrary(pack, section, key)` replaces the item in place with the pack's current one and imports any newly referenced pack items, with the override rule, then runs the item rules and records the new hashes. Errors: no such import is `LibraryImportNotFound` (404); a pack item that is gone is `LibraryItemRemoved` (409). `detachLibraryImport(section, key)` forgets the import (404 if there is none). After every command, imports whose item is no longer in the document are dropped, so a delete or rename drops them |
| Statuses (Claude) | Computed in the application layer, never stored. **removed**: the pack (any owner) or its item is gone. **changed**: the pack item's hash differs from the recorded hash. **current**: otherwise. **editedLocally**: the draft item's hash differs from the recorded hash. A draft's `libraryChanges` = count of changed and removed |
| Library API (Claude) | `GET /api/studio/library/packs` lists every pack: key, name, description, mine, item counts per section, updatedAt. `POST /api/studio/library/packs {key, name, description}` creates one. `GET /api/studio/library/packs/{pack}` is the catalog, readable by any GM: sections, then items `{key, label, kind?: table\|likelihood, dependsOn [{section, key}]}`. Pack editing and deletion reuse `/api/studio/game-systems/{key}/…` as the owner. `GET /api/studio/game-systems` keeps listing only GameSystems; `GET /{key}` gains `kind`, and later `libraryChanges` |
| Import API (Claude) | Under `/api/studio/game-systems/{key}/library-imports`: `GET` (imports with section, key, label, pack `{key, name}\|null`, status, editedLocally); `POST /preview {pack, items}` (the plan, no write); `POST {expectedVersion, pack, items}` (import; returns the imports view and the new version); `POST /{section}/{item}/update {expectedVersion}`; `DELETE /{section}/{item}?expectedVersion=N`. Statuses 400/404/409/415/422 follow ADR 0030 |
| Frontend (Claude) | **Studio home:** a Library section listing packs (mine first), with "New pack" (key, name, description). **Pack page:** `GameSystemPage` made kind-aware, with only Overview, Oracles, Trackers & tags, Scene Types and Validation, and no publish or releases; read-only for non-owners through the catalog. **Draft:** a Library tab with imports, status badges, Update (confirm when edited locally) and Detach, plus an Import dialog (pick a pack, browse the catalog by section, select, show the preview's imports, overrides, conflicts and error, then import). The draft list and Overview get a `libraryChanges` badge; the release history shows its library sources |

## Design

### Backend (slices 1–6)

- ADR 0033, ADR index row, glossary (Library, Library import, Library pack), vision question closed.
- `GameSystemKind`, migration (`kind`, `library_imports`), `GameSystem::createLibraryPack`, pack guards on every refused command, `CreateLibraryPack`, `ListLibraryPacks`; publish and restore handlers refuse a pack.
- `LibrarySection`, the item hash and dependency closure (via `DraftReferences`), plan/import/update/detach, pruning imports after each command; `ImportLibraryItems`, `UpdateLibraryImport`, `DetachLibraryImport`, `GetGameSystemLibraryImports` (statuses), `PreviewLibraryImport`, `GetLibraryPack` (catalog); publish stores `library_sources` on the release row (migration).
- Library pack controller and catalog; 409 mapping of `LibraryPackRefuses` on every existing Studio endpoint; `kind` on the GameSystem view; API tests; `make api`.
- Library import controller; API tests; `make api`.
- `libraryChanges` on the list and detail views; `librarySources` on release history; API tests; `make api` (slice 6, with their frontend).

### Frontend (slices 6–9)

- `libraryChanges` badges and library sources in release history (slice 6, with their API).
- Library section on the Studio home, the new-pack dialog, kind-aware `GameSystemPage` for packs.
- Import dialog (catalog browser, selection, preview, import) and the draft's Library tab (statuses, Update, Detach).
- Playwright e2e.

## Constraints

- The release contract, every hash and Play stay unchanged. Imported items are ordinary draft items.
- The publisher's item rules decide what a pack item may be, in a pack as in a draft.
- Domain stays framework-free. PHPat context rules hold; Studio does not import Identity.
- Frontend uses only the generated client; no new library (ADR 0004).
- Drafts stay owner-only. Packs are readable by every GM through the library endpoints only.

## Slice plan

Forecasts include tests, docs and generated files. The hard ceiling is about 1,700 lines per slice; the reviewer refuses about 1,850. Each slice's first commit carries the previous slice's review fixes. A slice with API changes commits the regenerated `openapi.json` / `schema.d.ts` on their own.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-library-1-library-packs` | `feat(studio): studio-library 1/8 library packs` | T1, T2, T3 | ~1,300 |
| 2 | `feat/studio-library-2-imports` | `feat(studio): studio-library 2/8 library imports in the draft` | T4 | ~1,500 |
| 3 | `feat/studio-library-3-application` | `feat(studio): studio-library 3/8 library import handlers` | T5 | ~1,100 |
| 4 | `feat/studio-library-4-pack-api` | `feat(studio): studio-library 4/8 library pack API` | T6 | ~1,450 incl. ~350 generated |
| 5 | `feat/studio-library-5-import-api` | `feat(studio): studio-library 5/9 library import API` | T7 | ~1,800 incl. ~700 generated |
| 6 | `feat/studio-library-6-change-flags` | `feat(studio): studio-library 6/9 library change flags and provenance` | T12, T10 | ~1,300 incl. ~250 generated |
| 7 | `feat/studio-library-7-packs-ui` | `feat(studio): studio-library 7/9 library packs in Studio` | T8 | ~1,300 |
| 8 | `feat/studio-library-8-import-ui` | `feat(studio): studio-library 8/9 import dialog and library tab` | T9 | ~1,600 |
| 9 | `feat/studio-library-9-e2e` | `feat(studio): studio-library 9/9 end to end` | T11 | ~500 |

Slice 2 stopped at T4 (1,562 lines), so T5 became slice 3 and the plan grew to 8 slices. Slice 4 measured 773 generated lines for three endpoints, so the five import endpoints alone fill slice 5: `libraryChanges` and `librarySources` (T12) moved to slice 6 with their frontend (T10), and the plan grew to 9 slices.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0033 (Studio library packs) and ADR index row; glossary Library, Library import, Library pack; vision question on generic flows closed | inline (parent: design authored this session) | [x] | cc9db7f |
| T2 | 1 | Domain: `GameSystemKind`; `GameSystem::createLibraryPack`; `LibraryPackRefuses` on flows, sheet fields and layout, checks, theme, credits, restore (and later publish/imports); persistence `kind` column + migration (default `game-system`), `library_imports` column (default `[]`), repository mapping; legacy rows read as `game-system` | delegated writer (slice 1) | [x] | 117fa6a |
| T3 | 1 | Application: `CreateLibraryPack` (draft key rules) and handler, `ListLibraryPacks` query (every pack, `mine`, counts per section, updatedAt); publish handler refuses a pack; the existing GameSystem list excludes packs | delegated writer (slice 1) | [x] | a77c28c |
| T4 | 2 | Domain: `LibrarySection`, item hash, dependency closure via `DraftReferences`; `planLibraryImport`, `importFromLibrary`, `updateFromLibrary`, `detachLibraryImport`, pruning after every command; `LibraryItemNotFound`, `LibraryItemKeyTaken`, `LibraryImportNotFound`, `LibraryItemRemoved` | delegated writer (slice 2) | [x] | f0e2702 |
| T5 | 3 | Application: `ImportLibraryItems`, `UpdateLibraryImport`, `DetachLibraryImport`, `PreviewLibraryImport`, `GetGameSystemLibraryImports` (statuses, editedLocally); publish stores `library_sources` on the release row (migration) | delegated writer (slice 3) | [x] | bdd35da |
| T6 | 4 | Application `GetLibraryPack` (catalog with dependsOn, moved from T5 for slice 2's size); HTTP: library pack controller (list, create, catalog), `LibraryPackRefuses` → 409 on every existing Studio endpoint, `kind` on the GameSystem view, API tests for every status, `make api` | delegated writer (slice 4) | [x] | a2d87fa, 19d12ab |
| T7 | 5 | HTTP: library import controller (list, preview, import, update, detach), API tests for every status (including 409 on a concurrent save), `make api` | delegated writer (slice 5) | [x] | bdfa001, aaca611 |
| T12 | 6 | Application + HTTP: `libraryChanges` on the GameSystem list and detail views, `librarySources` on release history, API tests, `make api` (moved from T7 for slice 5's size) | delegated writer (slice 6) | [x] | 5855bc2, 9c2f02d |
| T8 | 7 | Frontend: Library section on the Studio home, new-pack dialog, kind-aware `GameSystemPage` for packs (tabs, no publish/releases), hooks, tests, stories | delegated writer (slice 7) | [x] | |
| T9 | 8 | Frontend: Import dialog (pack picker, catalog by section, selection, preview with imports/overrides/conflicts/error) and the draft's Library tab (statuses, Update with confirm over local edits, Detach), 409 reload, tests, stories | delegated writer (slice 8) | [ ] | |
| T10 | 6 | Frontend: `libraryChanges` badges on the draft list and Overview; library sources in release history; tests | delegated writer (slice 6) | [x] | d3420d7 |
| T11 | 9 | Playwright: author a pack (table + Scene Type naming it), import the Scene Type into a draft that overrides the table, change the pack, see the flag, update, publish; feature doc close | delegated writer (slice 9) | [ ] | |

## Progress

- 2026-10-18: Feature started. Explored the draft aggregate, item rules, `DraftReferences`, the publisher, the Studio API and UI, and the Play ACL. The user chose library packs, pinned copy imports, no generic flows and Studio-only provenance. Issue #185.
- 2026-10-18: T1 done. Added ADR 0033 and its index row, the glossary entries Library, Library import and Library pack, and closed the vision question on generic flows.

- 2026-10-18: T2 done. `GameSystemKind`, `GameSystem::createLibraryPack` and `LibraryPackRefuses` (flows, sheet fields and layout, checks, theme, credits, restore); migration `Version20261018100000` adds `kind` and `library_imports`; `libraryImports` is `[]` until slice 2. Repository gained `libraryPacks()`; the in-memory test repository follows.
- 2026-10-18: T3 done. `CreateLibraryPack`, `ListLibraryPacks` (every owner, `mine`, item counts, mine first then by name); the GameSystem list excludes packs; publish and restore refuse a pack in the handlers (a pack has no release, so restore refuses before the release lookup).
- 2026-10-18: T4 done. `LibrarySection`, `LibraryItemRef`, `LibraryImport`, `LibraryImportPlan` and `LibraryItems` (closure through `DraftReferences` path prefixes); `ReleaseContent::itemHash` (sorted keys, normalized numbers); `GameSystem::planLibraryImport`, `importFromLibrary`, `updateFromLibrary`, `detachLibraryImport`; every command ends in one `commit()` that prunes vanished imports; the Doctrine repository reads imports fail-closed. Tests: 41 domain, 3 integration.
- 2026-10-18: Slice 2 review fixes. `libraryPacks()` no longer parses a pack's imports (a pack never has any). The `list[1]` vs `list[10]` finding did not reproduce: the prefix includes the closing bracket (`sceneTypes[1]`), so it never matches `sceneTypes[10]`; a regression test with 12 items now pins it.
- 2026-10-18: T5 done. Commands `ImportLibraryItems`, `UpdateLibraryImport`, `DetachLibraryImport` (return nothing, like every write command; the owner-only `OwnedGameSystems` and the optimistic lock apply), query `PreviewLibraryImport` (`LibraryImportPreview`: imports, overrides, conflicts, dry-run error `{path, message}` or null) and `GetGameSystemLibraryImports` (`LibraryImportView` with status current/changed/removed and editedLocally, each pack loaded once). `LibraryPacks` finds a pack of any owner; an unknown key or a GameSystem is `LibraryPackNotFound`. Publish stores the draft's imports as `library_sources` (migration `Version20261018110000`, nullable JSONB outside the hash; **null, never `[]`**, when none; legacy rows read null).
- 2026-10-18: Slice 3 review fixes. `GetGameSystemLibraryImportsHandler` caches a missing pack with `array_key_exists`, so it is looked up once (counting-repository test; RED first). `libraryPacks()` deliberately skips `readable()`: that method only parses a row's imports and a pack never has any, so the two are equivalent for a well-formed pack and the skip is what keeps one malformed row from breaking the listing (bf22b4f); unchanged. A test pins the provenance decision: import, publish, detach, publish again is refused as unchanged and the earlier release keeps its `library_sources` (green on first run, no production change).
- 2026-10-18: T6 done (backend). `GetLibraryPack` (`LibraryPackCatalog`: every `LibrarySection` in order, items in document order with `label` = name, else label, else key (`LibraryItems::labelOf`, shared with the imports view), `kind` table/likelihood for oracles only, and `dependsOn` = direct dependencies from `LibraryItems::dependenciesOf`). `LibraryPackController`: `GET`/`POST /api/studio/library/packs` and `GET /{pack}`; `POST` answers 201 with the GameSystem view (`GameSystemResponse`, `kind: library-pack`) and `Location` = the catalog URL. `GameSystemView`/`GameSystemResponse` gain `kind`. `LibraryPackRefuses` maps to 409 in the narrative (flows), sheet (fields, layout), check, release (publish, restore) and metadata (credits, theme) endpoints, each with an API test (RED first: 18 refusals failed before the mapping). API tests: 43 in `LibraryPackApiTest`; 2 handler tests.
- 2026-10-18: Regenerated `openapi.json` and `schema.d.ts` (`make api`); `GameSystem.kind` is required in TypeScript, so the two `GameSystem` fixtures gained `kind: 'game-system'` and `tsc` passes.
- 2026-10-18: T7 done (backend). `LibraryImportController` under `/api/studio/game-systems/{key}/library-imports`: `GET` (list), `POST /preview`, `POST` (import), `POST /{section}/{item}/update`, `DELETE /{section}/{item}?expectedVersion=N`. Every write answers 200 with `{version, imports}` (command, then `GetGameSystemLibraryImports`, which now returns `LibraryImportsView` = draft version + views, like the narrative read); detach returns the view too, as the narrative DELETE does. Statuses: malformed body or non-integer version 400, non-JSON 415, bad `items` (not a list, unknown section, key not a text) 422 with the path, unknown draft/pack/item/import 404 (an unknown section in the path never matches the route: 404), `LibraryItemKeyTaken`, `LibraryItemRemoved`, `LibraryPackRefuses`, stale or concurrent version 409, item-rule failure and schema 1 draft 422. A pack target answers 409 to import, preview and detach, but update is 404: a pack has no imports to update. RED: 44 API tests failed on the missing route; GREEN after the controller and DTOs (`LibraryImportApiTest`, 44 tests).
- 2026-10-18: Slice 5 review fixes. `LibraryImportController` runs import, update and detach, and the `GetGameSystemLibraryImports` read-back, in one DBAL transaction (`Connection::transactional`; the command bus's `doctrine_transaction` nests inside it, and a refusal rolls both back), so the response shows exactly this write and a failing read rolls it back. RED first: a `postFlush` listener corrupts the stored imports and clears the EntityManager so the read fails; before the fix the write stayed committed, after it the draft is unchanged. A deterministic interleaved-save test is not feasible in one process, so the atomicity is pinned through the failing read. New API test: update from the library that breaks an item rule is 422 with the draft unchanged. Known follow-up outside this feature: the narrative, oracle, sheet and check controllers share the same write-then-read pattern and keep it.
- 2026-10-18: T12 done. `LibraryImportStandings` (Application) holds the status computation that `GetGameSystemLibraryImportsHandler` had, so the imports list, the draft detail and the draft list agree; `ofEach` looks each pack up once across drafts, and a draft without imports loads no pack. `libraryChanges` (changed + removed, editedLocally not counted) is on `GameSystemView` (detail) and `GameSystemSummary` (list); a pack has 0. Release history lines gain `librarySources` (`{section, key, pack}`, no hash; null when the release recorded none, legacy rows included). Tests: unit (counts, local edit, no-import and pack, list/detail agreement with one pack lookup) written after the extraction, so no RED for them; API tests for list, detail and history.
- 2026-10-18: T10 done. `LibraryChangesBadge` (warning badge, "1 library change" / "N library changes", nothing at 0) on the draft cards of the Studio home and in the Overview header, beside `Unpublished changes`. The release history shows `Library: fantasy-basics (npc-name, social); wild-tales (noble)` under a release's notes, grouped by pack, and nothing for a null `librarySources`. `usePublishGameSystem` now types its result as `Release` (what the endpoint returns; the summary gained `librarySources`, the release detail did not). Vitest for each; stories `WithLibraryChanges` (home, Overview) and the release history story shows the sources. Tests written after the components, so no RED.
- 2026-10-18: Slice 6 review fixes (partial). The `postFlush` listener of `aReadThatFailsAfterAWriteRollsTheWriteBack` is asserted to have fired; `ReleasesTab` shows no `Library:` line for an empty `librarySources` list and names the section of a key two sections of a pack share (tests written after). The two malformed-row warnings are not fixed: the throw comes from `DoctrineGameSystemRepository::readable()` (Infrastructure), before the Application handlers run, so they cannot degrade it; left to the parent.
- 2026-10-18: T8 done. `studio/library/` holds `useLibraryPacks`, `useLibraryPack` (catalog, for slice 8), `useCreateLibraryPack`, the Library section of the Studio home (`LibraryPacks`: a card per pack, `Yours` badge, mine links to the editable page, the others to the read-only `LibraryPackPage` at `/studio/library/$pack`), `NewLibraryPackDialog` and fixtures. `GameSystemPage` is kind-aware: a pack has the Overview, Oracles, Trackers & tags, Scene Types and Validation tabs, a `Library pack` badge, no publish, releases or library badges, pack wording for validation and discard; `MetadataForm` hides credits and theme for a pack; `SectionCounts` gained a compact form; a discarded pack drops out of the packs list. No API change. Tests written after the code.
- 2026-10-18: Slice 6 review fixes (rest). The two malformed-row warnings are fixed: `DoctrineGameSystemRepository::ofOwner` and `ofKey` no longer parse `library_imports` (`readable()` removed), so one corrupt row does not break the list or the Overview. `LibraryImportStandings::changesOf`/`changesOfEach` count 0 for a draft whose `libraryImports()` throws, used by `ListMyGameSystemsHandler` and `GetGameSystemHandler`. The imports read and every command still fail closed (the aggregate's `commit()` and `libraryImports()` throw `UnexpectedValueException`, which surfaces as a 500, as before). RED first: the new API test got a 500 on the list; the two repository tests were rewritten to the new contract.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-d6a850a27d4e8fc0` | cc9db7f..a77c28c | Approved, 2 suggestions: restore case for another owner's pack in `LibraryPackHandlersTest` (fixed in slice 2); map `LibraryPackRefuses` to 409 with API tests (slice 4, as planned) | #186 |
| 2 | `review-dc0d296433561739` | 2369c36..f0e2702 | Approved, 2 findings, fixed in slice 3: `LibraryItems::dependenciesOf` matched path prefix `list[1]` against `list[10]`…; `libraryPacks()` parsed every pack's imports, so one malformed row broke every GM's listing | #187 |
| 3 | `review-8163438989572aec` | bf22b4f..bdd35da | Approved, 3 findings, handled in slice 4: (warning) `library_sources` is only recorded on a new release, so a detach-only change keeps the last release's sources (decided: publish-time record, pinned by a test); (suggestion) `GetGameSystemLibraryImportsHandler` pack cache `??=` looks up a missing pack again (use `array_key_exists`); (suggestion) `libraryPacks()` bypasses `readable()` (verify equivalence) | #188 |
| 4 | `review-e1f289f825a00d01` | f3bc6c0..a2d87fa (generated 19d12ab not reviewed, user choice) | Approved, no findings. Known for later: a malformed pack row makes its catalog 500 (same as a malformed draft); no API test reads a row stored before the `kind` column (defaults to `game-system`) | #189 |
| 5 | `review-e7c811d0dc9df712` | main..bdfa001 (generated aaca611 not reviewed, user choice; size 2,645 accepted by the user) | Approved, 2 findings, fixed in slice 6: (warning) write-then-read is not atomic in `LibraryImportController`: an interleaved save's version leaks into the response, and a failing read after a persisted write answers 404/409 (the narrative controllers share the pattern); (suggestion) API test for update → 422 with the draft unchanged | #190 |
| 6 | `review-b9e21d6a1e6d8db4` | main..d3420d7 (fix 8a947e2, T12 5855bc2, generated 9c2f02d, T10 d3420d7; 813 lines) | Approved, 4 findings, fixed in slice 7: (warning ×2) `ListMyGameSystemsHandler` and `GetGameSystemHandler` read `libraryImports()` through `LibraryImportStandings`, so one malformed `library_imports` row breaks the whole list / the Overview (degrade to 0 changes and test with a malformed row); (suggestion) `LibraryImportApiTest::aReadThatFailsAfterAWriteRollsTheWriteBack` should assert the `postFlush` listener fired; (suggestion) `ReleasesTab` shows a dangling `Library:` for an empty `librarySources` list, and `sourcesByPack` drops the section (the same key in two sections renders twice) | #191 |

## Next step

Slice 7: slice 6 review fixes, then T8 (library packs in Studio). Then native review and PR, then slice 8 (T9, import dialog and Library tab).
