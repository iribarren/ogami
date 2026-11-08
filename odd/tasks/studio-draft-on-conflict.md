# Feature: studio-draft-on-conflict

- **Locator:** `odd/tasks/studio-draft-on-conflict.md` · Engram topic `odd/studio-draft-on-conflict/tasks`
- **Issue:** [#363](https://github.com/iribarren/ogami/issues/363) · **Branch:** `fix/studio-draft-on-conflict-1-checks-currency` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** follow-up of `studio-unsaved-work` (#360, PR #362); roadmap B7r

## Objective

Two Studio forms still drop typed work with no choice: `CheckEditor` on a 409 save, and `SheetCurrencyPanel` when a refetch brings a different stored currency. Move both to the Scene Type model: keep the draft and the version it read, warn, and replace the draft only when the person reloads.

## Problem and why

- `CheckEditor.commit` (`frontend/src/studio/checks/ChecksTab.tsx:854`) calls `onConflict`, and `CheckList.onConflict` (`:219`) runs `reload()` at once, which bumps `opened` and remounts the editor (`key={selected.opened}`): the draft is gone and only a list notice remains. A 409 on a move (`:369`) also goes through `onConflict`, so it drops the open editor's draft too.
- `SheetCurrencyPanel` (`frontend/src/studio/sheet/SheetCurrencyPanel.tsx:40-49`) resets its inputs whenever the stored currency changes, and saves with the live `sheet.version` (`:84`): an unsaved edit is replaced by a refetch.
- The reference model is `SceneTypeEditor` (`frontend/src/studio/narrative/sceneTypes/SceneTypeEditor.tsx:160-186`, `:297-313`): the draft and `readAt` change together or not at all; a 409 shows "Reloading replaces your unsaved edits…" with "Reload the latest version"; a failed reload or a gone item keeps the draft with a note, and the old version, so a save still conflicts.

## Decisions

| Item | Decision | By |
|---|---|---|
| Check save 409 | The editor keeps its draft and shows, in its action area, the 409 message, "Reloading replaces your unsaved edits with the latest stored check." and a "Reload the latest version" button. Only that button reloads (refetch, then reopen the editor on the fresh checks). No list-level notice for a save 409 | Parent |
| Check reload fails | The draft and the old version stay; the editor shows "Couldn't reload the latest version. Try again." | Parent |
| Check gone on reload | The editor stays open with the draft and the old version, and notes "The check `<key>` no longer exists. Your changes are kept: Duplicate saves them as a new check." | Parent |
| Move 409 | Refetch the checks so the list is current, with the list notice as today ("… The latest version was loaded." or "Try again"). The open editor is reopened on the fresh checks only when it has no unsaved edits; with unsaved edits it keeps its draft and version, and its next save conflicts and offers the reload | Parent |
| Currency version | The panel keeps `readAt` and `base` (the stored currency its inputs started from). While the stored currency equals `base`, `readAt` follows the live `sheet.version` (other sheet edits do not make a currency save conflict, as today) | Parent |
| Currency changed while editing | If the stored currency changes away from `base`: with no unsaved edit (inputs equal `base`), the inputs, `base` and `readAt` follow the stored value. With an unsaved edit, the draft, `base` and `readAt` stay, and the panel says "The stored currency changed since you started editing. Reloading replaces your edit with it." with "Reload the latest version"; a save then answers 409 | Parent |
| Currency reload | "Reload the latest version" (the existing `ReloadLatest`, before or after a 409) refetches, then replaces the inputs, `base` and `readAt` with the refetched sheet. A failed reload keeps the draft and says so | Parent |
| Currency save and removal | A successful save or removal sets `base` and `readAt` from its answer. `dirty` compares the inputs with `base` (unit trimmed) | Parent |
| Scope | Frontend only. No API change. Accessible names unchanged except the new notes | Parent |

## Constraints

- No API or OpenAPI change.
- `UnsavedChangesGuard` stays on both forms and reads the new `dirty`.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/studio-draft-on-conflict-1-checks-currency` | `fix(studio): studio-draft-on-conflict 1/1 keep drafts on a conflict` | T1–T2 | ~700: this doc ~90; T1 ~350; T2 ~250 |

## Tasks

| ID | Task | Route | Done | Commit |
|---|---|---|---|---|
| T1 | `CheckEditor` keeps its draft on a 409 and reloads only on request; a move 409 keeps an editor with unsaved edits | delegated: slice runs under one writer (2+ non-trivial files) | [x] | `acef7011` |
| T2 | `SheetCurrencyPanel` keeps `readAt` and `base`, keeps an unsaved edit when the stored currency changes, and replaces it only on reload | delegated: slice runs under one writer | [x] | `d574a0bb` |
| T3 | Review findings: `reload` decides on the current selection, not a stale closure; a save's answer moves `base`/`readAt` before the cache render (no false "currency changed" note); tests for the `unreadable` result and a move 409 closing a clean editor on a gone check | delegated: review fixes in 2 files plus tests | [x] | |

## Applicable checks

- `make verify` once at the end; CI runs the full suites and e2e.
- Focused Vitest while iterating (`ChecksTab.test.tsx`, `SheetCurrencyPanel.test.tsx`), test first.

## Progress

- 2026-11-08: Explored `CheckList`/`CheckEditor`, `SheetCurrencyPanel`, `ReloadLatest` and the Scene Type model. One slice, delegated writer.
- 2026-11-08: T1 done. RED: 6 new `ChecksTab.test.tsx` tests failed on the old code (immediate reload); GREEN: 121/121. The editor also notes an unreadable refetched check and keeps its draft.
- 2026-11-08: T2 done. RED: 3 new `SheetCurrencyPanel.test.tsx` tests failed on the old code (no note, edit dropped); GREEN: `src/studio/sheet` 385/385. `ReloadLatest.onLoaded` now receives the refetched sheet.
- 2026-11-08: T3 done. RED: 2 new `ChecksTab.test.tsx` tests (editor opened during the refetch closed; misleading list notice on `unreadable`) and 1 `SheetCurrencyPanel.test.tsx` test (edit equal to the stored currency still showed the note) failed on the old code; the gone-check close, the editor `unreadable` note and the post-save no-flash tests passed at once (coverage and regression guards). GREEN: `src/studio/sheet` + `ChecksTab.test.tsx` 512/512.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1, T1–T2 | `review-71d961c61104b32a` | Approved and acknowledged | 2 warnings, 1 suggestion: `reload` read `selected` and `editorUnsaved` from a stale closure, so a check opened during the refetch could be closed; a successful currency save flashed the "stored currency changed" note until the mutate `onSuccess` ran (and kept it for good if that callback was skipped); no test for the `unreadable` result or a move 409 closing a clean editor on a gone check. All fixed in T3 | [#377](https://github.com/iribarren/ogami/pull/377) |

## Next step

Review T3, then close the feature.
