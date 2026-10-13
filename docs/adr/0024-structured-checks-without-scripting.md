# 0024. Structured checks without scripting

- **Status:** Accepted
- **Date:** 2026-10-13

## Context

Feature `play-checks` (roadmap 12) adds Checks: named rules that roll a dice expression against a Character's sheet and map the result to outcome bands (glossary). The vision left one question open: "How far do structured checks go before they need a scripting language?", with the answer "Start with dice + formulas + outcome bands; extend only on a real gap".

The pieces already exist:

- Randomness parses and rolls dice notation (`DiceExpression`): `NdS`, `d%`, keep/drop selectors (`kh`, `kl`, `dh`, `dl`), arithmetic and parentheses.
- The Formula kernel ([ADR 0023](0023-formula-kernel-and-derived-values.md)) evaluates integer formulas over sheet values and trackers.
- Outcome bands branch `roll` and `condition` steps ([ADR 0017](0017-narrativeflow-model.md), [ADR 0018](0018-narrativeflow-control-flow-and-cast.md)).
- The release reserves an empty `checks` section, and [ADR 0022](0022-character-sheets-in-schema-version-2.md) and the release contract said checks would open schema version 3.

The first real system to play is Night Job, a heist one-shot run with Cyberpunk RED rules: 1d10 + stat against a Difficulty Value that changes from roll to roll. Fixed-band games (PbtA's 6- / 7–9 / 10+) and roll-under games (d100 ≤ skill) must fit as well.

Five questions needed answers:

1. Do structured checks need a scripting language?
2. Which schema version?
3. What does the player enter at roll time?
4. Which Character rolls, from the sheet and from a flow?
5. How does a flow step branch on a check's outcome?

## Decision

### No scripting: dice + one formula modifier + outcome bands

A Check is data. It has a dice expression, an optional formula modifier and ordered outcome bands. Nothing in it runs code.

```
total = dice total + modifier (formula, if any) + situational modifier (player, default 0)
```

The first band whose `upTo` is at least the total is the outcome, and the last band catches the rest. This is the same rule as `roll` steps.

This covers:

- **Roll-over against a target:** a CPR skill check with 1d10 + `{hands}` against a difficulty.
- **Fixed bands:** PbtA's 2d6 + `{cool}` with bands up to 6, up to 9, and the rest.
- **Roll-under:** d100 with no modifier and a band up to `{skill}`.
- **Keep highest or lowest:** advantage with `2d20kh1`, or a small pool's best die with `3d6kh1`.

A scripting language waits for a real gap that a played game hits. These gaps are known and deliberately left open:

- exploding dice;
- a dice count read from the sheet (pools sized by a stat);
- counting successes per die;
- opposed rolls;
- re-rolls and pushes.

Each one would extend the dice notation or the band model first. A scripting language would only follow if several of them turn out to need control flow.

### Additive in schema version 2

Checks fill the reserved `checks` section, and the `check` step kind is added to schema version 2. No new version is opened, as with the sheet, `credits` and derived values. Every release that was valid before stays valid and keeps its hash. This amends ADR 0022, ADR 0017 (which said "`check` arrives as a later schema version") and the release contract.

```
Check   { key, label (1–100), hint? (≤500), dice, modifier?, difficulty?, bands }
          dice        dice notation, as a `roll` step's
          modifier    formula (1–200), sheet scope
          difficulty  { label (1–60), default, min?, max? }   integers within ±1,000, min ≤ default ≤ max
          bands       1–10 of { key, label (1–100), upTo? }    keys unique in the check; every band but the last has upTo
                      upTo: int | {tracker: key} | {formula}   sheet scope, plus {check:difficulty}
checks  at most 50; keys unique
```

**Formula scope.** A check formula reads the same values as a derived value: `number` fields, `track` current values and `.max`, derived values and trackers. A band formula can also read `{check:difficulty}`, but only when the check declares a difficulty. The Formula kernel gains the reference kind `{check:key}` for this; Studio and Play allow only `difficulty`. It lands in PHP and TypeScript with conformance vectors, in the same change.

**Checked at publish** (Studio) and again by Play's translator, failing closed:

- the dice notation parses;
- formulas parse and their references exist with the right kind;
- band keys are unique and the bounds are well formed;
- the difficulty's range holds.

### Roll-time inputs: a difficulty and a situational modifier

- **Difficulty.** When a check declares one, the player sets it before rolling, prefilled with `default` and kept within `min..max` (±1,000 when no bounds are given). Bands read it as `{check:difficulty}`. A check without a difficulty refuses one (422).
- **Situational modifier.** The player can always add an integer from −100 to 100, default 0, for the fiction's edge or hindrance. In a roll-under game a bonus is written as a negative number.
- **Evaluation failures.** If the modifier or a band bound fails to evaluate (division by zero, overflow), the check is refused with a clear 422 and nothing is rolled or logged.

### Who rolls

- **From the sheet:** any Character of the Campaign, in or out of a Scene, so checks work in free play between scenes.
- **From a flow `check` step:** a Character in the current Scene (`Scene::characters()`, which starts as the Session party, ADR 0018 decision 24). Outside a Scene, the Session party is used. The player picks the Character, and it is preselected when there is only one. With no eligible Character, the step cannot be answered: a suggested step can be skipped, and a mandatory one waits until the cast has a Character.

### The `check` step kind

```
{ key, kind: "check", title, tip?, mandatory?, check, difficulty?, outcomes? }
  check       a check key of the release
  difficulty  int | {tracker: key} | {formula}   flow scope (trackers); only for a check with a difficulty
  outcomes    [{ band, next?, effects? }]         band keys of that check, each at most once
```

- The step rolls the named check. When the step sets `difficulty`, the flow fixes it and the player cannot change it. Otherwise the player enters it as on the sheet.
- `outcomes` branch per band key, with the same `next` and `effects` rules as a `roll` band. A band without an entry continues to the next step. Keying by band key rather than position keeps a step readable when the check gains a band.

### The journal

Every check, from the sheet or from a step, logs one `check` journal entry. It holds:

- the Character (id and name at roll time);
- the check (key and label);
- the dice roll with every die;
- the evaluated modifier, the situational modifier, the difficulty and the total;
- the outcome band (key and label).

A step's entry carries the usual flow-step snapshot.

## Consequences

- No new language and no new schema version. Checks reuse Randomness for the dice, Formula for the modifier and bounds, and the outcome bands' first-match rule. Play composes them, and neither kernel imports the other.
- The Formula kernel gains one reference kind, `{check:key}`, in both evaluators and the conformance file.
- Studio's check editor (feature 17) edits exactly this shape, and its test-roll sandbox uses the same Play-independent pieces.
- Systems with exploding dice, stat-sized pools, success counting or opposed rolls do not fit yet. The real system played in feature 13 decides which gap, if any, gets filled first.
