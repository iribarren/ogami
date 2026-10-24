# Feature: structured-character-creation

- **Locator:** `odd/tasks/structured-character-creation.md` · Engram topic `odd/structured-character-creation/tasks`
- **Issue:** #270 · **Branch:** `feat/structured-character-creation-3-creation-form` · **Status:** in progress: slice 3
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7k (follow-up of `release-api-followups`, #255, and [ADR 0027](../../docs/adr/0027-sheet-effects.md))

## Objective

A sheet declares how a Character is created: number fields with a dice default rolled on the server, a point budget over a set of number fields, and per-Character tracks whose max starts from a formula. The server applies them when the Character is created. `percentile-horror` is the first user.

## Problem and why

- Percentile Horror explains creation in the `you` step tip (`backend/presets/percentile-horror.json:244`): roll 3d6 x 5 or (2d6 + 6) x 5 with the dice roller, or spend about 460 points (each characteristic 15–90), then copy `hp-max` and `mp-max` into the track maxima by hand. Nothing checks any of it.
- A per-Character track (no `max`) starts at `{current: 0, max: 0}` (`CharacterValues::initial`, `backend/src/Play/Domain/Campaign/Characters/CharacterValues.php:110-119`); the player types the max (`frontend/src/shared/sheet/SheetFields.tsx:238-277`).
- The Randomness kernel already parses `3d6*5` and `(2d6+6)*5` (`backend/src/Randomness/Domain/DiceExpression.php`); `3d6x5` is refused. Play already rolls `{dice}` sheet effects with the injected `RandomNumberGenerator` (`CompleteFlowStepHandler` → `Campaign::completeFlowStep` → `FlowRunContext::applySheetEffect`). `AddCharacterHandler` gets no random source today.
- Creation (`Campaign::addCharacter`, `Campaign.php:816-833`), NPC promotion (`Campaign.php:779`) and the upgrade planner's added field (`ReleaseUpgradePlanner.php:87-89`) all fill missing values with `CharacterValues::initial`.
- Play's translator refuses any new sheet or field property (`GameSystemReleaseTranslator.php:114-120`, `:767-772`, `:1131-1135`), so it keeps refusing until Play supports creation.

## Decisions (approved by the user 2026-10-24)

| Item | Decision |
|---|---|
| Methods | When the budget covers fields with a dice default, the player picks "Roll" or "Spend points". Rolled fields outside the budget are always rolled. The creation request gains a nullable method |
| Budget | Spend at most `total`. The budget may set its own creation-only `min`/`max` per field (Percentile Horror: 15–90 while the fields stay 1–99) |
| Track max | `startMax: {formula}` on a track (exclusive with `max`), evaluated once at creation after the rolls; the track starts full; its max then stays per-Character and editable |
| Rolled values | The server always rolls dice-default fields; a value sent for one (or for a `startMax` track) is refused with 422. No journal entry |
| Mixed budget under roll (2026-10-24, slice 2 review) | When a budget mixes dice and non-dice fields and the player rolls, the player sets the non-dice budget fields; each must sit inside the budget's creation `min`/`max` (`outside_creation_bounds`); the total is not checked under roll |

Decided while writing ADR 0040 (no product impact): one budget per sheet with an integer total; NPC promotion creates by `roll` and evaluates `startMax`; the upgrade planner never rolls (an added number field takes its static initial value, an added `startMax` track evaluates its formula on the fitted values); `3d6*5`, no `x` operator; new 422s carry ADR 0037 codes.

## Contract (ADR 0040)

Additive in schema version 2; existing releases keep their content hash.

| Part | Shape | Rules |
|---|---|---|
| `number.roll` | optional `{dice}` | Kernel expression, 1–100 characters (same as `diceRef`). `default` stays allowed |
| `track.startMax` | optional `{formula}` | 1–200 characters. Not with `max` (domain check). Reads `number` fields and `derived` fields that depend on no track and no tracker, directly or transitively |
| `sheet.pointBudget` | optional `{label?, total, fields, min?, max?}` | `label` 1–60; `total` 1–1,000,000; `fields` 1–100 distinct keys of `number` fields; `min`/`max` sheet numbers, `min ≤ max`. Domain: each budget field keeps a value inside both its own and the budget bounds; the sum of the lowest such values is at most `total` |

Creation request (`POST /api/campaigns/{id}/characters`) gains a nullable `method` (`roll` or `points`). Codes: `creation_method_required`, `creation_method_unavailable`, `value_set_at_creation`, `outside_creation_bounds`, `point_budget_exceeded`.

## Constraints

- Additive in schema version 2; no migration (Character values are JSONB already).
- The translator refuses the new properties until slice 2, so a release using them is publishable but not playable in slice 1 (precedent: ADR 0027).
- The upgrade plan stays deterministic (its fingerprint): no rolls there.
- Planning heuristic: slice ceiling ~1,700 changed lines, including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/structured-character-creation-1-contract` | `feat(studio): structured-character-creation 1/4 contract and field dialog` | T1–T3 | ~1,500: ADR and feature doc ~250, schema and contract doc ~120, Studio validator, in-use checks and unit tests ~650, field dialog (dice default, track max mode) with tests ~500 |
| 2 | `feat/structured-character-creation-2-play-creation` | `feat(play): structured-character-creation 2/4 creation on the server` | T4–T6 | ~1,600: translator and Play sheet model ~350, creation methods, rolls, budget and `startMax` in the domain with promotion ~500, API (request, codes) with tests ~300, generated OpenAPI and TS ~150, upgrade planner ~300 |
| 3 | `feat/structured-character-creation-3-creation-form` | `feat(play): structured-character-creation 3/4 creation form` | slice 2 review fixes, T7 | ~1,500: review fixes with generated files ~200; pinned release exposes `roll`, `startMax` and `pointBudget` ~250 and generated OpenAPI and TS ~150; creation form (method choice, budget counter, rolled and computed fields) with tests, fixtures and story ~900 |
| 4 | `feat/structured-character-creation-4-point-budget` | `feat(studio): structured-character-creation 4/4 Percentile Horror and point budget editor` | T8–T10 | ~1,100: preset and its flow test ~300, point budget panel in the sheet builder (and its save path) with tests ~800; closing doc |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; ADR 0040 (index; status lines of ADR 0027 and 0035; roadmap B7k link) | parent, inline | [x] | d9151ad |
| T2 | 1 | Contract: `v2.schema.json` (`roll`, `startMax`, `pointBudget`), the contract doc, Studio validator (`SheetFields`, `Formulas`) and the draft in-use checks (a field read by `startMax` or listed in the budget cannot be deleted); unit tests | writer (delegated: 2+ non-trivial files) | [x] | 7c05612 |
| T3 | 1 | Studio field dialog: dice default on a number field; track max as fixed, per-Character or start formula; `sheetDraft` and tests | writer (delegated) | [x] | 83f1407, 39af8f4 |
| T4 | 2 | Translator and Play sheet model read `roll`, `startMax` and `pointBudget` (fail closed on malformed) | writer (delegated) | [x] | 981beba (slice 1 fixes), eb721fb |
| T5 | 2 | Creation by method: `AddCharacterHandler` gets the random source; rolls, budget and `startMax` in the domain; NPC promotion; request `method` and 422 codes; API tests; regenerated OpenAPI and TS | writer (delegated) | [x] | cf51164 |
| T6 | 2 | Upgrade planner: added `startMax` track evaluated on the fitted values; added rolled number field takes its static initial value; plan tests | writer (delegated) | [x] | 4837ba5 |
| T7 | 3 | Pinned release exposes `roll`, `startMax` and `pointBudget`; Play creation form (Characters panel and `character` step): method choice, budget counter with creation bounds, rolled fields and `startMax` tracks shown as set at creation; fixtures, tests, story | | [ ] | |
| T8 | 4 | `percentile-horror`: dice defaults, budget "Characteristic points" 460 (15–90), `startMax` for Hit and Magic points, Luck rolled by the `you` step effect, shorter tip; preset flow test | | [ ] | |
| T9 | 4 | Studio point budget panel in the sheet builder | | [ ] | |
| T10 | 4 | Review fixes, Chrome pass, closing doc | | [ ] | |

## Acceptance criteria

- Releases without the new properties keep their content hash; presets are unchanged until T8.
- The publisher refuses: `roll` with invalid dice; `startMax` with `max`; a `startMax` formula reading a track, a tracker, or a derived field that depends on one; a budget over a missing, non-number or repeated field; budget `min > max`; bounds that leave a field no value; a `total` below the sum of the lowest values.
- Play creates a Character by `roll` (dice-default fields rolled with the injected source, clamped) or by `points` (budget checked, creation bounds), refuses values for server-set fields, and starts `startMax` tracks full. Each 422 code has an API test.
- NPC promotion rolls and evaluates `startMax`. The upgrade plan stays deterministic.
- Studio authors dice defaults, start formulas and the budget; Play's creation form offers the method, counts points and shows server-set fields.
- Percentile Horror creates investigators by rolling or by spending 460 points, with Hit and Magic points set at creation.

## Checks

`make verify` once per slice. CI runs the full suites.

## Progress

- 2026-10-24: Explored every layer (contract, publisher, translator, Play creation, Randomness, Studio builder, upgrade planner, preset). The user chose the four creation decisions. Issue #270, ADR 0040 and this feature doc written.

- 2026-10-24: T2 done. Schema v2 gains `numberField.roll`, `trackField.startMax` and `sheet.pointBudget`; Studio validates them (`SheetFields`, `Formulas::startMax`, new `PointBudget`); drafts refuse deleting or renaming a field read by a `startMax` formula or listed in the budget; contract doc section added. RED: the new unit tests failed before the validator (unknown property, missing class), GREEN after. The translator test for the three refusals passed first time (Play unchanged). No OpenAPI change: Studio sheet fields cross the API as free-form objects.
- 2026-10-24: T3 done. `sheetDraft` reads and writes `roll` and a track max mode (fixed, each Character, formula at creation); the dialog has a "Dice default" input and a "Max is" select with a `FormulaInput` over number and derived fields; server paths such as `roll.dice` land on their inputs. Dialog and draft tests written with the code, so they passed first time (no RED).
- 2026-10-24: Slice 1 review fixes done (start max walk memoized per derived key; diamond and check-value cases). T4 done. The translator reads `roll`, `startMax` and `pointBudget` into `NumberField::$roll`, `TrackField::$startMax` and the new `PointBudget` on `SheetTemplate`; malformed shapes (types, unknown sub-keys, `startMax` with `max`, budget over a non-number or repeated field) are refused. Not exposed in API responses. No RED observed: the tests were written with the code and passed first time (the old refusal cases in the layout test were removed).
- 2026-10-24: T5 done. `CharacterCreation` (domain) rolls dice defaults with the injected source (clamped to the field's own bounds), checks the budget and evaluates `startMax` after the other values (clamped 0..999; a formula that fails refuses the Character); `AddCharacterHandler` and `PromoteNpcHandler` take the `RandomNumberGenerator`; the request gains a nullable `method`; 422 bodies carry `{error, code}` for the five ADR 0040 codes (`CharacterCreationErrorCode`, derived from `InvalidCharacter::$refusal`), other 422s stay `{error}`. Method rule: no budget means `points` is unavailable (`roll` and null create as before); a budget with a rolled field needs `roll` or `points`; a budget with no rolled field is spent implicitly (`roll` unavailable, null or `points` accepted). By points every budget field needs a value (a missing one is `outside_creation_bounds`, not its default); a value for any server-set field is `value_set_at_creation`; edits are unchanged. Promotion rolls every dice default whatever the budget. Unknown `method` is a plain 422, a non-string a 400. Not exposed in API responses. Domain, handler, API (each code, promotion) tests; RED not observed: tests were written with the code, a first run failed only on test fixtures (DerivedField signature, NPC release id).
- 2026-10-24: T6 done. An added `startMax` track starts full at its formula over the fitted values (clamped 0..999; an unreadable formula gives 0, a plan never fails); an added number field with `roll` already took `NumberField::initial()` (default, else 0 inside the bounds), so only a test was needed; the fingerprint is unchanged for releases without the new properties. RED: the start max plan test failed before the planner change (1 failure), the rolled-number test passed first time (existing behaviour). A field that is Reset into a `startMax` track (type change) still starts at 0.
- 2026-10-24: Slice 2 review fixes done. The 422 `code` property references the `CharacterCreationErrorCode` union (the class-name `enum` is gone, so the TS type is the five literals). By roll, a budget field without a dice default must be given and sit inside the budget and field bounds (a missing one is `outside_creation_bounds`, as by points); the total is not checked. Also fixed: a budget with nothing to roll and no method now checks the budget like points (it was skipped). RED: the two new domain tests failed before the change (2 failures); the mixed-budget API test passed first time (written after the domain change).

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|
| 1 | `review-7ab9c67fe0dd9325` (medium) | Approved and acknowledged. WARNING R3-startmax-unmemoized-walk (`Formulas.php:156-164`, the "known at creation" walk is not memoized per derived key) and SUGGESTION R3-startmax-check-ref-untested (`ReleaseContentCreationTest.php:159-168`, no check-reference case): fixed in slice 2 | #271 |
| 2 | `review-574dbb807677a9eb` (medium) | Approved and acknowledged. WARNING R3-error-code-enum-not-an-array (`CharacterCreationErrorResponse.php:19`, the OpenAPI enum is the class name, so the TS type is `unknown`) and SUGGESTION R3-mixed-budget-roll-unbounded (`CharacterCreation.php:40-43`, non-dice budget fields unbounded under roll; the user decided, see Decisions): fixed in slice 3 | #272 |

## Next step

Slice 3: slice 2 review fixes, T7 (pinned release exposes the creation properties; Play creation form). T8 (Percentile Horror) moved to slice 4 to keep slice 3 under the ceiling.
