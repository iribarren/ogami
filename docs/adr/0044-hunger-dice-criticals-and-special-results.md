# 0044. Hunger dice, critical pairs and special results

- **Status:** Accepted
- **Date:** 2026-10-27

## Context

[ADR 0041](0041-dice-pools-pushes-and-conditions.md) gave checks dice pools and pushes, and left room for Vampire: The Masquerade: `pool` is a closed object that can gain optional properties, and a journalled pool die carries its group. Feature `preset-vtm-chronicle` builds a VtM night-court preset under the Dark Pack Agreement, and VtM's dice need four things a pool lacks:

- **Critical pairs.** Every pair of 10s counts as four successes instead of two.
- **Hunger dice.** As many dice of the pool as the vampire's Hunger are rolled in a second colour. They count successes like the others but are never re-rolled.
- **Special results** that come from those dice: a *critical* (a win with a pair of 10s), a *messy critical* (a critical with a 10 on a Hunger die) and a *bestial failure* (a failed roll with a 1 on a Hunger die). The story changes with them: a bestial failure triggers a Compulsion.
- **The Willpower re-roll**: up to three failed dice, never Hunger dice.

The Dark Pack Agreement also requires its logo on the material, and a release carries no image.

The user decided three points: special results are shown and a flow can branch on them; the re-roll is a push capped at a number of dice; the logo comes from a closed enum of badges whose images ship with the SPA.

A check has a total, an optional difficulty the bands read as `{check:difficulty}`, and bands ([ADR 0024](0024-structured-checks-without-scripting.md)). Nothing says which band means "failed".

## Decision

### Additive in schema version 2

Every new part is an optional property or a new value. No release valid before uses them, so every release keeps its content hash, and a `null` is left out of the canonical form. Play's translator refuses each new shape, with its path and a message naming this ADR, until the slice that plays it: `criticals`, `hunger` and `maxDice` until slice 2, `special` outcomes and `badge` until slice 3 (precedent: ADR 0027, 0040, 0041). A release using them publishes but cannot start a Campaign until then.

### Pool: critical pairs and Hunger dice

```
pool   { count, sides, successAt, criticals?, hunger? }
         criticals  { bonus }       bonus  int 1..10
         hunger     { count }       count  int 0..100 | {formula}   sheet scope, as pool.count
```

- **Critical pairs.** Two dice showing the top face (`sides`) make a pair, and each pair adds `bonus` successes to the total. VtM: `bonus` 2, so a pair of 10s counts 4. Pairs form across groups. A die is in one pair at most; an odd top face counts as a plain success.
- **Pairing order.** Hunger dice pair first, then regular dice, each group in roll order. So a pair holds a Hunger die whenever a pair exists and a Hunger die shows the top face, which is VtM's rule for a messy critical. The total never depends on the order.
- **Hunger dice.** The pool is sized as before (`count` plus situational dice, clamped to 1..100). Then `hunger.count` of those dice become Hunger dice, clamped to 0..size: a Hunger formula below 0 rolls none, and one above the pool size makes every die a Hunger die. A Hunger formula that fails to evaluate refuses the check (422), as `pool.count` does. Hunger dice use the same `successAt` and count successes like the rest.
- **Roll order.** Regular dice are rolled first, then Hunger dice. The journal keeps each die's group: `pool` for regular dice, `hunger` for Hunger dice. Entries stored before this ADR hold `pool` dice only and read unchanged.
- **Only on a pool.** `criticals` and `hunger` are pool properties, so a dice check cannot have them.

### Special results

A roll has at most one special result: `critical`, `messyCritical`, `bestialFailure` or none. It is computed from the dice and the total, after the bonus of critical pairs.

**When a roll fails.** A check has no "failed" flag, so the rule uses what a roll already has:

- When the check declares a difficulty, the roll fails when its total is below the roll's difficulty (the player's, or the step's fixed one). VtM's bands say the same: `fail` is up to `{check:difficulty} - 1`.
- Otherwise, the roll fails when its outcome is the first band of a check with two bands or more. A check with a single band never fails.

**Precedence.**

1. A failed roll is a `bestialFailure` when a Hunger die shows 1, and has no special result otherwise.
2. A roll that does not fail is a `messyCritical` when it has a critical pair with a Hunger die in it.
3. Otherwise it is a `critical` when it has a critical pair.
4. Otherwise it has none.

The first two rules split the rolls in two, so a bestial failure and a critical never compete; the only overlap is a messy critical, which is also a critical and wins.

**Change from the feature doc's default.** The doc's default made any roll with a critical pair a `critical`. VtM's critical is a critical *win*: a pair of 10s on a roll that still misses the difficulty is a plain failure. So criticals need a roll that does not fail. The bonus of the pairs still counts in the total either way.

A special result needs its dice: `critical` needs `pool.criticals`, `messyCritical` needs both `pool.criticals` and `pool.hunger`, and `bestialFailure` needs `pool.hunger`. A check without them never has one.

### Push capped at N dice

```
push   { label, effects?, maxDice? }
         maxDice   int 1..100
```

- **Hunger dice are never pushed.** A push re-rolls failed *regular* dice only. A roll can be pushed when at least one regular die failed; a roll whose failed dice are all Hunger dice cannot.
- **The cap.** Without `maxDice`, a push re-rolls every failed regular die, as in ADR 0041. With it, the push re-rolls the first `maxDice` failed regular dice in roll order. The order is deterministic; the player does not pick, as picked re-rolls stay out of scope. A top face always succeeds, so a push never breaks a critical pair.
- **Recomputed.** After a push, the total (successes plus the pairs' bonus), the band and the special result are computed again from the dice as they stand. VtM works this way: a Willpower re-roll can turn a failure into a critical, and a Hunger 1 still makes the new result a bestial failure if the roll still fails.
- The cost, the once-per-roll rule and the pending `check` step (Keep or Push) are unchanged.

### Check step outcomes on a special result

```
outcomes   [{ band, next?, effects? } | { special, next?, effects? }]     at most 13
             special   critical | messyCritical | bestialFailure
```

- Each entry names exactly one of `band` or `special`. Each band and each special appears at most once. Up to 13 entries: 10 bands and 3 specials.
- **A special entry wins.** When the roll has a special result and the step has an entry for it, that entry supplies `next` and `effects`. Otherwise the band entry does, as before. A `messyCritical` matches only a `messyCritical` entry. It does not fall back to a `critical` entry, so an author who wants the same branch writes both.
- **Only when the check can produce it.** A `special` outcome is refused unless the step's check can produce it (the pool parts above). For a check set ([ADR 0042](0042-flow-choices-check-sets-and-scene-tables.md)), every check of the set must be able to produce it. An outcome no roll can reach would be a silent dead branch.
- **Keep and Push.** A pending step completes with the special result of the kept roll, or the recomputed one after a push.

### Credits badge

```
credits   { notice, license?, url?, badge? }
            badge   null | "dark-pack"
```

- A closed enum, so a release never carries an image or a URL to one. The SPA ships each badge's image, the official logo from the publisher's asset kit, and shows it beside the notice.
- An unknown value fails closed in Studio and in Play. A new badge is a new enum value and its image, added together.

### Studio and Play

- **Play** (slices 2 and 3) rolls Hunger dice inside the pool, counts critical pairs, journals the special result with the entry (`null` for entries stored before), shows Hunger dice in a second colour and the special result in the roll dialog and the journal, caps the push, and shows the badge.
- **Studio** (slices 4 and 5) edits the criticals bonus, the Hunger count and the push cap, sandbox-rolls them with the special result, and offers special outcomes in the flow editor and the badge in the metadata form. A Hunger formula's sheet fields and Trackers are references like a `pool.count` formula's, so they block a rename or a deletion.
- **Release upgrade** ([ADR 0035](0035-campaign-release-upgrade.md)): nothing changes. A pending check step is dropped as before, and its journal entry stays as rolled.

## Consequences

- VtM checks fit without scripting: Hunger dice, critical pairs and the capped Willpower re-roll are data on a pool, and flows branch on a bestial failure or a messy critical as they do on a band.
- "Fail" now has a meaning on every check: below the difficulty, or the first band. Only special results use it, but a later feature that needs a pass/fail flag can reuse it.
- Authors who want one branch for both kinds of critical write two outcomes. The step stays explicit about which result takes which path.
- Credits can show a publisher's logo without releases carrying images. Every badge ships with Ogami.
- Picked re-rolls, opposed rolls, exploding dice and a face for criticals other than the top face remain open.
