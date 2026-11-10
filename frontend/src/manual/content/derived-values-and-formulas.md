# Derived Values and Formulas

Some numbers on a sheet are not typed in. They follow from other numbers: a modifier from a trait, a limit from a score. A **derived value** is a sheet field of the Derived type. It has no stored number. It holds a **Formula**, and the number is worked out each time it is shown.

A Formula is also at work in other places: a Track's maximum at creation, a Check's modifier and the bounds of an outcome band. This page covers the Formula itself and the derived field. _Checks and Outcome Bands_ shows the other uses.

## What a Formula can say

A Formula is a whole-number expression.

| Part              | Written as                                                    |
| ----------------- | ------------------------------------------------------------- |
| Numbers           | `0` to `1000000`, written without a sign                      |
| Arithmetic        | `+`, `-`, `*`, `/`, with the usual order, and brackets        |
| Negation          | `-` in front of a part: `-{wounds}`                           |
| Smallest, largest | `min(a, b, ...)` and `max(a, b, ...)`, with 2 to 10 arguments |
| A field           | `{back}`                                                      |
| A Track's maximum | `{wind.max}`                                                  |
| A Tracker         | `{tracker:winter}`                                            |

Division rounds down, so `7/2` is 3 and `-7/2` is -4. A Formula is at most 200 characters, and no step of it may pass a billion in either direction. A division by zero is an error when it is worked out. There are no decimals, no conditions, no `if`, no rounding functions and no text.

A Formula that breaks the grammar is refused when you save the field, and again when you publish.

## What it can read

A derived field can read:

- Number fields, as `{key}`.
- Other derived fields.
- A Track's current value as `{key}` and its maximum as `{key.max}`.
- Conditions fields, which read as the number of conditions marked.
- Trackers, as `{tracker:key}`.

It cannot read Text, List, Select or Inventory fields. A Select such as a Dogwatch Station gives no number, and the hint on the field says so. An Inventory is not read by Formulas either. That is why a rule like "your carrying limit is your Back" cannot be a Formula over the Load: the capacity is a fixed number on the Inventory.

A Track's start maximum is more restricted still. It is worked out when the Character is created, before the Character has any Track or Tracker value, so it can read only Number and Derived fields.

Editing a derived field in Studio shows what the Formula reads and what it computes to with sample values, as you type. A reference to a key that does not exist, or one the field may not read, is shown as an error before you save, and so is a cycle: a derived value cannot depend on itself, even through another one.

## Honest limit: no preset uses one

Neither Hard Country nor Dogwatch has a derived value, a Formula-sized maximum or a Check modifier. Hard Country keeps its traits as plain Numbers and compares the roll against them in the Check's bands. The only Formulas in the two presets are band bounds: `{back}`, `{feet}`, `{head}`, and `{check:difficulty}-1` in Dogwatch.

The examples below are written for this chapter. They do not exist in the presets.

### Hard Country: grit

Suppose a bearer's staying power were the worst of their three traits. Add a Derived field `grit` with the Formula:

```
min({back}, {feet}, {head})
```

With Back 11, Feet 8 and Head 9, grit is 8. Raise Feet by one and grit becomes 9, the Head. The field is not typed in and it cannot go out of date.

### Hard Country: breath left

A second example reads a Track:

```
{wind.max} - {wind}
```

With a Wind maximum of 8 and a current Wind of 3, this is 5, the breath the bearer has lost. It follows Wind as the bearer's Wind changes.

### Dogwatch: ship pressure

A Formula can read a Tracker. With Threat at 6 and a Clues counter at 2, a derived field with `max({tracker:threat} - {tracker:clues}, 0)` gives 4: how much the ship is ahead of the crew, never below nothing. Use this sort of field when you want a number from the campaign on the sheet. Remember that a flow step cannot read it back. A step condition reads Trackers, not sheet fields, so put the rule in the Tracker if a step must branch on it.

## When not to use one

A derived value earns its place when a rule says "your X is Y plus Z" and the player would otherwise do the sum at the table. If the sum is simple and used once, it is a line in a tip. If it needs a choice, a Formula cannot give it.

## Try it

Write one derived value for your own game as a sentence: "Your ____ is ____". Rewrite it as a Formula using only the parts in the table. If you need a word the table does not have, the rule is not a Formula yet.
