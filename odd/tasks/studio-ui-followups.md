# Feature: studio-ui-followups

- **Locator:** `odd/tasks/studio-ui-followups.md` · Engram topic `odd/studio-ui-followups/tasks`
- **Issue:** #249 · **Branch:** `fix/studio-ui-followups-1-followups` · **Status:** in progress: slice 1 of 1
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-ui-polish` (PRs #240–#248)

## Objective

Fix the follow-ups `studio-ui-polish` logged (roadmap B7c): the two slice 9 review warnings ([Reviews row 9](studio-ui-polish.md#reviews)) and the T14 Chrome pass follow-ups (its last T14 Progress line).

Out of scope: backend and API changes; new Studio screens; restyling beyond the controls named below.

## Problem and why

- `splitWarning` (`studio/validationLocation.ts:121-126`) splits at the first `': '`, so a pathless warning containing one loses its first words and gets a misleading Go to field (Validation tab `ValidationTab.tsx:285`, Publish dialog `PublishDialog.tsx:248`).
- The Publish dialog's `onOpenAutoFocus` (`PublishDialog.tsx:67-74`) always prevents the default and focuses the notes `textarea` only when it exists; without it focus stays outside the modal.
- No component test shows the check Modifier or band preview at the sandbox defaults (`ChecksTab.tsx:611-619`, `sampleDefaults` in `checks/checkSandbox.ts:108`), nor with the sheet read pending or failed.
- The "Used by" lines in the Oracles (`oracles/OraclesTab.tsx:71`), Trackers & tags and Scene Types lists (`narrative/NarrativeTab.tsx:176`) and the Publish dialog errors list (`PublishDialog.tsx:211-235`) show raw release paths instead of `locateError` locations.
- The check editor title reads "Edit nerve" (the key, `ChecksTab.tsx:716`) instead of the label.
- The New GameSystem dialog (`NewGameSystemDialog.tsx`) has no Close button or Cancel.
- The Sheet tab's derived previews (`sheet/SheetPreview.tsx`, `useSheetSamples`) do not read untyped number fields at the defaults the check sandbox uses.

## Decisions

| Question | Decision |
|---|---|
| `splitWarning` | Split only when the prefix before the first `': '` tokenizes as a release path (same tokenizer as `locateError`); otherwise the whole warning is the reason with no path and no Go to field |
| Publish focus | Prevent the default only when the notes field exists; otherwise let Radix focus as usual |
| Check editor title | Visible heading shows the label ("Edit Nerve"); the form's accessible name stays `Edit <key>`, so existing queries keep working |
| "Used by" | Each path rendered as its `locateError` group and trail in words; the raw path stays available (e.g. `title` or a Technical paths detail) |
| Accessible names | Unchanged except the new Close and Cancel controls of the New GameSystem dialog |

## Constraints

- Frontend only; no API or generated file changes.
- Accessible names unchanged except the new controls; icon-only buttons have `aria-label` and a tooltip.
- Planning heuristic only: the slice ceiling is ~1,700 changed lines.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/studio-ui-followups-1-followups` | `fix(studio): studio-ui-followups 1/1 Studio follow-ups` | T1–T5 | ~700 (review warnings and tests ~150; preview tests ~120; "Used by" and Publish errors locations ~200; title, dialog controls ~100; Sheet preview defaults ~130) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent, inline (doc only) | [x] | |
| T2 | 1 | Review warnings: `splitWarning` splits only on a path prefix; Publish dialog falls back to the default focus without notes | writer (delegated) | [x] | |
| T3 | 1 | Check Modifier and band preview component tests at the sandbox defaults, with the sheet read pending and failed | writer (delegated) | [x] | |
| T4 | 1 | Readable "Used by" lines (Oracles, Trackers & tags, Scene Types) and Publish dialog errors through `locateError` | writer (delegated) | [x] | |
| T5 | 1 | Check editor title with the label; Close and Cancel in the New GameSystem dialog; Sheet derived previews read untyped number fields at the sandbox defaults | writer (delegated) | [x] | |

## Applicable checks

- Test first for every task (all are deterministic component or unit behaviour); `make verify` once by the writer; CI runs the full suites.

## Progress

- 2026-10-23: feature doc created (5 tasks, one slice); issue #249.
- 2026-10-23: T2 done. `splitWarning` splits only when the prefix is a release path with a known head. RED: two new tests failed (`Note: ...` got a path `Note`); GREEN after. Publish focus: the notes textarea is always rendered, so the no-notes branch is unreachable in the app; the focus-inside-dialog test pins behaviour (no RED).
- 2026-10-23: T3 done. Three `ChecksTab` tests: Modifier and band previews at the sandbox default (pow default 7 gives 8 and 14), and at 0-based fallback with the sheet pending and failed. Pin existing behaviour: no RED, no bug found.
- 2026-10-23: T4 done. `UsedBy` (exported from `NarrativeTab`, reused by `OraclesTab`) takes optional `names` and renders each path via `describeLocation` (new in `validationLocation.ts`) with the raw path as `title`; Trackers & tags and both Oracle lists pass `useNames`. `PublishErrors` renders each error through `WarningText` (reason plus location). RED: Oracles/Narrative/Publish tests with the new text failed (7 failures), GREEN after. Flows list stays raw (not requested); delete-error messages unchanged.
- 2026-10-23: T4 follow-up. Scene Types list (`narrative/sceneTypes/SceneTypesTab.tsx`) passes `useNames` to `UsedBy`; RED: the list and delete-description assertions failed with raw paths, GREEN after. Flows left raw (not requested).
- 2026-10-23: T5 done. Check editor heading shows `Edit <label>` (key when the label is empty), form name stays `Edit <key>`; New GameSystem dialog has a Close button and Cancel (tests in `StudioHomePage.test.tsx`); `useSheetSamples` starts an untyped number at `sampleDefaults` (default held within its range). RED: the Sheet preview test (Power min 4 showed 0, not 4), the dialog Close/Cancel test and the heading test failed; GREEN after. The empty-label heading test pins existing behaviour (no RED).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T2–T5 are implemented; the parent records commits, runs the native review, then pushes and opens the PR.
