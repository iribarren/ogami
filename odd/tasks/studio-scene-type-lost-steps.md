# Feature: studio-scene-type-lost-steps

- **Locator:** `odd/tasks/studio-scene-type-lost-steps.md` · Engram topic `odd/studio-scene-type-lost-steps/tasks`
- **Issue:** [#310](https://github.com/iribarren/ogami/issues/310) · **Branch:** `fix/studio-scene-type-lost-steps-1-unsaved-work` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `night-court-followups` (PRs #357–#358)

## Objective

Roadmap backlog B7o. Three Scene Types a Claude in Chrome worker reported as saved while authoring Night Court had no steps when reopened ([S21](preset-vtm-chronicle.md#studio-findings)). Close every path by which an author can lose steps, or see saved steps as missing, in the Scene Type editor.

Out of scope: tall step cards ([#356](https://github.com/iribarren/ogami/issues/356), B7p), an unsaved-changes guard on the other Studio editors, and refusing empty Scene Types at publish.

## Problem and why

The worker's network log is gone, so S21's exact cause cannot be proved. Reading the code found no path that loses steps on the server: a save replaces one item as sent, serialization is 1:1, and stale writes are refused twice (`expectedVersion` and the Doctrine `version` column). The client has five paths that fit the symptom:

| # | Path | Effect | Where |
|---|---|---|---|
| C1 | Create from the list stores empty step lists and stays there; the dialog closing reads as a save. `FlowsTab` already opens the editor | Never had steps | `SceneTypesTab.tsx:46` |
| C2 | Leaving the editor with unsaved steps gives no warning: nothing in `frontend/src` blocks navigation | Steps lost | `SceneTypeEditorPage.tsx:28` |
| C3 | Reload after a 409 replaces the draft with no warning, and the old "Saved at" comes back | Steps lost, reads as saved | `SceneTypeEditor.tsx:156` |
| C4 | A GET in flight during a save can overwrite the saved narrative in the cache | Saved steps look missing | `useGameSystemNarrative.ts:83` |
| C5 | The form fills once, from the cache; a refetch with newer data never reaches an open form, for example in a second tab | Saved steps look missing | `SceneTypeEditorPage.tsx:57` |

## Decisions

| Finding | Decision | By |
|---|---|---|
| C1 | After create, open the new Scene Type's editor, as `FlowsTab` does | Parent |
| C2 | A shared guard component (router blocker, dialog, `beforeunload`) used only by the Scene Type editor for now. It blocks while the draft differs from the baseline. Other editors get a `follow-up` issue | Parent |
| C2 | A rename's own navigation after a save ignores the blocker, and does not run if the person has already left the page | Parent |
| C3 | Same warning as `LikelihoodOracleEditor` next to the reload button; a reload clears "Saved at" | Parent |
| C4 | Cancel the narrative query before writing the save's answer to the cache | Parent |
| C5 | On opening, wait for a refetch in flight before filling the form. If it fails, open from the cache: a save still answers 409. Under the 30 s stale time no refetch runs, so another tab's save shows up only as that 409 | Parent |
| Two tabs | Test only: an API test that two saves of different Scene Types at one version answer 200 then 409, and the first keeps its steps | Parent |

## Slice plan

Forecasts include tests and docs. Hard ceiling ~1,700 per slice. No API change.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/studio-scene-type-lost-steps-1-unsaved-work` | `fix(studio): studio-scene-type-lost-steps 1/1 unsaved work` | T1–T5 | ~550: this doc ~80; C1 ~40; C2 ~220; C3 ~50; C4 and C5 ~120; API test ~40 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | C1: create opens the editor | delegated writer (Sonnet): 2+ non-trivial files | [x] | |
| T2 | 1 | C2: guard unsaved changes on leaving, and leaving during a pending save | delegated writer | [x] | |
| T3 | 1 | C3: reload warns and clears "Saved at" | delegated writer | [x] | |
| T4 | 1 | C4 and C5: a stale read neither overwrites a save nor fills the form | delegated writer | [x] | |
| T5 | 1 | Two-tab API regression test | delegated writer | [ ] | |

## Acceptance criteria

- Creating a Scene Type lands in its editor, with the steps panel in view.
- Leaving with unsaved changes asks first, through a link or the browser; staying keeps the draft, leaving discards it. A saved or untouched form leaves without asking.
- A rename save does not prompt, and leaving during a pending save keeps what was saved and does not pull the person back.
- The reload after a conflict says it replaces unsaved edits, and shows no "Saved at" afterwards.
- A GET in flight during a save cannot replace the saved narrative in the cache.
- An editor opened from a stale cache shows the steps the server holds.

## Checks

`make verify`. A browser pass on the guard in the real router, because Vitest cannot exercise `beforeunload`.

## Progress

- 2026-11-02: Explored; S21's exact cause cannot be proved without the worker's network log. Five client paths found, none on the server. RED tests are the reproduction.
- 2026-11-02: T1 done; RED: the create test found no editor form and the URL on the list. Create now opens the editor; the `selected` state is gone.
- 2026-11-02: T2 done; RED: 5 of 8 new tests failed with no guard (no dialog; the pending-save test fails without the mounted check). New `UnsavedChangesGuard` on the Scene Type editor only.
- 2026-11-02: T3 done; RED: both new tests failed (old "Saved at" stayed after Reload; no warning). The sentence matches `LikelihoodOracleEditor`.
- 2026-11-02: T4 done; RED: the held-GET test kept version 5 in the cache, and the two stale-cache tests found no loading text and the form filled from the stale cache. The filled-once test passes at once, as a guard.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer, then native review, browser pass, PR.
