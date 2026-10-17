# Feature: studio-library

- **Locator:** `odd/tasks/studio-library.md` · Engram topic `odd/studio-library/tasks`
- **Issue:** #185 · **Branch:** `feat/studio-library-1-library-packs` · **Status:** in progress
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

### Backend (slices 1–4)

- ADR 0033, ADR index row, glossary (Library, Library import, Library pack), vision question closed.
- `GameSystemKind`, migration (`kind`, `library_imports`), `GameSystem::createLibraryPack`, pack guards on every refused command, `CreateLibraryPack`, `ListLibraryPacks`; publish and restore handlers refuse a pack.
- `LibrarySection`, the item hash and dependency closure (via `DraftReferences`), plan/import/update/detach, pruning imports after each command; `ImportLibraryItems`, `UpdateLibraryImport`, `DetachLibraryImport`, `GetGameSystemLibraryImports` (statuses), `PreviewLibraryImport`, `GetLibraryPack` (catalog); publish stores `library_sources` on the release row (migration).
- Library pack controller and catalog; 409 mapping of `LibraryPackRefuses` on every existing Studio endpoint; `kind` on the GameSystem view; API tests; `make api`.
- Library import controller; `libraryChanges` on the list and detail views; `librarySources` on release history; API tests; `make api`.

### Frontend (slices 5–7)

- Library section on the Studio home, the new-pack dialog, kind-aware `GameSystemPage` for packs.
- Import dialog (catalog browser, selection, preview, import) and the draft's Library tab (statuses, Update, Detach).
- `libraryChanges` badges, library sources in release history, Playwright e2e.

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
| 1 | `feat/studio-library-1-library-packs` | `feat(studio): studio-library 1/7 library packs` | T1, T2, T3 | ~1,300 |
| 2 | `feat/studio-library-2-imports` | `feat(studio): studio-library 2/7 library imports` | T4, T5 | ~1,600 |
| 3 | `feat/studio-library-3-pack-api` | `feat(studio): studio-library 3/7 library pack API` | T6 | ~1,300 incl. ~350 generated |
| 4 | `feat/studio-library-4-import-api` | `feat(studio): studio-library 4/7 library import API` | T7 | ~1,600 incl. ~450 generated |
| 5 | `feat/studio-library-5-packs-ui` | `feat(studio): studio-library 5/7 library packs in Studio` | T8 | ~1,300 |
| 6 | `feat/studio-library-6-import-ui` | `feat(studio): studio-library 6/7 import dialog and library tab` | T9 | ~1,600 |
| 7 | `feat/studio-library-7-flags-e2e` | `feat(studio): studio-library 7/7 change flags, provenance and end to end` | T10, T11 | ~1,000 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0033 (Studio library packs) and ADR index row; glossary Library, Library import, Library pack; vision question on generic flows closed | inline (parent: design authored this session) | [x] | |
| T2 | 1 | Domain: `GameSystemKind`; `GameSystem::createLibraryPack`; `LibraryPackRefuses` on flows, sheet fields and layout, checks, theme, credits, restore (and later publish/imports); persistence `kind` column + migration (default `game-system`), `library_imports` column (default `[]`), repository mapping; legacy rows read as `game-system` | delegated writer (slice 1) | [ ] | |
| T3 | 1 | Application: `CreateLibraryPack` (draft key rules) and handler, `ListLibraryPacks` query (every pack, `mine`, counts per section, updatedAt); publish handler refuses a pack; the existing GameSystem list excludes packs | delegated writer (slice 1) | [ ] | |
| T4 | 2 | Domain: `LibrarySection`, item hash, dependency closure via `DraftReferences`; `planLibraryImport`, `importFromLibrary`, `updateFromLibrary`, `detachLibraryImport`, pruning after every command; `LibraryItemNotFound`, `LibraryItemKeyTaken`, `LibraryImportNotFound`, `LibraryItemRemoved` | delegated writer (slice 2) | [ ] | |
| T5 | 2 | Application: `ImportLibraryItems`, `UpdateLibraryImport`, `DetachLibraryImport`, `PreviewLibraryImport`, `GetGameSystemLibraryImports` (statuses, editedLocally), `GetLibraryPack` (catalog with dependsOn); publish stores `library_sources` on the release row (migration) | delegated writer (slice 2) | [ ] | |
| T6 | 3 | HTTP: library pack controller (list, create, catalog), `LibraryPackRefuses` → 409 on every existing Studio endpoint, `kind` on the GameSystem view, API tests for every status, `make api` | delegated writer (slice 3) | [ ] | |
| T7 | 4 | HTTP: library import controller (list, preview, import, update, detach), `libraryChanges` on list/detail, `librarySources` on release history, API tests for every status (including 409 on a concurrent save), `make api` | delegated writer (slice 4) | [ ] | |
| T8 | 5 | Frontend: Library section on the Studio home, new-pack dialog, kind-aware `GameSystemPage` for packs (tabs, no publish/releases), hooks, tests, stories | delegated writer (slice 5) | [ ] | |
| T9 | 6 | Frontend: Import dialog (pack picker, catalog by section, selection, preview with imports/overrides/conflicts/error) and the draft's Library tab (statuses, Update with confirm over local edits, Detach), 409 reload, tests, stories | delegated writer (slice 6) | [ ] | |
| T10 | 7 | Frontend: `libraryChanges` badges on the draft list and Overview; library sources in release history; tests | delegated writer (slice 7) | [ ] | |
| T11 | 7 | Playwright: author a pack (table + Scene Type naming it), import the Scene Type into a draft that overrides the table, change the pack, see the flag, update, publish; feature doc close | delegated writer (slice 7) | [ ] | |

## Progress

- 2026-10-18: Feature started. Explored the draft aggregate, item rules, `DraftReferences`, the publisher, the Studio API and UI, and the Play ACL. The user chose library packs, pinned copy imports, no generic flows and Studio-only provenance. Issue #185.
- 2026-10-18: T1 done. Added ADR 0033 and its index row, the glossary entries Library, Library import and Library pack, and closed the vision question on generic flows.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T2–T3 on `feat/studio-library-1-library-packs`.
