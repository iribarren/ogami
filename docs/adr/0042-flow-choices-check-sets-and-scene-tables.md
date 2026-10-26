# 0042. Sheet effects on choices and prompts, check sets and tables by scene type

- **Status:** Accepted
- **Date:** 2026-10-26

## Context

Authoring and playing `hearts-and-rapiers` (`preset-pbta`) found three flow gaps:

- **S11 / P4.** The end-of-session XP question ("Did you learn something?") cannot award XP. [ADR 0027](0027-sheet-effects.md) allows a sheet effect only in a `character` step's effects, a `check` step's outcomes and (since [ADR 0041](0041-dice-pools-pushes-and-conditions.md)) a push cost. A `choice` option or a `prompt` step cannot carry one, and Play only knows which Character to change when the step result names one (check and character steps).
- **S12 / P5.** A `check` step binds exactly one check. A PbtA move rolled with the stat that fits (Defy Disaster with any of five stats) needs five steps, or a step fixed to one stat.
- **P6.** A `table` step names one fixed table. The Complication table of `hearts-and-rapiers` is always Romantic, though the game rolls a different table per kind of scene.

## Decision

### Additive in schema version 2

All three are new optional properties or a widened placement. No release valid before uses them, so every release keeps its content hash, and `v2.schema.json` is extended in place as for sheet effects and dice pools. This amends ADR 0017 (step kinds), ADR 0027 (placement), ADR 0030 (the flow editor) and ADR 0035 (a pending roll of a check set). Out of scope: multi-select choices, per-NPC values, `{answer}` as a sheet effect value.

### Sheet effects on `choice` options and `prompt` steps

| Placement | Rule |
|---|---|
| A `choice` option's `effects` | May hold `sheet` effects, except the option the step's `skip` names |
| A `prompt` step's own `effects` | May hold `sheet` effects |

Values are what ADR 0027 already allows (integer, `{formula}`, `{dice}`); `{answer}` stays a text placeholder (titles, names, facts).

Play applies them to a Character the player picks when completing the step. The step response lists the campaign's Characters when the step can apply a sheet effect (any option of a choice, or the prompt's effects), and the UI preselects the only one. The request carries `characterId`: required when the picked option or the prompt applies a sheet effect, refused on any other choice or prompt step. A skip applies no sheet effect: the publisher refuses them on the skip option, and a skipped prompt applies none of its sheet effects.

### Check sets

A `check` step has exactly one of `check` (a check key, as today) or `checks`:

| Property | Shape | Rules |
|---|---|---|
| `checks` | list of 2–10 check keys | Unique, each a known check; every check in the set has the same band keys |
| `difficulty` | unchanged | Valid for every check in the set |
| `outcomes` | unchanged, keyed by band | The shared band keys |

The player picks the check when rolling: the request carries `checkKey`, required for a set and one of its keys, refused on a single-check step. The picked check rolls, bands and pushes as a single check would; the journal entry already records the check key. A pending roll ([ADR 0041](0041-dice-pools-pushes-and-conditions.md)) stores the picked check key; a pending roll stored before this ADR has none and reads the step's single `check`. The release upgrade planner keeps no step state, so it is unchanged: guidance restarts and a pending roll is dropped, a check set's like any other.

### Tables by scene type

A `table` step keeps `table` as its default and may add `tableBySceneType`, a map from scene type key to table key (1 entry or more, each key a known scene type, each value a known table). Play rolls `tableBySceneType[current scene type] ?? table`: a step in a session or phase hook, outside a scene or in a scene type the map does not name rolls the default. A `switchSceneType` effect earlier in the scene changes the table later steps roll. A step with `tableBySceneType` has no `branches` (entries differ per table); its `otherwise` outcome applies to every roll. The step response names the table the current scene rolls.

### References

Every reference the new properties add counts wherever references are walked: the flow's reach (tables and checks a flow uses), Studio's in-use guards (a check, its bands, an oracle table, a scene type) and the sheet field and condition option guards (sheet effects on options and prompts).

## Consequences

- One `hearts-and-rapiers` step can offer Defy Disaster with any stat, its XP question awards XP, and its Complication follows the scene type.
- A check set constrains authors to one band vocabulary; checks with different bands still need separate steps.
- Choice and prompt steps that change the sheet ask for a Character, as check and character steps do.
- Play's translator refuses each new shape until the slice that plays it, so a release using one is publishable but not playable in between (precedent: ADR 0027, 0040, 0041).
