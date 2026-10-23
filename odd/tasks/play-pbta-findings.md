# Feature: play-pbta-findings

- **Locator:** `odd/tasks/play-pbta-findings.md` · Engram topic `odd/play-pbta-findings/tasks`
- **Issue:** #253 · **Branch:** `fix/play-pbta-findings-1-sheet-journal` · **Status:** slice 1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7e

## Objective

Fix the `preset-pbta` Play findings of issue #253 (P8–P12, P14/P19; [preset-pbta.md](preset-pbta.md#playtest-findings)) after replaying `hearts-and-rapiers` in Chrome to confirm each one. Frontend first; backend only where a finding needs it. P6 moves to B8b and P18 to B7g (user's calls).

## Problem and why

Code map (read-only, 2026-10-23):

- P8: `summarize()` renders a `list` field as `Label: <count>` (`frontend/src/shared/sheet/sheetValues.ts:73-75`), used by `CharactersPanel.tsx:88-93`; the sheet dialog already lists the items.
- P9: `Check::perform()` folds the modifier formula into one int (`backend/src/Play/Domain/GameSystem/Check/Check.php:91`), stored in `CheckContent` and sent as `CheckContentResponse.modifier`; the frontend never sees the formula (`CheckView.php:9-21`), so `JournalEntryView.tsx:216` can only print the number.
- P10: a tag is `{key, label, hint?}` (`ReleaseVersion2.php:74,363-380`; Play `Tag.php:11-18`); every Play form offers all `campaign.tags` (`TagFields.tsx:56-73`); ADR 0018 §15 lets tags apply to NPCs, Characters and Threads with no scoping.
- P11: the hint shows before rolling (`CheckForm.tsx:121`); the result card (`JournalEntryView.tsx:196-227`) only has `CheckContent`, which carries no hint.
- P12: the Climax `check-1` sits in `phaseOpening`, so it runs in a hook scene (`FlowRun::enterHookScene`, `FlowRun.php:985-992`) filed under the phase-opening heading with a "Flow step" line (`Journal.tsx:110-116,184-212`); the Climax sequence scene (Scene 5) then stays empty and reads "Nothing recorded" (`Journal.tsx:97-100`, `groupJournal.ts:20-56`).
- P14/P19: number fields always render `<Input type="number">` (`SheetFields.tsx:273-285`); the five Conditions are `min 0, max 1, default 0`; the preset declares no sheet layout (ADR 0031 exists and renders).
- P6 (moved): a `table` step names one fixed table (`Steps.php:231-235`, `FlowRun.php:266-270`); a per-scene-type table is a flow-step contract change.

Chrome replay (2026-10-23, campaign "Chrome Replay H&R 1" on v4, account `pbta-replay@ogami.test`; no console errors): P8, P9, P10, P11 and P14/P19 confirmed as logged. P12 partly changed: the journal already heads the roll "Phase opening — Act III: Climax" with "Flow step · Confront the villain", and the following Swashbuckle scene reads "Nothing recorded in this scene yet" because it is the current scene; the Climax step then shows "Next: Scene: Swashbuckle". The cause is content (the check in `phaseOpening`), not the journal. New: P20, the Character step shows a derived field's raw formula (`{angry} + {frightened} + …`) under "Conditions marked" (`SheetFields.tsx:176-178`, by design since `3e075c2`); not in scope unless the user adds it.

## Decisions

| Item | Decision |
|---|---|
| P6 | Out of scope: moved to B8b `rules-flow-choices`, which already opens the flow-step contract, the Studio flow editor and the upgrade planner (user, 2026-10-23) |
| P18 | Out of scope: B7g `release-api-followups` |
| P8 | The summary lists the first items joined with ", ", cut like text fields (~40 characters), with "+N" for the rest; an empty list keeps today's output |
| P9 | Record the modifier's breakdown on the check journal entry at roll time: the formula text and each referenced sheet value (key, label, value). Optional fields: entries stored before this feature read without a breakdown. The result card shows the breakdown next to the modifier |
| P11 | Store the check's hint on the entry at roll time, with P9's change (not a frontend lookup, which would change after a release upgrade) (user, 2026-10-23) |
| P10 | Optional `appliesTo` on each tag (`character`, `npc`, `thread`; absent = all). Additive contract change with an ADR 0018 amendment; Studio validates that tags used by flow steps match the target kind; Play offers and accepts only tags that apply (user, 2026-10-23) |
| P12 | Content only: move the Climax check into the Climax scene in v5 (user, 2026-10-23). The planned journal code change was dropped after the replay: the hook heading already names the phase opening and the empty scene is the current one |
| P14/P19 | Code: a `number` field with `min 0` and `max 1` renders as a toggle (same draft values `0`/`1`, no API change). Content: a sheet layout in v5 (Stats and Conditions in columns) (user, 2026-10-23) |
| Content | `hearts-and-rapiers` v5 published from Studio in Chrome (Climax check, sheet layout, scoped tags), re-exported to `backend/presets/` (user, 2026-10-23) |

## Constraints

- Contract changes only for P10, additive (old releases stay valid). Ask before any other contract change.
- Context boundaries: Play reads releases only through its anti-corruption layer.
- Planning heuristic only: the slice ceiling is ~1,700 changed lines including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/play-pbta-findings-1-sheet-journal` | `fix(play): play-pbta-findings 1/4 sheet summary and condition toggles` | T1, T2, T4 | ~450: P8 ~120, P14 ~250, doc ~80 |
| 2 | `fix/play-pbta-findings-2-check-breakdown` | `fix(play): play-pbta-findings 2/4 modifier breakdown and move hint` | T5–T6 | ~1,300: domain and persistence ~450, API and generated types ~400, result card ~300 |
| 3 | `feat/play-pbta-findings-3-tag-scope` | `feat(studio): play-pbta-findings 3/4 tags scoped per entity` | T7–T8 | ~1,500: contract and Studio ~700, Play ~400, generated ~300, ADR ~80 |
| 4 | `fix/play-pbta-findings-4-preset-v5` | `fix(presets): play-pbta-findings 4/4 hearts-and-rapiers v5` | T9–T10 | ~300; folded into slice 3 if it fits |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; Chrome replay of every finding | parent, inline (doc); Chrome worker (delegated) | [x] | |
| T2 | 1 | P8: list fields in the character summary | writer (delegated) | [x] | |
| T3 | — | P12: dropped after the replay (content fix in T9) | — | [x] | — |
| T4 | 1 | P14/P19: 0..1 number fields as toggles | writer (delegated) | [x] | |
| T5 | 2 | P9 + P11 backend: breakdown and hint recorded on `CheckContent` at roll time; response and OpenAPI | writer (delegated) | [ ] | |
| T6 | 2 | P9 + P11 frontend: breakdown and hint on the result card | writer (delegated) | [ ] | |
| T7 | 3 | P10 contract and Studio: `appliesTo` on tags (schema, validation, Studio tag dialog, ADR 0018 amendment) | writer (delegated) | [ ] | |
| T8 | 3 | P10 Play: translator, `Tag`, `ItemRules`, `TagView`, `TagFields` filter; upgrade planner for tags that stop applying | writer (delegated) | [ ] | |
| T9 | 4 | v5 content in Studio (Chrome): Climax check into the Climax scene (P12), sheet layout, scoped tags; re-export; preset tests | Chrome worker + writer (delegated) | [ ] | |
| T10 | 4 | Close: roadmap B7e done, P6 on B8b, issue #253 ticked | parent, inline (doc) | [ ] | |

## Applicable checks

- Test first in each task with a runnable deterministic test: Vitest for T2–T4 and T6, PHPUnit unit + API for T5, T7 and T8, Studio release fixtures for T7.
- T4 changes the hero form, so `frontend/e2e/play-hearts-and-rapiers.spec.ts` selectors may change.
- `make verify` once per slice by the writer; CI runs the full suites.

## Progress

- 2026-10-23: code map done; P6 moved to B8b, P10 approved as an additive contract change, P11 hint stored at roll time, v5 content approved (user's calls). Feature doc created (10 tasks, four slices).
- 2026-10-23 T1: Chrome replay done (see Problem and why); P12's journal code change dropped (content only).
- 2026-10-24 T2: `summarize()` lists list items (<=40 characters joined, first item cut with an ellipsis, ` +N` for the rest; empty keeps `Label: 0`). RED: 3 of 4 new `sheetValues.test.ts` tests failed (one-item, many-items, long-item; the empty-list test passed on first run, it pins today's output); GREEN: 43 tests in `src/shared/sheet` + `src/play/characters`.
- 2026-10-24 T4: a `number` field with `min 0`, `max 1` and a `'0'`/`'1'` draft renders as a checkbox in `SheetFields` (same draft values, no API change); a value outside 0..1 or any other range stays a number input. RED: 3 toggle tests failed (the legacy `2` and other-range tests passed on first run, they pin the fallback); GREEN: `SheetFields.test.tsx` 11/11. `play-hearts-and-rapiers.spec.ts` does not touch Conditions, so unchanged.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1 writer (T2, T4).
