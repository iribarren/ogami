# 0049. Challenge dice and burning momentum

- **Status:** Accepted
- **Date:** 2026-11-01

## Context

[ADR 0024](0024-structured-checks-without-scripting.md) gave a check one total that picks a band. [ADR 0041](0041-dice-pools-pushes-and-conditions.md) added pools and a push that re-rolls failed pool dice, and [ADR 0044](0044-hunger-dice-criticals-and-special-results.md) added special results. Feature `preset-ironsworn` (roadmap B16) builds an Ironsworn preset. It uses only the Ironsworn SRD, which is licensed under CC BY 4.0; the full rulebook is CC BY-NC-SA and stays out. Credits carry the licence notice ([ADR 0021](0021-release-credits-for-fan-presets.md)).

An Ironsworn move compares a **score** against two ten-sided *challenge dice*, each on its own. Five things do not fit a check today:

- **The score against two dice.** The total is how many challenge dice the score beats (0, 1 or 2), not a sum. A formula cannot read dice.
- **A match.** When both challenge dice show the same face, the story changes. It is a result the roll has, not a band.
- **Burning momentum.** Instead of the rolled score, the player may use their momentum, which is then reset. A push re-rolls pool dice, so it does not fit.
- **Negative momentum.** When momentum is below zero and its absolute value equals the action die, the die counts zero.
- **A progress roll.** It has no action die: the score is the progress made, against the same challenge dice.

Momentum itself (a `number` field from −6 to +10), its reset and maximum (derived values), the choice of stat (one check per stat in a check set) and vow tracks (five fixed tracker slots) need no contract change.

## Decision

### Additive in schema version 2

`challenge` is an optional property of a check, and `push` gains a second meaning on a check that has it. No release valid before uses either, so every release keeps its content hash, and a `null` is left out of the canonical form. Play's translator refuses `challenge` until the slice that rolls it, and a challenge `push` until the slice that burns, with the path and a message naming this ADR (precedent: ADR 0044). A release using them publishes but cannot start a Campaign until then.

### The challenge shape

```
challenge  { count, sides, scoreMax?, momentum? }       closed object
             count      int 2..10
             sides      int 2..1000
             scoreMax   int 1..1000
             momentum   key of a `number` sheet field
```

The total of a challenge check is a count: the number of challenge dice the score strictly beats. Bands, step outcomes and check sets work unchanged. Ironsworn bands are `miss` up to 0, `weak` up to 1 and `strong`.

### Action roll

A check with `dice` and `challenge` is an action roll. The score is `dice + modifier + situational modifier`, capped at `scoreMax` when it is set. Each of the `count` challenge dice is rolled, and the total is the number of them that show a face strictly below the score: ties go to the dice. `dice` is the action die, so `momentum` needs it to be exactly one die (`1dN`, no selectors, no arithmetic).

### Progress roll

A check with `challenge` and no `dice` is a progress roll. `modifier` is required and is the score (with the situational modifier), capped at `scoreMax` as an action roll's is. It has no action die, so it cannot name `momentum`: the SRD ignores momentum on a progress move.

### Refused shapes

Studio refuses at publish, and the schema holds the shapes it can:

- `pool` and `challenge` on one check.
- `difficulty` on a challenge check: the challenge dice are the difficulty.
- A progress roll without `modifier`, or with `challenge.momentum`.
- `challenge.momentum` when `dice` does not roll exactly one die.
- `challenge.momentum` naming a missing field or a field that is not type `number`.
- `push` on a challenge check without `challenge.momentum`, and `push.maxDice` on a challenge check.

`push` stays refused on a plain dice check. A check step's `special` outcome stays refused on a challenge check, which cannot produce `critical`, `messyCritical` or `bestialFailure`.

### Negative momentum

When the momentum value is below 0 and its absolute value equals the action die, the action die counts 0. The journal says so. Momentum is read from the Character's sheet when the roll is made.

### Burning momentum as a push

A challenge check's `push` burns momentum. No dice are rolled: the score becomes the momentum value (capped at `scoreMax`), and the challenge dice stay as rolled. The push keeps its label, its cost effects and the once-per-roll rule, and a `check` step stays pending until the player keeps or burns, as with a pool push. The Ironsworn cost is `set momentum {formula: "{momentum-reset}"}`, applied after the score is read.

**When a burn is offered.** Only when momentum beats more challenge dice than the score does. A roll that cannot improve completes at once, as a pool roll with no failed die does. It is checked again when the burn arrives, so a momentum edited by hand in between fails closed.

**Why the larger of score and momentum.** The SRD lets a player cancel any challenge die that is below momentum. That equals using the larger of score and momentum. If the score beats die A and momentum beats die B, and neither beats the other's die, then S > A ≥ M > B ≥ S, which is a contradiction. So one of the two beats a superset of the dice, and a single score is enough.

### Match

A new special result `match` applies when every challenge die shows the same face. It is shown in the roll dialog, the journal and the sandbox. It is **not** an outcome a step can name: a `special` entry replaces the band entry ([ADR 0044](0044-hunger-dice-criticals-and-special-results.md)), and a match must keep the band's effects, since a strong hit with a match is still a strong hit. A burn cannot change it, as the challenge dice are not re-rolled. A flow tip may point the player at the Major Plot Twist oracle.

### Studio and Play

- **Play** (slices 2 and 3) rolls the challenge dice, applies the score cap and the negative-momentum rule, journals the score, each die, the cancelled die and the match, and plays the burn from the roll dialog, the sheet and the pending step.
- **Studio** (slices 4 and 5) edits challenge mode, the momentum select and the burn, rolls them in the sandbox with the match, and counts `challenge.momentum` as a field reference, so it blocks a rename or a deletion like other check references.
- **Release upgrade** ([ADR 0035](0035-campaign-release-upgrade.md)): nothing changes.

## Consequences

- An Ironsworn move fits as data: a score against challenge dice, a momentum field, a burn and a progress roll, with no scripting and an unchanged formula kernel.
- Any game that compares a score against separate dice reuses the shape (Starforged and its descendants).
- A match cannot branch a flow in this version. A flow reads it from the journal or a tip only.
- `push` now means "re-roll failed pool dice" or "burn momentum" depending on the check. The cost, label and once-per-roll rule are shared.
- Still open: a flow branch on a match, per-instance progress tracks (vows are five fixed slots), a momentum maximum as a field bound, and asset cards as data.
