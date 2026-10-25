# 0027. Sheet effects

- **Status:** Accepted, amended by [0040](0040-structured-character-creation.md) and [0041](0041-dice-pools-pushes-and-conditions.md)
- **Date:** 2026-10-15

## Context

Almost every real system loses Hit points and Sanity: a failed Sanity check costs 1d6 Sanity, a wound costs HP. Before slice 8 the Percentile Horror preset could only say "lose 1d6 Sanity: adjust the sheet" in a step tip, and the player edited the Character by hand; the preset now does it with sheet effects.

[ADR 0022](0022-character-sheets-in-schema-version-2.md) reserved effects on sheet fields for schema version 3. Since then derived values ([ADR 0023](0023-formula-kernel-and-derived-values.md)), checks ([ADR 0024](0024-structured-checks-without-scripting.md)), inspiration tables and phase descriptions ([ADR 0026](0026-prompt-inspiration-and-phase-descriptions.md)) all proved additive in version 2. A sheet effect is additive in the same way: no existing release uses it, so every release valid before stays valid with the same content hash.

## Decision

- **Additive in schema version 2.** This amends ADR 0022: sheet effects do not wait for version 3.
- **Effect `{kind: "sheet", field, op, value}`:**
  - `op` is `add`, `subtract` or `set`.
  - `value` is an integer from −1,000 to 1,000, a `{formula}`, or a `{dice}`.
  - `field` is a sheet `number` field (its value) or a `track` field (its current value). Never `derived`, `text` or `list`.
- **Placement.** Only where a Character is known: a `check` step's `outcomes[].effects` (the checked Character) and a `character` step's own `effects` (the Character the step completes with). Anywhere else is a semantic error: other step kinds, a check step's own `effects`, bands, options, branches, `otherwise`, oracle table entries and Scene Type setup effects. The JSON Schema cannot express placement; it accepts `sheet` wherever an effect is allowed and the validator refuses the rest.
- **`{formula}`** is checked in the sheet scope of that Character: `number`, `track` current value, `.max`, `derived` and `{tracker:key}`. `{check:difficulty}` is not available.
- **`{dice}`** is a dice expression of the Randomness kernel, up to 100 characters (`{dice: string}`, no other property). Play rolls it with the injected random source when the effect is applied.
- **Clamping.** A result clamps: a track to 0..its max, a number to its declared `min` and `max`.
- **Order and atomicity.** Effects apply in list order with the step, all or nothing.
- **Track start value.** No new `start` field. A track still starts full. A `character` step's sheet effect sets a start that depends on other fields: Percentile Horror sets Sanity to Power with `{kind: "sheet", field: "sanity", op: "set", value: {formula: "{pow}"}}`. Formula maxima for per-Character tracks stay player-entered.

## Consequences

- The release contract gains the `sheet` effect kind and the `diceRef` value form. Schema version 2 is additive: existing releases, presets and content hashes are unchanged.
- Play's translator keeps refusing the `sheet` kind ("unknown effect kind") until slice 7 of `preset-real-system`, so a release using it can be published but not played. Slice 8 of `preset-real-system` made the `percentile-horror` preset the first to use it.
- Slice 7 applies the effects in Play and records each change in the step's journal entry (field, before, after, dice rolled). An evaluation failure refuses the step and changes nothing.
- A step's sheet change is a Character edit made by the Flow, not by the player; the player still edits the sheet by hand.
- Structured creation methods (dice defaults, point budgets) and per-Character track maxima from formulas are decided in [ADR 0040](0040-structured-character-creation.md).
