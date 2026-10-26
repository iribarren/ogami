# 0043. Several point budgets per sheet

- **Status:** Accepted, amends [0040](0040-structured-character-creation.md)
- **Date:** 2026-10-26

## Context

[ADR 0040](0040-structured-character-creation.md) gives a sheet one `pointBudget` and leaves several budgets out of scope. Authoring `lanterns-of-the-mire` (`preset-vaesen`, P2) needs two: 12 attribute points and 8 skill points, at most 3 per skill. Percentile Horror's occupation and interest skill points are the same case.

`sheet.pointBudget` is part of the canonical release content, so turning it into a list would change the content hash of every release with a budget (`ReleaseContent`). A Studio draft is a release document checked by the item rules (`GameSystem::replaceSheet()`), and publishing hashes `ReleaseContent::fromArray()` of it.

## Decision

### Contract (additive in schema version 2)

- **`sheet.pointBudgets`**: an optional list of 1–10 budgets `{key, label, total, fields, min?, max?}`. `null` or `[]` is no budget and is left out of the canonical form. `v2.schema.json` is extended in place; there is no schema version 3.
  - `key` follows the key rule (`^[a-z0-9-]{1,64}$`); keys are distinct in the list.
  - `label` is required, 1–60 characters, not blank once trimmed.
  - `total`, `fields`, `min` and `max` keep ADR 0040's limits and its spendability check, per budget against its own bounds.
- **Legacy `sheet.pointBudget`** stays valid unchanged (`label` optional, no key). A sheet declaring both, neither `null`, is refused at `sheet.pointBudgets`.
- **A field sits in at most one budget**, across the list. The later occurrence is refused at its path, such as `sheet.pointBudgets[1].fields[0]`, naming the earlier budget's key.
- **Error paths** index the list: `sheet.pointBudgets[1].key` (duplicate key), `sheet.pointBudgets[1].label`, `sheet.pointBudgets[1].total`, `sheet.pointBudgets[0].fields[2]`. The legacy paths stay `sheet.pointBudget.…`.
- **Canonical form.** A list of exactly one budget canonicalizes to the legacy `pointBudget` `{label, total, fields, min?, max?}`: the key is dropped, the label kept. Two or more stay `pointBudgets`, in the order given. The publisher (`PointBudget::validate()`) does this, so a draft publish, a preset publish and the "no changes" check (`GameSystemPublication`) agree.
- **Hashes.** No release before this ADR has `pointBudgets`, so every content hash is unchanged, and a one-budget release keeps the legacy shape and hash whatever route wrote it.

### Studio

- **Budgets as a list.** Studio reads a draft's budgets as a keyed list: `pointBudgets` as stored, or a legacy `pointBudget` as one budget with key **`points`** and its label, possibly none. `points` names the creation method budgets serve, follows the key rule and is what a published one-budget release reads back as, since the canonical form drops the key.
- **Label round trip.** A draft keeps a legacy `pointBudget` exactly as restored or imported until a keyed write. A label-less legacy budget therefore publishes byte-identically (a republish is `no_changes`). A keyed write needs a label, so the published legacy budget then carries one.
- **Keyed writes.** Any keyed write stores the whole list as `pointBudgets` and drops `pointBudget`; a one-entry list is valid in the draft and canonicalizes on publish. Removing the last budget removes the property. Converting a label-less legacy budget into a list of two is refused by the item rules at `sheet.pointBudgets[0].label`: the manager labels it first (`PUT …/point-budgets/points`).
- **Routes**, under `/api/studio/game-systems/{key}/sheet` as the field and layout routes; `points` addresses a legacy budget:
  - `PUT …/point-budgets/{budget}` (`SaveSheetPointBudgetEntry`): body `{expectedVersion, label, total, fields, min?, max?}`, the key is the path's. Replaces the budget in place, else appends it. 200 with the sheet; 422 with the path on a contract error (an invalid path key fails at `sheet.pointBudgets[i].key`).
  - `DELETE …/point-budgets/{budget}?expectedVersion=` (`RemoveSheetPointBudgetEntry`): removes it; 404 when the draft has no budget with that key.
  - Both keep the existing 400, 401, 403, 404 (GameSystem), 409 (concurrent save, library pack) and 415 answers.
- **View.** `GameSystemSheetResponse` gains `pointBudgets`: a list of `{key, label|null, total, fields, min|null, max|null}`, empty without budgets.
- **Single-budget endpoint and view field** (`PUT` / `DELETE …/sheet/point-budget`, view `pointBudget`) keep working on a draft with at most one budget, until slice 3 moves the UI and removes them. `PUT` writes the legacy `pointBudget` as today and drops a one-entry `pointBudgets`; `DELETE` removes the one budget of either shape. With two or more budgets both answer 409 `{error, code}` with code `several_point_budgets` ([ADR 0037](0037-api-error-codes.md)); the endpoints' other 409 causes take `modified_concurrently` and `library_pack`. The view's `pointBudget` is the single budget, `null` with none or several.
- **In-use guards** as today, per budget: deleting or renaming a field listed by any budget is refused with its paths (`sheet.pointBudgets[i].fields[j]`, `DraftReferences`), and changing its type away from `number` fails the item rules at the budget path. A field already in another budget is refused at the saved budget's field path.

### Play

- **Translator** reads `pointBudget` (key `points`, label or none) or `pointBudgets` (each key and label) into a list, refuses both, and re-checks the limits, distinct keys and one budget per field, failing closed.
- **`SheetTemplate`** holds a list of budgets (`budgets()`, `PointBudget` gains `key`); its constructor checks each covers number fields only and that no field is in two.
- **Creation methods** (amends ADR 0040). One method per Character. A budget is *rollable* when it covers a field with a dice default.
  - The player picks a method only when some budget is rollable; then a request without one is `creation_method_required`.
  - With budgets and none rollable, the request names no method or `points`; `roll` is `creation_method_unavailable`. Without budgets, `points` is `creation_method_unavailable`.
  - **Roll.** Every field with a dice default is rolled. Each rollable budget is handled as ADR 0040's budget under roll: its other fields take a value inside the bounds, no total. Every other budget is spent as under `points` (Percentile Horror's skill points).
  - **Points.** Every budget is spent: its fields take the given value inside the field's and the budget's bounds, at most its `total`. Budget fields are not rolled; dice fields outside every budget are.
  - Budgets are checked in list order and the first refusal answers. The error codes stay ADR 0040's; the messages of `point_budget_exceeded` and `outside_creation_bounds` name the budget's label, or "the point budget" when it has none.
- **Unchanged:** NPC promotion (rolls every dice default, no budget), Character editing and the release upgrade (budgets not checked).
- **Pinned release response** adds `pointBudgets` (same entries as the Studio view, empty without budgets) beside `pointBudget`, which is the single budget and `null` with none or several, until slice 3 removes it.

## Consequences

- A sheet can budget attributes and skills separately; Percentile Horror can add its skill points without losing its characteristic roll.
- A one-budget release keeps its legacy shape, so its key never reaches Play; Play names it `points`.
- The contract carries two spellings of a budget until a schema version 3, if one ever comes.

## Out of scope

- A formula `total` (Percentile Horror's EDU × 4).
- A method per budget: one Character mixes roll and points only as the rule above says.
- Studio and Play UI for several budgets: slice 3 of `preset-vaesen`.
