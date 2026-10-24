# 0040. Structured character creation

- **Status:** Accepted, amends [0027](0027-sheet-effects.md) and [0035](0035-campaign-release-upgrade.md)
- **Date:** 2026-10-24

## Context

Many systems create a Character by a method: Percentile Horror rolls 3d6×5 for most characteristics, or lets the player spend about 460 points across them, each from 15 to 90. Hit points then start at (CON + SIZ) / 10. Today a sheet can only say this in a step tip: the player rolls with the dice roller, adds up points by hand and copies a derived value into the track's max. [ADR 0027](0027-sheet-effects.md) left structured creation methods and formula maxima for per-Character tracks in the backlog ("Formula maxima for per-Character tracks stay player-entered").

The Randomness kernel already parses `3d6*5` and `(2d6+6)*5`, and Play already rolls a `{dice}` with the injected random source for sheet effects. Every addition below is optional, so every release valid before stays valid with the same content hash.

## Decision

**Contract (additive in schema version 2):**

- **Dice default.** A `number` field may declare `roll: {dice}`, a Randomness kernel expression of 1–100 characters (`3d6*5`). Its static `default` stays allowed and is used wherever nothing is rolled.
- **Formula start max.** A `track` may declare `startMax: {formula}` (1–200 characters) instead of a fixed `max`; declaring both is a semantic error. The formula reads `number` fields and `derived` fields that depend on no track and no tracker, directly or through other derived fields.
- **Point budget.** The sheet may declare one `pointBudget: {label?, total, fields, min?, max?}`:
  - `label` is 1–60 characters, `total` an integer from 1 to 1,000,000.
  - `fields` lists 1–100 distinct keys of `number` fields.
  - `min` and `max` are creation-only bounds for every budget field, with `min ≤ max`. The field's own bounds still apply, during creation and after.
  - Every budget field must keep at least one value inside both bounds, and the lowest values of all budget fields must fit in `total`.

**Creation methods.** A Character is created by `roll` or by `points`:

- `points` exists when the sheet has a budget; `roll` always exists. The player picks the method only when the budget covers a field with a dice default. Otherwise the request names no method, and naming one that does not apply is refused.
- **Roll.** The server rolls every field with a dice default, clamped to the field's bounds. The budget is not checked.
- **Points.** Budget fields take the given value, else their default. Each must sit inside the field's bounds and the budget's creation bounds, and their sum must not exceed `total`. Fields with a dice default outside the budget are still rolled.
- **Set at creation.** A value sent for a rolled field, or for a `startMax` track, is refused: the server sets those.
- **Start max.** After the rolls and points, each `startMax` formula is evaluated on the resolved values and clamped to 0..999. The track starts full, and its max then stays per-Character and editable like any other.
- **Errors.** New refusals answer 422 with `{error, code}` ([ADR 0037](0037-api-error-codes.md)): `creation_method_required`, `creation_method_unavailable`, `value_set_at_creation`, `outside_creation_bounds` and `point_budget_exceeded`.
- **No record of the dice.** Rolls write no journal entry. The created Character shows the values.

**Other paths:**

- **NPC promotion** creates a Character by `roll`, with no budget, and evaluates `startMax`.
- **Editing a Character** ignores all of this. Values stay inside the field's own bounds.
- **Release upgrade** (amends [ADR 0035](0035-campaign-release-upgrade.md)) keeps the plan deterministic:
  - An added `number` field takes its static initial value and is never rolled.
  - An added `startMax` track evaluates its formula on the fitted values and starts full.
  - An existing track keeps its stored max and is fitted as before.
  - The budget is not checked.
- **Track start value** (amends [ADR 0027](0027-sheet-effects.md)): a per-Character track with `startMax` starts full at that max. A start value that depends on other fields is still set by a `character` step's sheet effect.

## Consequences

- The release contract gains `roll`, `startMax` and `pointBudget`. Existing releases, presets and content hashes are unchanged.
- Play's translator keeps refusing the three properties ("unknown property") until Play creation supports them. Until then a release using them can be published but not played.
- Character creation and NPC promotion get the injected random source, as flow steps already do.
- Percentile Horror becomes the first user: it declares the rolls, the budget and the Hit and Magic point maxima, rolls Luck with the `character` step's sheet effect `{dice: "3d6*5"}`, and drops the rules from its tip.

## Out of scope

- A formula `total`, or several budgets (Percentile Horror's occupation and interest skill points).
- A dice default on a track's current value. Use a `character` step's sheet effect.
- Showing or re-rolling the dice before the Character is created, and a journal entry for the rolls.
- An `x` multiplication sign in dice notation. Write `3d6*5`.
- Rolling during a release upgrade.
