# 0041. Dice pools, pushed rolls and conditions

- **Status:** Accepted
- **Date:** 2026-10-25

## Context

[ADR 0024](0024-structured-checks-without-scripting.md) left five check gaps open, to be filled when a played game hits them. Three presets on the roadmap hit three of them, plus one sheet gap:

- **Vaesen** (Year Zero): a pool of d6 sized by attribute + skill, sixes are successes, one success passes. A failed roll can be *pushed*: re-roll every die that is not a six, once, and take a Condition of your choice.
- **Vampire: the Masquerade**: a pool of d10 sized by attribute + skill, 6+ is a success, successes are compared to a difficulty. Later it adds critical pairs (two 10s) and Hunger dice (a second colour of die inside the pool).
- **West Marches** (D&D 5e): conditions such as Poisoned or Prone, marked and cleared by the flow.

Today a check rolls one dice expression whose dice count is a literal, adds a modifier and maps the total to bands. The Randomness kernel keeps every die with a kept/dropped flag but has no notion of a success. A flow `check` step rolls and resolves in one request. The sheet has `number`, `text`, `list`, `track` and `derived` fields; none can hold a set of marked conditions.

## Decision

### No scripting, additive in schema version 2

Pools, pushes and conditions are data, as in ADR 0024. They fill new optional properties, a new field type and two new sheet effect operations. No release valid before uses them, so every release keeps its content hash. This amends ADR 0024 (three gaps closed), ADR 0027 (new operations and placement), ADR 0032 (editor and sandbox) and ADR 0035 (fitting the new field, dropping a pending roll).

### Pool checks

A check has exactly one of `dice` (as today) or `pool`:

```
pool   { count, sides, successAt }
         count      int 1..100 | {formula}     sheet scope, as a check modifier
         sides      int 2..1000
         successAt  int 1..sides               a die succeeds when its face is at least successAt
```

- **Size.** The pool rolls `count` plus the situational modifier dice, clamped to 1..100: a pool never drops below one die. A count formula that fails to evaluate refuses the check (422), as a modifier does today.
- **Total.** The total is the number of successes. Difficulty and outcome bands work unchanged on it: Vaesen has a band up to 0 ("Failure") and the rest; VtM reads `{check:difficulty} - 1` as the failure bound.
- **No modifier.** A pool check refuses `modifier`: the count formula sizes the pool, and the situational modifier adds or removes dice.
- **Kernel.** Randomness gains a pool roll (count, sides, success threshold) returning each die's face and success, and a push of such a roll. The dice notation does not change. Play and Studio's sandbox both use the kernel, as they do for expressions.

### Pushed rolls

```
push   { label, effects? }      only on a pool check
         label     1..100       the cost, shown before the player pushes ("Take a physical Condition")
         effects   0..10        `sheet` effects (the rolling Character) and `tracker` effects
```

- **What a push does.** It re-rolls every die that did not succeed; successes stay. The new successes are the total, and the band is chosen again.
- **When.** Once per roll, only when at least one die failed, and only for a check that declares `push`.
- **Cost.** The cost effects apply with the push, after the re-roll, in list order and all or nothing. A cost effect that fails to evaluate refuses the push and changes nothing. The cost applies whatever the re-rolled dice show.
- **From the sheet.** The roll is journalled at once, as today. The player may push the campaign's latest journal entry when it is a pushable check roll from the sheet. Anything journalled since closes it.
- **From a `check` step.** A pushable roll leaves the step *pending*: the roll is journalled with the step snapshot and the FlowRun holds the step, the entry and the band. The player then picks **Keep** (the step completes on the rolled band) or **Push** (the step completes on the pushed band). Nothing else advances the Flow meanwhile, and a pending step cannot be skipped. A roll that cannot be pushed completes the step at once, as today.
- **Journal.** The push completes the same `check` entry, once: the re-rolled dice, the new successes and band, and the cost's sheet changes (field, before, after, dice rolled). This is the only change a journal entry's content can take.

### Conditions field

```
{ key, label, type: "conditions", hint?, options }
  options  1..20 of { key, label (1..60) }     keys unique in the field
```

- **Value.** The marked option keys, in declared order; initially none. The player marks and clears them on the sheet.
- **Formulas** read a conditions field as the number of marked options (`{physical}`), so a pool can lose a die per Condition: `{agility} + {stealth} - {physical}`.
- **Sheet effects** gain two operations, only on a conditions field:
  - `{kind: "sheet", field, op: "mark", value?}` marks an option. Without `value`, the player picks an unmarked option; this is allowed only in a push cost, where the push request carries the pick. With every option marked, it marks nothing.
  - `{kind: "sheet", field, op: "clear", value?}` clears an option, or every option without `value`.
  - `add`, `subtract` and `set` stay for `number` and `track` fields, and `mark` and `clear` are refused on them.
- **Placement** (ADR 0027) gains a check's push effects.

### Room for VtM, not built

- `pool` is a closed object, so critical pairs (`criticals`) and Hunger dice (`hunger: {count}`, dice taken out of the pool and rolled in a second colour, never pushed) can be added later as optional properties.
- A journalled pool die carries its group (`pool` for now), so a second colour reads as a second group.
- VtM's Willpower re-roll (up to three dice the player picks) is not a push. It would be a push option such as `dice: "failed" | {pick: 3}` later.

### Studio

- The check editor offers **Dice** or **Pool**, the pool's count (an integer or a formula), sides and success threshold, and a push section (cost label and effects). The item rules decide validity, as before.
- The test-roll sandbox rolls a pool against the samples. It can push a sandbox roll: the client sends back the faces and the server re-rolls the failed ones. Cost effects are listed, not applied, and nothing is recorded.
- The sheet builder gains the conditions field type with its options. The flow editor's sheet effects gain `mark` and `clear` with an option select.
- A conditions option referenced by an effect cannot be deleted or renamed, as with fields today.

### Release upgrade (ADR 0035)

- A conditions value keeps the marked keys that are still options. A new conditions field starts with none. A type change resets the value, as today.
- A pending check step is dropped with the rest of the guidance, which restarts. Its journal entry stays as rolled.

## Consequences

- Vaesen and VtM checks fit without a scripting language: pools sized by formulas, success counting, and Year Zero pushes with their cost.
- Conditions are first-class sheet values that formulas count and effects mark, for Vaesen, D&D and later systems.
- The FlowRun gains its first two-phase step. The pending state is persisted with the FlowRun and blocks other answers until the player resolves it.
- A journal entry's content can change once, for a push. All other content stays write-once.
- Exploding dice, opposed rolls, critical pairs, Hunger dice and picked re-rolls remain open.
