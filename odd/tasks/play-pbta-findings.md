# Feature: play-pbta-findings

- **Locator:** `odd/tasks/play-pbta-findings.md` · Engram topic `odd/play-pbta-findings/tasks`
- **Issue:** #253 · **Branch:** `fix/play-pbta-findings-4-preset-v5` · **Status:** done
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
| Content | `hearts-and-rapiers` v5 (Climax check in the Climax scene opening, sheet layout in two columns, `villain`/`ally`/`crush` scoped to NPCs) edited in `backend/presets/hearts-and-rapiers.json`, published with `make presets` and re-exported in canonical form; Studio in Chrome was planned, but the Chrome extension was not connected (user, 2026-10-24) |

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
| 4 | `fix/play-pbta-findings-4-preset-v5` | `fix(presets): play-pbta-findings 4/4 hearts-and-rapiers v5` | slice 3 findings, T9–T10 | ~400: findings ~150, preset and its tests ~200, doc ~50 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; Chrome replay of every finding | parent, inline (doc); Chrome worker (delegated) | [x] | 7bdfe4a |
| T2 | 1 | P8: list fields in the character summary | writer (delegated) | [x] | a6c200a |
| T3 | — | P12: dropped after the replay (content fix in T9) | — | [x] | — |
| T4 | 1 | P14/P19: 0..1 number fields as toggles | writer (delegated) | [x] | 65d3f43 |
| T5 | 2 | P9 + P11 backend: breakdown and hint recorded on `CheckContent` at roll time; response and OpenAPI | writer (delegated) | [x] | 177dc27 |
| T6 | 2 | P9 + P11 frontend: breakdown and hint on the result card | writer (delegated) | [x] | 8f51169 |
| T7 | 3 | P10 contract and Studio: `appliesTo` on tags (schema, validation, Studio tag dialog, ADR 0018 amendment) | writer (delegated) | [x] | fa54158 |
| T8 | 3 | P10 Play: translator, `Tag`, `ItemRules`, `TagView`, `TagFields` filter; upgrade planner for tags that stop applying | writer (delegated) | [x] | 7d4a576 |
| T9 | 4 | v5 content: Climax check into the Climax scene (P12), sheet layout, scoped tags; `make presets`, canonical export; preset tests | writer (delegated) | [x] | a98bb61 |
| T10 | 4 | Close: roadmap B7e done, P6 on B8b, issue #253 ticked | parent, inline (doc) | [x] | this closing commit |

## Applicable checks

- Test first in each task with a runnable deterministic test: Vitest for T2–T4 and T6, PHPUnit unit + API for T5, T7 and T8, Studio release fixtures for T7.
- T4 changes the hero form, so `frontend/e2e/play-hearts-and-rapiers.spec.ts` selectors may change.
- `make verify` once per slice by the writer; CI runs the full suites.

## Progress

- 2026-10-23: code map done; P6 moved to B8b, P10 approved as an additive contract change, P11 hint stored at roll time, v5 content approved (user's calls). Feature doc created (10 tasks, four slices).
- 2026-10-23 T1: Chrome replay done (see Problem and why); P12's journal code change dropped (content only).
- 2026-10-24 T2: `summarize()` lists list items (<=40 characters joined, first item cut with an ellipsis, ` +N` for the rest; empty keeps `Label: 0`). RED: 3 of 4 new `sheetValues.test.ts` tests failed (one-item, many-items, long-item; the empty-list test passed on first run, it pins today's output); GREEN: 43 tests in `src/shared/sheet` + `src/play/characters`.
- 2026-10-24 T4: a `number` field with `min 0`, `max 1` and a `'0'`/`'1'` draft renders as a checkbox in `SheetFields` (same draft values, no API change); a value outside 0..1 or any other range stays a number input. RED: 3 toggle tests failed (the legacy `2` and other-range tests passed on first run, they pin the fallback); GREEN: `SheetFields.test.tsx` 11/11. `play-hearts-and-rapiers.spec.ts` does not touch Conditions, so unchanged.

- 2026-10-24 R3-001 (slice 1 review): `summarize()` skips blank (whitespace-only) list items; a list of only blank items reads `Label: 0`. RED: the new blank-items test failed (the 40/41-character boundary test passed on first run, it pins today's behaviour); GREEN: `sheetValues.test.ts` 6/6.

- 2026-10-24 T5: `CheckContent` records `hint`, `modifierFormula` and `modifierTerms` (`{reference, label, value}`, one per formula reference, labels from the release sheet and tracker names via `ReferenceLabels`); both handlers record them; legacy entries read `null`/`null`/`[]`; malformed values fail closed. No real RED: the implementation was written before its tests; the one failure seen was a wrong sum in my own test. GREEN: `tests/Unit/Play` + `tests/Integration/Play`, including an API test for a check with and without a modifier and one for a legacy row.
- 2026-10-24 T6: the check card shows the stored hint and the modifier breakdown (`Hands (4) + Edge (2)`), also at a zero modifier when a formula exists; legacy entries render as before. RED: 3 of 4 new `JournalEntryView.test.tsx` tests failed (the legacy test passed on first run, it pins today's output); GREEN: `src/play` + `src/shared/sheet` 528 tests, `tsc` clean.

- 2026-10-24 R3-001..003 (slice 2 review): the card's `explainModifier` replaces every `{...}` token of the formula and lists the terms (`Hot (2), Edge (1)`) when one has no matching term; evaluation reads every formula reference (no short-circuit), so the breakdown cannot diverge, now pinned by evaluation-failure rows (`0 * {ghost}`, `min()`, repeated reference); a handler test asserts the hint, formula and terms the flow-step check records. RED: only the unmatched-reference card test failed; the PHP tests passed first run (no real RED, they pin existing behaviour); GREEN: `CheckTest` + `CompleteFlowStepCheckHandlerTest` 163, `JournalEntryView.test.tsx` 27.
- 2026-10-24 R3-001..002 (slice 3 review): `TagFields` still lists a held Tag that is not offered but marks it "(no longer offered)" and its comment now says the player must untick it (the API refuses it); the Studio draft reads an `appliesTo` with a repeated kind as unreadable and counts distinct kinds when deciding the scope is every kind. RED: the new `TagFields` test and the 3 new `narrativeDraft` tests failed first; GREEN: `src/play/lists` + `src/studio/narrative` 563 passed.
- 2026-10-24 T9 (v5 content): the Climax confrontation (`check-1`) is the `sceneOpening` of the Climax's Swashbuckle scene (P12), the sheet has 2 columns (Identity and Progress span 2, Stats and Conditions side by side), and the `villain`, `ally` and `crush` tags apply to NPCs only. The local database has a Studio draft for `hearts-and-rapiers`, so `make presets` refuses the new file there (ADR 0028); the file was published and exported on a throwaway database (`app:gamesystem:publish`, then `app:gamesystem:export`, `--if-changed` reports it unchanged) so it is the canonical export. RED: `HeartsAndRapiersPresetFlowTest` failed on the v4 file (phaseOpening, no scene) and passes on v5; the `PresetsTest` layout and tag scope assertions were written after the file and have no separate RED; GREEN: both 36 tests.

- 2026-10-24 T7: optional `appliesTo` on a release tag (non-empty, unique `character`/`npc`/`thread`; `null` reads as absent and the canonical form omits it); `v2.schema.json` agrees; `Effects::tags` takes the target kind (`createNpc` npc, `createThread` thread, `pick` and condition `count` by their `of`) and `Catalog::tagAppliesTo` refuses a tag outside its scope at the tag's path (no other step kind carries tags); the Studio tag dialog has an "Applies to" group (none or all ticked sends no field); ADR 0018 §15 addendum. The Studio API passes `definition` through, so no OpenAPI change. RED: the new `ReleaseContentTagScopeTest` failed on `appliesTo: unknown property` before the implementation (the schema agreement rows were added with it); GREEN: `tests/Unit/Studio` 1,701, `tests/Integration/Studio` 657, `src/studio` Vitest 1,296.

- 2026-10-24 T8: Play reads `appliesTo` into `Tag` (`list<ItemKind>`, empty = every kind, `isFor()`; a malformed scope is an `InvalidGameSystemRelease` with its path); `ItemRules::assertInRelease` takes the kind and throws the new `TagNotApplicable` (422 like an unknown tag, mapped in the three controllers incl. promote); `TagView`/`TagResponse` expose `appliesTo` (nullable); `TagFields` offers only the tags that apply to the form's kind and still lists a held one that does not; the upgrade planner's `keptTags`/`droppedTags` take the kind, so a tag that stops applying drops like a removed one (same preview wording, applied on upgrade); promoting an NPC keeps on the Character only the tags that apply to Characters. RED: translator scope tests failed (`Tag` took no scope) and the new domain test errored on the missing classes before the implementation; the planner, handler and API tests passed first run once the code existed (no real RED); GREEN: `tests/Unit/Play` + `tests/Integration/Play` 2,672 + 1,002, `src/play` Vitest. `make api` regenerated `openapi.json` and `schema.d.ts` in their own commit.
- 2026-10-24 T10: slice 4 PR #264; roadmap B7e done, P6 added to B8b, P20 follow-up #265 (B7i), issue #253 ticked. Local dev DB stays at v4 (it holds a Studio draft, so `make presets` refuses the key); publish v5 from Studio to play it locally.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-ac9260f5b4d50984` (medium) | 7bdfe4a, a6c200a, 65d3f43 | Approved, acknowledged. R3-001 (blank list items summarise as "Gear: , "; no 40-character boundary test, `sheetValues.ts:68-83`): fixed in slice 2 | [#261](https://github.com/iribarren/ogami/pull/261) |
| 2 | `review-8e36c20cc3a5f8aa` (medium) | 59bcccb, 177dc27, 8f51169 | Approved, acknowledged. R3-001 (warning: the breakdown re-reads every formula reference after evaluation, no test for a formula with unresolvable references, `Check.php:94`), R3-002 (no test asserts the content the flow-step check path records, `CompleteFlowStepHandler.php:289`), R3-003 (the card's token replacement relies on the formula text spelling references like the term tokens, no test for non-canonical input, `JournalEntryView.tsx:244-251`): fixed in slice 3 | [#262](https://github.com/iribarren/ogami/pull/262) |
| 3 | `review-a15c22b9a05ea148` (medium) | 7727405, fa54158, 7d4a576, 090694d, 3c9d04e | Approved, acknowledged. R3-001 (`TagFields` keeps a held tag that no longer applies and its comment promises saving keeps it, but the API refuses it with a 422, `TagFields.tsx:50-85`), R3-002 (the Studio draft's `scopeOf` accepts duplicate kinds and `labelledToItem` compares the length, not the distinct kinds, `narrativeDraft.ts:219-252`): fixed in slice 4 | [#263](https://github.com/iribarren/ogami/pull/263) |
| 4 | none (declined) | 053e93f, a98bb61 | Native review offered at medium risk; the user chose "Skip this time" (slice 3 findings and preset content only). Late findings: none from review; P20 (Chrome replay, raw derived formula) is follow-up [#265](https://github.com/iribarren/ogami/issues/265), roadmap B7i | [#264](https://github.com/iribarren/ogami/pull/264) |

## Next step

None. Every finding is fixed or tracked: P6 in B8b, P18 in B7g, P20 in #265 (B7i).
