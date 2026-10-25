# Feature: rules-dice-pools

- **Locator:** `odd/tasks/rules-dice-pools.md` · Engram topic `odd/rules-dice-pools/tasks`
- **Issue:** #276 · **Branch:** `feat/rules-dice-pools-3-play-pools` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B8 ([ADR 0041](../../docs/adr/0041-dice-pools-pushes-and-conditions.md), closing three gaps of [ADR 0024](../../docs/adr/0024-structured-checks-without-scripting.md))

## Objective

Checks can roll a pool sized from the sheet and count successes per die, a pool check can be pushed once with a cost the release declares, and the sheet gains a conditions field that formulas count and sheet effects mark. Every layer: contract and publisher, Play's translator, Play checks (sheet panel and `check` step), the Studio check editor and sandbox, the sheet builder and flow editor, and the release upgrade planner. Vaesen (B9), VtM (B10) and West Marches (B15) build on it.

## Problem and why

- The dice count of an expression is an integer literal (`backend/src/Randomness/Domain/DiceExpression.php:13-20`, `Expression/Parser.php:110`); nothing reads it from the sheet.
- The kernel keeps every die with a kept/dropped flag (`Randomness/Domain/RolledDie.php:10`) but has no success flag; a check's total is dice total + modifier + situational (`Play/Domain/GameSystem/Check/Check.php:84-109`).
- A `check` step rolls and resolves in one request (`Play/Application/CompleteFlowStepHandler.php:280`, `Play/Domain/Campaign/FlowRun/FlowRun.php:299-307`); `FlowRunStatus` has no pending state.
- Journal entries are write-once (`Play/Domain/Journal/JournalEntry.php` has no content mutator).
- Sheet field types are `number`, `text`, `list`, `track`, `derived` (`backend/contracts/gamesystem-release/v2.schema.json:93-100`); a Condition is today a number field read in a modifier (`hearts-and-rapiers`: `{grace} - 2 * {frightened}`).
- Sheet effects only `add`/`subtract`/`set` number and track fields (`Studio/Domain/Release/Version2/Effects.php:39,212`, `Play/Domain/Campaign/FlowRun/FlowRunContext.php:280`).

## Decisions (approved by the user 2026-10-25)

| Item | Decision |
|---|---|
| Conditions | A new `conditions` sheet field type with declared options; its value is the marked keys; formulas read the count marked; sheet effects gain `mark` and `clear`; a push cost may `mark` without an option so the player picks one |
| Push in a flow | A pushable roll leaves the `check` step pending until the player picks Keep or Push; nothing else advances the Flow; an upgrade drops the pending roll with the guidance |

Decided while writing ADR 0041 (no product impact): `dice` xor `pool`; the pool size is count + situational, clamped 1..100; a pool check refuses `modifier`; the total is the success count, bands and difficulty unchanged; a push re-rolls every non-success die once, only with a failed die, cost after the re-roll, all or nothing; the sheet pushes only the campaign's latest journal entry; a push completes the same journal entry once (the only content change an entry can take); `clear` without an option clears all; the dice notation is unchanged.

## Contract (ADR 0041)

Additive in schema version 2; existing releases keep their content hash.

| Part | Shape | Rules |
|---|---|---|
| `check.pool` | `{count, sides, successAt}` | Exactly one of `dice`/`pool`. `count` int 1..100 or `{formula}` (1–200, sheet scope as a modifier); `sides` int 2..1000; `successAt` int 1..`sides`. No `modifier` with `pool` |
| `check.push` | `{label, effects?}` | Only with `pool`. `label` 1–100; `effects` 0–10 of `sheet` and `tracker` effects |
| `conditions` field | `{key, label, type: "conditions", hint?, options}` | `options` 1–20 of `{key, label (1–60)}`, keys unique in the field |
| sheet effect ops | `op: "mark" \| "clear"`, `value?` an option key | Only on a conditions field (and `add`/`subtract`/`set` never on one). `mark` without `value` only in a push cost. `clear` without `value` clears all |

Play adds: a push request on the sheet's latest check entry (with condition picks), Keep/Push on a pending `check` step, the pool and push parts of the `check` journal entry, conditions values on Characters. Endpoint shapes are decided in their slices.

## Constraints

- Additive in schema version 2; no migration (Character values, FlowRun and journal content are JSONB).
- Play's translator refuses each new shape until the slice that plays it, so a release using it is publishable but not playable in between (precedent: ADR 0027, 0040).
- Room for VtM's `criticals` and `hunger` (closed `pool` object, a `group` on journalled pool dice); not built.
- The upgrade plan stays deterministic (its fingerprint).
- Slice ceiling ~1,700 changed lines, including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/rules-dice-pools-1-contract` | `feat(studio): rules-dice-pools 1/8 contract and publisher` | T1–T2 | ~1,400: ADR, feature doc, glossary ~300; schema and contract doc ~200; publisher (`Checks`, `SheetFields`, `Effects`, `Formulas`) with unit tests and fixtures ~900 |
| 2 | `feat/rules-dice-pools-2-play-conditions` | `feat(play): rules-dice-pools 2/8 conditions on the sheet` | T3a, T3–T4 | ~1,500: translator and `ConditionsField`, values, formula count, `mark`/`clear` effects, upgrade fitting ~800; pinned release exposes options, sheet checkboxes, fixtures and tests ~400; generated OpenAPI and TS ~300 |
| 3 | `feat/rules-dice-pools-3-play-pools` | `feat(play): rules-dice-pools 3/8 pool checks` | T5a, T5–T6 | ~1,650: slice 2 review fix ~50; kernel pool roll ~250; translator, `Check::perform` pool path, `CheckContent` pool part, API ~650; check form pool size and journal success dice ~400; generated ~300 |
| 4 | `feat/rules-dice-pools-4-sheet-push` | `feat(play): rules-dice-pools 4/8 pushed rolls from the sheet` | T7–T8 | ~1,600: kernel push, translator push, cost effects with condition picks, journal amendment, push endpoint and codes ~900; Push in the check dialog with the cost and picks ~400; generated ~300 |
| 5 | `feat/rules-dice-pools-5-step-push` | `feat(play): rules-dice-pools 5/8 pushed rolls in a check step` | T9–T10 | ~1,500: FlowRun pending check step, Keep/Push command and endpoint, planner drops the pending roll ~800; step card Keep/Push ~400; generated ~300 |
| 6 | `feat/rules-dice-pools-6-studio-pool` | `feat(studio): rules-dice-pools 6/8 pool checks in the editor` | T11–T12 | ~1,600: test roll pool and sandbox push (backend) ~600; editor Dice/Pool mode and pool inputs, sandbox pool dice and Push ~700; generated ~300 |
| 7 | `feat/rules-dice-pools-7-studio-push` | `feat(studio): rules-dice-pools 7/8 push cost in the editor` | T13 | ~1,000: push section (label, sheet and tracker effects with `mark`) in the check editor, tests and story |
| 8 | `feat/rules-dice-pools-8-studio-conditions` | `feat(studio): rules-dice-pools 8/8 conditions in the builder` | T14–T15 | ~1,400: conditions field in the sheet builder, options in use by effects, formula diagnostics; `mark`/`clear` in the flow editor's sheet effects; Chrome pass, closing doc |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; ADR 0041 (index; status lines of ADR 0024, 0027, 0032, 0035; glossary; roadmap B8 link); issue #276 | parent, inline | [x] | ad46102 |
| T2 | 1 | Contract: `v2.schema.json` (`pool`, `push`, `conditions` field, `mark`/`clear`), the contract doc, Studio validator (`Checks`, `SheetFields`, `Effects` placement and ops, `Formulas` count scope), fixtures; unit tests | writer (delegated: 2+ non-trivial files) | [x] | 1f6adb3 |
| T3a | 2 | Slice 1 review fix: an explicit `null` `value` on `mark`/`clear` reads like an absent one (Studio `Effects`), proven by tests | writer (delegated) | [x] | c472aa5 |
| T3 | 2 | Play conditions: translator reads the field and `mark`/`clear` (with an option; still refuses `pool`, `push` and a pick); `ConditionsField`, `CharacterValues`, `SheetValues` count, `FlowRunContext` effects; upgrade planner fitting; tests | writer (delegated) | [x] | 4fb450d |
| T4 | 2 | Pinned release exposes conditions options; Play sheet marks and clears them (checkboxes); character value API tests; fixtures; generated OpenAPI and TS | writer (delegated) | [x] | d6c05a1, b09f96d |
| T5a | 3 | Slice 2 review fix: the upgrade planner's reading of a stored conditions list with a duplicate or a non-string entry (`ReleaseUpgradePlanner.php:268-272`) is pinned by tests (trimmed vs unchanged) | writer (delegated) | [x] | |
| T5 | 3 | Kernel pool roll (count, sides, threshold → faces and successes); translator reads `pool`; `Check::perform` pool path (size clamp, success total); `CheckContent` pool part; API responses; tests | writer (delegated) | [ ] | |
| T6 | 3 | Play check dialog and `check` step show the pool size; journal shows successes per die; tests and fixtures | writer (delegated) | [ ] | |
| T7 | 4 | Kernel push; translator reads `push`; push of the latest journal entry from the sheet: re-roll, cost effects with picks, journal amendment, codes; API tests | writer (delegated) | [ ] | |
| T8 | 4 | Check dialog offers Push with the cost and condition picks; journal shows the pushed roll; tests | writer (delegated) | [ ] | |
| T9 | 5 | FlowRun pending check step: pushable roll pends; Keep/Push completes on the right band; other answers and skip refused; upgrade drops the pending roll; API tests | writer (delegated) | [ ] | |
| T10 | 5 | Step card shows the pending roll with Keep and Push; tests | writer (delegated) | [ ] | |
| T11 | 6 | Studio test roll: pool checks against samples; push of a sandbox roll (faces sent back); API tests; generated | writer (delegated) | [ ] | |
| T12 | 6 | Check editor Dice/Pool mode (count int or formula, sides, success at); sandbox shows success dice and Push; tests | writer (delegated) | [ ] | |
| T13 | 7 | Check editor push section: cost label, sheet and tracker effects, `mark` with or without an option; `DraftReferences` counts `check.push.effects` (slice 1 note); tests and story | writer (delegated) | [ ] | |
| T14 | 8 | Sheet builder conditions field (options editor); options referenced by effects cannot be deleted or renamed (`DraftReferences` learns conditions options, slice 1 note); formula diagnostics count it; flow editor `mark`/`clear`; tests | writer (delegated) | [ ] | |
| T15 | 8 | Chrome pass (author a pool check with push and a conditions field in Studio, publish, play it), closing doc | parent | [ ] | |

## Acceptance criteria

- Releases without the new properties keep their content hash; presets are unchanged.
- The publisher refuses: both or neither of `dice`/`pool`; `modifier` with `pool`; `successAt` above `sides`; a count formula outside the sheet scope; `push` without `pool`; push effects other than `sheet`/`tracker`; `mark`/`clear` on a non-conditions field and `add`/`subtract`/`set` on a conditions field; an unknown option; `mark` without an option outside a push cost; repeated option keys.
- Play rolls a pool of count + situational dice (1..100), totals the successes, bands it; a count that fails to evaluate is a 422 with nothing rolled.
- A push re-rolls only failed dice, once, applies its cost all or nothing (including a picked condition), and completes the same journal entry; a push of anything but the latest pushable entry is refused.
- A pushable `check` step pends; Keep and Push complete it on the right band; nothing else advances while it pends; an upgrade drops it.
- Conditions: marked and cleared on the sheet and by effects, counted by formulas, fitted by the upgrade planner.
- Studio authors pools, pushes, conditions fields and `mark`/`clear`; the sandbox rolls and pushes pools.

## Checks

`make verify` once per slice. CI runs the full suites.

## Progress

- 2026-10-25: Explored every layer (kernel, contract, publisher, translator, Play checks and flow, journal, Studio editor and sandbox, upgrade planner). The user chose the conditions field and pending check steps. Issue #276, ADR 0041 and this feature doc written.
- 2026-10-25: T2 contract and publisher: `pool`, `push`, the `conditions` field and `mark`/`clear` in `v2.schema.json`, `Checks`, `SheetFields`, `Effects`, `Formulas` (a conditions field reads as a number) and the contract doc; fixture `valid/v2-dice-pools.json` (also run through the schema by the agreement test).
- 2026-10-25: T2 evidence. RED first: 83 of 146 tests failed in the four extended Studio test files (checks, sheet effects, sheet, formulas) before the publisher changed; GREEN after; `tests/Unit/Studio` 1893 tests OK. The agreement test's `v2-dice-pools` case failed before the schema change and passed after. Passed first time: the schema test `ReleaseSchemaDicePoolsTest` (written after the schema) and the Play translator refusals of `pool`, `push`, a `conditions` field, `mark` and `clear` (93 tests OK). One existing expectation changed: the Studio formula-scope message now lists conditions fields.
- 2026-10-25: T3a evidence. The three new tests (explicit `null` `value` on `clear`, on a push-cost `mark`, and on a `mark` outside a push) passed first time: the validator already reads `null` like an absent `value` and the canonical form drops it; no source change was needed, the tests now pin the behaviour (R3).
- 2026-10-25: T3 Play conditions: `ConditionsField` and `ConditionOption`, translator (`conditions` field, `mark`/`clear` with an option, `clear` without one; still refuses a `mark` without an option, `pool` and `push`), `CharacterValues` (marked keys, unique, declared, stored in declared order), `SheetValues` (count marked), `FlowRunContext` (mark, clear, clear all; the change tells the count before and after, so the journal shape is unchanged), `ReleaseUpgradePlanner` (drops marks of removed options as `trimmed`, follows the new order silently, a type change resets). RED: with `backend/src` stashed (new classes aside) the Play unit suite had 31 errors and 34 failures; GREEN after: `tests/Unit/Play` + `tests/Unit/Studio` 3729 OK, `tests/Integration/Play` 1022 OK (the Character round trip now holds a conditions value), PHPStan clean. Tests were written after the code for the value, count and planner rules, so RED there is the stash run, not a test-first run.
- 2026-10-25: T4 API and UI: the pinned release's sheet field exposes `type: "conditions"` and `options [{key, label}]` (`SheetFieldView`, `SheetFieldResponse`, `ConditionOptionResponse`; `options` is null for other types); the values API takes and returns the marked keys. New `CampaignCharactersConditionsApiTest`: options in declared order, empty start, canonical order and count, 422 for unknown option, duplicate, string, number, object, null and a non-string item (nothing saved), the 409 on a concurrent POST and PUT carrying a conditions value, and a legacy Character without the field (reads empty, derived count 0). The shared sheet renders a conditions field as one checkbox per option inside the Character form (the form's Save is the existing values save path); the dialog and the panel summary list the marked labels read-only; `computeDerived` counts the marked keys. RED/GREEN: the frontend and API tests were written after the code and passed once the Radix `ResizeObserver` stub was added to `CharactersPanel.test.tsx` (as other test files do for checkboxes in a form); no separate RED run. Generated files (`frontend/src/shared/api/openapi.json`, `schema.d.ts`) are in the API commit. Studio TS needed no change (the union grew without breaking it).
- 2026-10-25: T5a evidence: the planner already collapses a duplicate key and drops a non-string entry and reports both `trimmed` (a Character cannot store either, so the tests pin the private fitting rule by reflection); three new cases passed first time, no source change (R3).

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|
| 1 | review-72e9e9651173b257 (1f6adb3) | Approved, acknowledged. 1 suggestion: R3 explicit `null` `value` on `mark`/`clear` not proven to read like an absent one (`Studio/Domain/Release/Version2/Effects.php:250-256`), fixed in slice 2 (T3a). Writer notes moved to T13/T14: `DraftReferences` ignores `check.push.effects` and conditions options | [#277](https://github.com/iribarren/ogami/pull/277) |
| 2 | review-30332141e457fa01 (c472aa5..b09f96d) | Approved, acknowledged. 1 suggestion: R3 no test for a stored conditions list with a duplicate or non-string entry in the upgrade planner (`Play/Domain/Campaign/Upgrade/ReleaseUpgradePlanner.php:268-272`), fixed in slice 3 (T5a) | [#278](https://github.com/iribarren/ogami/pull/278) |

## Next step

Slice 3 (T5a, T5, T6): pool checks played from the sheet and in `check` steps (no push yet); then native review and the PR.
