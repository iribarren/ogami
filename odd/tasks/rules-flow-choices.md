# Feature: rules-flow-choices

- **Locator:** `odd/tasks/rules-flow-choices.md` · Engram topic `odd/rules-flow-choices/tasks`
- **Issue:** #285 · **Branch:** `feat/rules-flow-choices-1-contract` · **Status:** slice 1 in progress
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
| T1 | 1 | This feature doc; ADR 0042 (index; status lines of ADR 0017, 0027, 0030, 0035; glossary "Check set"; roadmap B8b link); issue #285 | parent, inline | [x] | |
| T2 | 1 | Contract: `v2.schema.json`, the contract doc, Studio validator (`Steps` placement, skip option, `checks`, `tableBySceneType`; `Effects` placement message; `StepParts`/`FlowReferences` reach), `DraftReferences` guards (checks, bands, oracle tables, entries, scene types, sheet fields and options), Play translator refuses the new shapes; fixtures; unit tests | writer (delegated: 2+ non-trivial files) | [ ] | |
| T3a | 2 | Slice 1 review fixes | writer (delegated) | [ ] | |
| T3 | 2 | Play: translator reads sheet effects on choice options and prompts; `StepResult` and handler `characterId` (required/refused); `FlowRun` applies them, a skip applies none; journal sheet change; API and Behat tests | writer (delegated) | [ ] | |
| T4 | 2 | Choice and prompt step cards ask for the Character when the step can change the sheet (preselected when one); response Characters; fixtures; generated OpenAPI and TS | writer (delegated) | [ ] | |
| T5a | 3 | Slice 2 review fixes | writer (delegated) | [ ] | |
| T5 | 3 | Play: translator reads `checks` and `tableBySceneType`; `CheckStep` set and every single-check read; `checkKey`; `PendingCheck` key with legacy rows; table resolved from the scene type in `FlowRun` and the handler; responses; upgrade drops a pending check-set roll; tests | writer (delegated) | [ ] | |
| T6 | 3 | Check step card picks the check of a set; table step card names the scene's table; fixtures; generated OpenAPI and TS | writer (delegated) | [ ] | |
| T7a | 4 | Slice 3 review fixes | writer (delegated) | [ ] | |
| T7 | 4 | Flow editor: sheet effects on choice options (not the skip option) and prompt effects; check set picker (shared bands); table-by-scene-type map (no branches with a map); `stepDraft` serialise and refusal paths; tests and stories | writer (delegated) | [ ] | |
| T8a | 5 | Slice 4 review fixes | writer (delegated) | [ ] | |
| T8 | 5 | `hearts-and-rapiers` in Studio (Chrome): XP question awards XP, Scenes check offers Defy Disaster with any stat, Complication table by scene type; publish, re-export into `backend/presets/`, preset tests | parent + Chrome worker | [ ] | |
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

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|

## Next step

Slice 1: T2 (writer), then review, push and PR.
