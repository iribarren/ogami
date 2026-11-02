# Feature: studio-unsaved-work

- **Locator:** `odd/tasks/studio-unsaved-work.md` · Engram topic `odd/studio-unsaved-work/tasks`
- **Issue:** [#360](https://github.com/iribarren/ogami/issues/360) · **Branch:** `fix/studio-unsaved-work-1-route-editors` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-scene-type-lost-steps` (PR #359)

## Objective

Roadmap backlog B7q. Give the other Studio editors the protection the Scene Type editor gained in [`studio-scene-type-lost-steps`](studio-scene-type-lost-steps.md), and close a silent overwrite found while mapping them.

Out of scope: modal dialogs (`ItemDialog`, `SheetFieldDialog` and the like), and `SheetLayoutPanel` and `SheetPointBudgetPanel`, which edit only in modals.

## Problem and why

Reading the code, not yet reproduced; RED tests are the reproduction.

| # | Gap | Effect | Where |
|---|---|---|---|
| G1 | The oracle editors send the live cached `oracles.version`, and compare `dirty` against the live item. After a refetch, a stale draft saves with no 409 and overwrites newer entries, and a clean form reads dirty. If the table leaves the cache, the page swaps the form out and the draft is gone | **Silent overwrite**, draft lost | `TableEditor.tsx:139`, `LikelihoodOracleEditor.tsx:322`, `OracleTableEditorPage.tsx:68` |
| G2 | Leaving a route editor with unsaved changes gives no warning | Draft lost | `FlowEditor`, `TableEditor`, `LikelihoodOracleEditor` |
| G3 | Flow editor: reload after a 409 does not warn or clear "Saved at", and a rename loses "Saved at" | Draft lost, reads as saved | `FlowEditor.tsx:199-223`, `:426-438`, `FlowEditorPage.tsx:121` |
| G4 | The route editors' pages fill from the cache with no wait for a refetch in flight | Saved work looks missing | `FlowEditorPage.tsx:76-78`, `OracleTableEditorPage.tsx:67`, `LikelihoodOracleEditorPage.tsx:67` |
| G5 | Section writes store the answer with no `cancelQueries`, so a read in flight can overwrite a save in the cache | Saved work looks missing | `useOracleWrite`, `useSheetWrite`, `useCheckWrite`, `useImportsWrite`, metadata in `useGameSystems`, restore in `useGameSystemReleases` |
| G6 | `FlowsTab` leaves a refused navigation after create unhandled | Unhandled rejection | `FlowsTab.tsx:99` |
| G7 | Forms in tabs have no guard: `MetadataForm`, `CheckEditor`, `SheetCurrencyPanel`. A `?tab=` change loses the last two, and so does picking another check | Draft lost | `GameSystemPage.tsx:219`, `ChecksTab.tsx:142,341`, `SheetTab.tsx:223` |

`CheckEditor` already keeps the version it read (`ChecksTab.tsx:111`), so G1 does not apply to it.

## Decisions

| Finding | Decision | By |
|---|---|---|
| G1 | Fixed first and in scope of #360, though the issue does not name it: it is the worst loss found. Both oracle editors adopt the Scene Type model: the page fills the form once; the editor holds what it read (`readAt`, baseline) in state and replaces it on its own save and on a reload, together or not at all. Reuse the reload they already have | Parent |
| G2 | Reuse `UnsavedChangesGuard`. A rename's own navigation passes `ignoreBlocker` and does not run if the person has left, as in `SceneTypeEditor` | Parent |
| G3 | Same as `SceneTypeEditor`. The oracle editors have no saved time, and this feature does not add one | Parent |
| G4 | Same as `SceneTypeEditorPage`: wait for a refetch in flight on opening; if it fails, open from the cache | Parent |
| G5 | One `cancelQueries` line in each of the four helpers and two mutations, with no shared factory. `useLibraryPacks` create needs none: its key is new | Parent |
| G6 | Same `try/catch` as `SceneTypesTab` | Parent |
| G7 | `UnsavedChangesGuard` gains an opt-in to also block a change of `tab`, comparing normalized tabs (no tab means Overview), used by `CheckEditor` and `SheetCurrencyPanel`. `MetadataForm` does not use it: it stays mounted on a tab switch. Details for slice 2 are decided when it is planned | Parent |
| Slice 2 | Accepted: two dialogs in a row when two forms are dirty; restoring a release replaces a dirty `MetadataForm`, behind restore's own confirm | Parent |

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. No API change. Slice 1 goes first because G1 can lose data.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/studio-unsaved-work-1-route-editors` | `fix(studio): studio-unsaved-work 1/2 route editors` | T1–T5 | ~1,150: this doc ~100; oracle editors ~550; Flow editor ~280; `cancelQueries` ~130; `FlowsTab` and `renderApp` ~70 |
| 2 | `fix/studio-unsaved-work-2-tab-forms` | `fix(studio): studio-unsaved-work 2/2 forms in tabs` | T6–T9 | ~600: guard option ~75; `MetadataForm` ~200; `CheckEditor` ~240; currency ~75 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | G1, G2, G4: oracle table editor keeps what it read, guarded, and waits for a refetch on opening | delegated writer (Sonnet): 2+ non-trivial files | [x] | |
| T2 | 1 | G1, G2, G4: likelihood oracle editor, the same | delegated writer | [x] | |
| T3 | 1 | G2, G3, G4: Flow editor reaches the Scene Type editor's level | delegated writer | [x] | |
| T4 | 1 | G5: a read in flight cannot overwrite a save in any section | delegated writer | [ ] | |
| T5 | 1 | G6: `FlowsTab` handles a refused navigation | delegated writer | [ ] | |
| T6 | 2 | G7: guard option for a tab change | delegated writer | [ ] | |
| T7 | 2 | G7: `MetadataForm` baseline and guard; Discard ignores the blocker | delegated writer | [ ] | |
| T8 | 2 | G7: `CheckEditor` dirty, guard, and a confirm on picking another check | delegated writer | [ ] | |
| T9 | 2 | G7: `SheetCurrencyPanel` guard | delegated writer | [ ] | |

## Acceptance criteria

- A stale oracle draft saved after a refetch answers 409 and overwrites nothing; a refetch never makes a clean form read dirty or swaps out a form holding a draft.
- Leaving a route editor with unsaved changes asks first; staying keeps the draft, leaving discards it. A saved or untouched form leaves without asking, and a rename save does not prompt.
- Flow editor: reload after a 409 warns and clears "Saved at", and a rename keeps it.
- A route editor opened from a stale cache shows what the server holds.
- In every section, a read in flight during a save cannot replace the saved data in the cache.
- Slice 2: the same leaving rules for `MetadataForm`, `CheckEditor` and `SheetCurrencyPanel`, including a tab change where it loses the form.

## Checks

`make verify` per slice, and the Studio Playwright specs that drive these editors, because `make verify` does not run Playwright (local form: `make -o e2e-seed e2e ARGS=<specs>`). Chrome pass per slice. CI is the full run.

## Progress

- 2026-11-02: Explored; G1 confirmed by reading `TableEditor.tsx:139`, not yet reproduced. RED tests are the reproduction.
- 2026-11-02: T1 done. RED: 7 new tests failed (stale version sent, clean form read dirty by a refetch, form swapped out when its table left the cache, no loading wait, no guard); reload-from-one-read, clean leave, rename and Test roll tests passed at once. `TableEditor` now holds `readAt` and the stored table; the page fills it once.
- 2026-11-02: T2 done. RED: 7 new tests failed, the same gaps as T1; the reload, clean leave, rename and test-answer tests passed at once. The two editors share no new helper.
- 2026-11-02: T3 done. RED: 7 of 8 new tests failed (no guard, no pending-rename mounted check, no reload warning, "Saved at" kept on reload and lost on rename, no refetch wait); the `?node=` no-prompt, clean leave and rename-no-dialog tests passed at once.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer, Chrome pass, native review, PR.
