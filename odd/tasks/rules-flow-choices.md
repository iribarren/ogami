# Feature: rules-flow-choices

- **Locator:** `odd/tasks/rules-flow-choices.md` · Engram topic `odd/rules-flow-choices/tasks`
- **Issue:** #285 · **Branch:** `feat/rules-flow-choices-5-preset` · **Status:** slice 5 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B8b ([ADR 0042](../../docs/adr/0042-flow-choices-check-sets-and-scene-tables.md); `preset-pbta` findings [S11, S12](preset-pbta.md#studio-findings), [P4–P6](preset-pbta.md#playtest-findings))

## Objective

A `choice` option and a `prompt` step can change a Character's sheet, a `check` step can offer a set of checks the player picks from, and a `table` step can roll a different table per scene type. Every layer: contract and publisher, Play's translator, Play (`choice`, `prompt`, `check` and `table` steps), the Studio flow editor and in-use guards, and the release upgrade planner. Then `hearts-and-rapiers` uses them, re-exported into `backend/presets/`, and one scene is played.

## Problem and why

- Sheet effect placement is a `$sheet` flag, true only for `character` step effects, check outcomes and push costs: Studio `Version2/Steps.php:114,331,376`, `Version2/Effects.php:206-209`; Play `GameSystemReleaseTranslator.php:663,726,1614,1652-1653`.
- Play applies a sheet effect to the result's `characterId` (`FlowRun.php:1146-1167`); choice and prompt results carry none (`StepResult.php`), and `refuseFieldsOtherThan` (`CompleteFlowStepHandler.php:301-303`) refuses it on those kinds. A skip finishes without a Character (`FlowRun.php:474-484`).
- A `check` step binds one check key everywhere: schema `v2.schema.json:755`, `Steps::check` :304-338, translator `checkStep` :715-735, `CheckStep`, `FlowRun` (`completeStep` :329-342, `resolvePendingCheck` :405-421, `pushable` :913, `checkDifficulty` :447, `checkView` :737, `assertCanRoll` :939), handler :284-293, `FlowStepResponse.php:107`, `PendingCheck` (no check key; JSON in `CampaignFlowRunType.php:30,98,162`), `DraftReferences::checkPathsTo`/`bandPathsTo` :296-331.
- A `table` step names one table: schema :864, `Steps::table` :231-265, `StepParts::rolledTable` :212-215 (→ `FlowReferences::reach` :196-263), `StepEffects::of`, translator :675, `FlowRun.php:296-305`, handler :202-207, `FlowStepResponse.php:105`, `DraftReferences::oraclePathsTo` :66 / `entryPathsTo` :103-115.
- The current scene type is `FlowRun::sceneType()` (`FlowRun.php:810`), set before `sceneOpening`, null in hooks and between scenes, changed by `switchSceneType`.
- The upgrade planner keeps no step state (`ReleaseUpgradePlanner.php:182,366`; ADR 0035: guidance restarts, a pending roll is dropped). The release diff compares items, not steps (`ReleaseDiff.php:105-124`).

## Decisions (approved by the user 2026-10-26)

| Item | Decision |
|---|---|
| Character for choice/prompt sheet effects | The player picks it (preselected when the campaign has one Character), as on check and character steps. A skip never applies a sheet effect (refused on the skip option) |
| Check sets | `checks` (2–10) instead of `check`; every check in the set has the same band keys; one difficulty and one outcome per band |
| Tables by scene type | `table` stays the default; optional `tableBySceneType` overrides it per scene type; hooks, no scene or an unmapped type roll the default; a step with a map has no `branches` |
| Prompt | Placement only: a prompt step's own effects may be sheet effects; `{answer}` stays text-only |

## Contract (ADR 0042)

Additive in schema version 2; existing releases keep their content hash; `v2.schema.json` extended in place (precedent: `4fe377c`, `1f6adb3`).

| Part | Shape | Rules |
|---|---|---|
| `sheet` effects | unchanged | Also allowed in a `choice` option's `effects` (not the option `skip` names) and a `prompt` step's `effects` |
| `check` step | `check` xor `checks` | `checks`: 2–10 unique known check keys with the same band keys; `difficulty` valid for each; `outcomes` keyed by the shared bands |
| `table` step | `table` + `tableBySceneType?` | Map of ≥1 known scene type → known table; no `branches` with a map; `otherwise` applies to every roll |

Play adds: `characterId` on a choice/prompt answer (required when the picked option or the prompt applies a sheet effect, refused on other choice/prompt steps) and the Characters in their step response; `checkKey` on a check-set roll (required, one of the set; refused on a single-check step), the set in the step response, the picked key on `PendingCheck` (legacy rows read the step's `check`); the table resolved from the current scene type, named in the step response.

## Constraints

- No migration: FlowRun and journal content are JSONB; `PendingCheck` gains an optional key.
- Play's translator refuses each new shape until the slice that plays it (precedent: ADR 0027, 0040, 0041).
- The upgrade planner stays deterministic and unchanged; tests prove a pending check-set roll is dropped.
- Slice ceiling ~1,700 changed lines, including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/rules-flow-choices-1-contract` | `feat(studio): rules-flow-choices 1/5 contract and publisher` | T1–T2 | ~1,550: ADR, feature doc, glossary ~250; schema and contract doc ~200; publisher (`Steps`, `Effects`, `StepParts`, `FlowReferences`), `DraftReferences` guards, translator refusals, tests and fixtures ~1,100 |
| 2 | `feat/rules-flow-choices-2-play-sheet-effects` | `feat(play): rules-flow-choices 2/5 sheet effects on choices and prompts` | T3a, T3–T4 | ~1,500: slice 1 review fixes; translator, `StepResult`, handler `characterId`, `FlowRun` finish and skip, journal sheet change, API tests ~800; Character select on choice and prompt steps, fixtures ~350; generated ~300 |
| 3 | `feat/rules-flow-choices-3-play-sets-tables` | `feat(play): rules-flow-choices 3/5 check sets and tables by scene type` | T5a, T5–T6 | ~1,700: slice 2 review fixes; translator, `CheckStep` set, `PendingCheck` key (legacy), `checkKey`, table by scene type in `FlowRun` and handler, responses, upgrade test ~950; check picker and table name in the step cards ~350; generated ~350 |
| 4 | `feat/rules-flow-choices-4-studio-editor` | `feat(studio): rules-flow-choices 4/5 flow editor` | T7a, T7 | ~1,300: slice 3 review fixes; sheet effects on choice options and prompts, check set picker with shared-band guidance, table-by-scene-type map in the step editor, `stepDraft` serialise/refusals, tests and stories |
| 5 | `feat/rules-flow-choices-5-preset` | `feat(presets): rules-flow-choices 5/5 hearts-and-rapiers` | T8a, T8–T9 | ~400: slice 4 review fixes; `hearts-and-rapiers` reworked in Studio (Chrome), re-exported, preset tests; one scene played; closing doc |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; ADR 0042 (index; status lines of ADR 0017, 0027, 0030, 0035; glossary "Check set"; roadmap B8b link); issue #285 | parent, inline | [x] | 63e7855 |
| T2 | 1 | Contract: `v2.schema.json`, the contract doc, Studio validator (`Steps` placement, skip option, `checks`, `tableBySceneType`; `Effects` placement message; `StepParts`/`FlowReferences` reach), `DraftReferences` guards (checks, bands, oracle tables, entries, scene types, sheet fields and options), Play translator refuses the new shapes; fixtures; unit tests | writer (delegated: 2+ non-trivial files) | [x] | ee06cbf, 33993c5 |
| T3a | 2 | Slice 1 review fixes | writer (delegated) | [x] | 5f96035 |
| T3 | 2 | Play: translator reads sheet effects on choice options and prompts; `StepResult` and handler `characterId` (required/refused); `FlowRun` applies them, a skip applies none; journal sheet change; API and Behat tests | writer (delegated) | [x] | 1420c38 |
| T4 | 2 | Choice and prompt step cards ask for the Character when the step can change the sheet (preselected when one); response Characters; fixtures; generated OpenAPI and TS | writer (delegated) | [x] | 0bf322a |
| T5a | 3 | Slice 2 review fixes | writer (delegated) | [x] | 3146f3c |
| T5 | 3 | Play: translator reads `checks` and `tableBySceneType`; `CheckStep` set and every single-check read; `checkKey`; `PendingCheck` key with legacy rows; table resolved from the scene type in `FlowRun` and the handler; responses; upgrade drops a pending check-set roll; tests | writer (delegated) | [x] | 6ed80d3 |
| T6 | 3 | Check step card picks the check of a set; table step card names the scene's table; fixtures; generated OpenAPI and TS | writer (delegated) | [x] | 735f23f, a6538b5 |
| T7a | 4 | Slice 3 review fixes | writer (delegated) | [x] | bdbbea1 |
| T7 | 4 | Flow editor: sheet effects on choice options (not the skip option) and prompt effects; check set picker (shared bands); table-by-scene-type map (no branches with a map); `stepDraft` serialise and refusal paths; tests and stories | writer (delegated) | [x] | 5f802eb |
| T8a | 5 | Slice 4 review fixes | writer (delegated) | [x] | |
| T8 | 5 | `hearts-and-rapiers` in Studio (Chrome): XP question awards XP, Scenes check offers Defy Disaster with any stat, Complication table by scene type; publish, re-export into `backend/presets/`, preset tests | parent (JSON) + writer (tests) | [x] | |
| T9 | 5 | Play one scene in Chrome; close (roadmap B8b done, issue) | parent | [ ] | |

## Acceptance criteria

- Releases without the new properties keep their content hash; presets other than `hearts-and-rapiers` are unchanged.
- The publisher refuses: both or neither of `check`/`checks`; fewer than 2 or more than 10 checks, a repeated or unknown one, checks with different band keys, a difficulty invalid for one of them; an empty `tableBySceneType`, an unknown scene type or table in it, `branches` with it; a sheet effect on a choice's skip option; sheet effects anywhere still outside the placements.
- Play applies a choice option's or prompt's sheet effects to the picked Character; a missing `characterId` there is a 422 with nothing changed; a `characterId` on a step that cannot change the sheet is refused; a skip changes no sheet.
- Play rolls the picked check of a set (`checkKey` required, in the set), pushes and resolves it like a single check; a pending roll stored before this feature still resolves.
- Play rolls the scene type's table, or the default in hooks, outside scenes and for unmapped types.
- Studio's in-use guards count every new reference; the flow editor authors all three.
- `hearts-and-rapiers` uses all three and one scene plays in Chrome.

## Checks

`make verify` once per slice. CI runs the full suites.

## Progress

- 2026-10-26: code map done; four design decisions approved by the user; ADR 0042, issue #285 and this doc created (13 tasks, five slices).
- 2026-10-26: T2 done (writer, delegated): schema v2 extended in place (`checks`, `tableBySceneType`); Studio `Steps` accepts sheet effects on choice options (not the skip option) and prompts, check sets and the table map; `StepParts::rolledTables` and `DraftReferences` count the new references; Play's translator refuses the three shapes. RED observed (41 failing assertions with the source stashed), GREEN on `tests/Unit/Studio` and the Play translator tests. About 860 changed lines.
- 2026-10-26: T3a done (writer, delegated): `Steps::list` takes a `setup` flag; a prompt's and a choice option's sheet effects are refused in a Scene Type's setup, allowed in hooks, play and closing; check-set bands compared as key sets. RED observed (2 refusals missing, reordered bands refused, source stashed), GREEN on `tests/Unit/Studio`.
- 2026-10-26: T3 done (writer, delegated): translator reads choice option and prompt sheet effects (refuses them on the skip option); `StepResult::choice/prompt` take `characterId`; handler and `FlowRun` require it when the option or prompt changes the sheet and refuse it otherwise (422, nothing changes); journal sheet change as for character steps; response flags `changesSheet` (per option, and per prompt step). Choice and prompt steps take any Character of the campaign, like the character step, so the response lists none. RED observed (source stashed: 10 domain and 13 API tests error), GREEN on `tests/Unit/Play` and `tests/Integration/Play`. No Behat feature covers choice or prompt completion.
- 2026-10-26: T4 done (writer, delegated): choice and prompt step cards ask for the Character (`SheetCharacterSelect`, preselected when the campaign has one; for a choice only once an option with `changesSheet` is picked, other options answer at once); `characterId` is sent only then; fixtures gain `changesSheet`; OpenAPI and TS regenerated. RED observed (card change stashed: 6 of 8 new tests fail), GREEN on `src/play` (563 tests).
- 2026-10-26: T5a done (writer, delegated): choice and prompt cards derive the Character (chosen, else the only one) on every render, so a list that becomes one Character after mount can be answered; an unknown `characterId` on a choice or prompt names the step's kind (`InvalidStepResult::unknownSheetCharacter`). RED observed (card change stashed: the new late-Character test fails, nothing sent), GREEN on `SheetEffectStep.test.tsx`, `tests/Unit/Play/Domain/Campaign/FlowRun` and the choice sheet effects API test.
- 2026-10-26: T5 done (writer, delegated): translator reads `checks` (2 to 10 distinct checks of the release sharing their band keys, difficulty and outcomes valid for each) and `tableBySceneType` (refused when empty or with branches); `CheckStep` holds `list<string> $checks` (`isSet()`, `singleCheck()`, `anyCheck()`), `TableStep::tableFor($sceneType)` is the one table rule (the FlowRun, its view and the handler use it); `FlowRun::pickedCheck()` validates `checkKey` (required on a set, refused on a single check, in the set; 422, nothing changes); `PendingCheck` keeps the picked key only for a set (single-check and legacy rows keep none and read the step's check); `FlowStepResponse` gains `checks` and names the table rolled now. RED observed (source stashed: 32 errors and 11 failures in the new and changed tests), GREEN on `tests/Unit/Play` and `tests/Integration/Play` (3,204 tests), PHPStan clean. No Behat feature covers check or table steps.
- 2026-10-26: T6 done (writer, delegated): a check step with `checks` shows a Check select with none preselected (`CheckForm` `pickCheck`), Roll waits for the pick and sends it as `checkKey`; a single-check step is unchanged and sends none; fails closed when a check of the set is not in the release. The table card already names `step.table`, so it needed tests only (`TableStep.test.tsx`: the table the response names, which now varies by Scene Type); fixtures gain `checks: []`. Generated TS client regenerated in its own commit; `frontend/src/shared/api/openapi.json` changed too but is outside the allowed edit surfaces, so it is left for the parent. RED observed (card change stashed: the new picker tests fail), GREEN on `src/play` (570 tests), `tsc` clean.
- 2026-10-26: T7a done (writer, delegated): the translator refuses at release load a `tableBySceneType` key that is no Scene Type of the release and a value that is no oracle table of it (`assertTableMapIsKnown`, same walk as the Tag and check reference checks). The difficulty finding needed no code change: the handler already performs the PICKED check (`pickedCheck`, then `Check::perform` validates the range on it); a new API test with two checks of different ranges (brawn 1 to 5, wits 6 to 10) pins it (the other check's difficulty is a 422 with nothing changed, the picked check's is accepted). RED observed for the two translator refusals (source stashed: both missing), the difficulty tests were green from the start (regression coverage). GREEN on `tests/Unit/Play/Infrastructure/GameSystem` and the check sets API test.
- 2026-10-26: T7 done (writer, delegated; frontend only, no endpoint change so no generated files): `StepListEditor` takes `setup` (the Scene Type editor passes it for its setup list) and offers sheet effects in a prompt step's effects (not in a setup) and in a choice option's effects (not the option `skip` names, not in a setup); a stored sheet effect where it is refused stays and carries the guide. Check step: "One check" / "A set of checks" (`checks: string[] | null` in the draft, `check` xor `checks` written); the set picker offers only checks with the band keys of the first one picked, outcomes and difficulty follow the shared bands, the difficulty guide tests every check. Table step: optional "Table by Scene Type" rows (`bySceneType`, null when off); no new branch while a map exists. `stepsToItems` refuses a set of fewer than 2 or more than 10 checks or with a repeat, an empty map, an empty row, a repeated Scene Type and branches with a map; the guides flag a check the game system lacks, other band keys, and a Scene Type or table it lacks. Drafts without the new shapes round-trip unchanged (tests). RED observed (editor sources stashed: 31 tests fail across `stepDraft`, `StepListEditor` and the Scene Type page), GREEN on `src/studio` and `tsc`/ESLint clean.
- 2026-10-26: T8a done (writer, delegated; frontend only): the set picker always lists every check the set holds (an unknown key by its key, flagged `(not in this game system)`) so a mismatched or unknown one can be unpicked, the band filter applying only to checks not yet picked; `checkDifficulty` returns `disjoint` and no range when the checks' ranges share no value, the editor says no difficulty fits (`DISJOINT_DIFFICULTY`) instead of "from 20 to 15", and a typed difficulty is flagged by the guide. Not blocking: guides are client hints and an out-of-range difficulty is already guide-only. RED (fixes stashed): the 3 new tests failed (stored-set unpick, disjoint hint, disjoint guide); GREEN: `vitest run src/studio/narrative/steps` 231 passed.
- 2026-10-26: T8 done (writer, delegated; preset JSON edited by the parent and re-exported canonically on a throwaway DB, by user decision instead of Studio authoring in Chrome because the local Studio draft is still v4): `hearts-and-rapiers` gains `swashbuckle-complication` and `intrigue-complication` (1d6) mapped by `tableBySceneType` (`romantic-complication` stays the default), the Scenes move rolls a check set (Defy Disaster with any of the five stats), and the Climax `yes` option awards 1 XP. `HeartsAndRapiersPresetFlowTest` pins the picked stat's modifier, the refusal without `checkKey`, the table per Scene Type, and `yes` +1 XP / `no` nothing (the Climax `fight-daring` single check still rolls unchanged); `PresetsTest` pins the tables, the map, the set and the option effect. RED observed (preset JSON stashed: 5 tests error with "takes no checkKey", the refusal and the Scene Type table tests fail; PresetsTest fails on the table keys), GREEN with the new preset (32 and 8 tests).

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|
| 1 | `review-df1a7a6a6d761b3b` (63e7855..ee06cbf) | Approved, acknowledged. R3 warning: prompt sheet effects are allowed in Scene Type setup lists and hooks, while the contract doc still lists setup effects as an error and no test covers either (`Steps.php:117-118`); fixed in slice 2 (T3a): refused in Scene Type setup, allowed in hooks, both tested. Suggestion: check-set bands compared as ordered lists (`Steps.php:428-431`); fixed in slice 2 (T3a): compared as sets, reordered keys tested. CI fix 33993c5 | [#286](https://github.com/iribarren/ogami/pull/286) |
| 2 | `review-0b9e00addc94f0b5` (5f96035..0bf322a) | Approved, acknowledged. R3 warning: `SheetCharacterSelect.tsx:37-43` hides the placeholder with one Character while `ChoiceForm`/`PromptForm` seed `characterId` once in a `useState` initializer, so a list that becomes one Character after mount leaves the step unanswerable; to fix in slice 3 (T5a). Suggestion: an unknown `characterId` on a choice or prompt reports "Character step … has no Character" (`CompleteFlowStepHandler.php:258-259`); to fix in slice 3 (T5a) | [#287](https://github.com/iribarren/ogami/pull/287) |
| 3 | `review-b32230ab49ff8fbb` (3146f3c..a6538b5) | Approved, acknowledged. R3 warning: the translator does not check that `tableBySceneType` keys are Scene Types of the release and its values oracle tables (`GameSystemReleaseTranslator.php:759-762`), so an unknown table fails at roll time instead of at release load; to fix in slice 4 (T7a). Suggestion: `CompleteFlowStepHandler.php:316` validates a player-set difficulty on a check-set step against the step's key, not the picked check's range; to fix in slice 4 (T7a). The parent committed the regenerated `openapi.json` in 735f23f | [#288](https://github.com/iribarren/ogami/pull/288) |
| 4 | `review-9c323b6812932765` (bdbbea1..5f802eb) | Approved, acknowledged. R3 warning: the set picker offers only checks with the first check's bands (`PickCheckFields.tsx:139-141`), so a stored set with a mismatched or unknown check cannot uncheck it in place; untested; to fix in slice 5 (T8a). Suggestion: the set difficulty intersects the checks' ranges (`stepDraft.ts:1103-1110`); disjoint ranges give a "from 6 to 5" hint with no clear message; untested; to fix in slice 5 (T8a) | [#289](https://github.com/iribarren/ogami/pull/289) |

## Next step

Slice 5: T8a (writer), T8 (parent + Chrome), review, push and PR, T9 (play one scene), closing doc commit.
