# Derived Values and Formulas

Some numbers on a sheet are not typed in, because they follow from other numbers, as a modifier follows from a trait or a limit from a score. A spreadsheet handles numbers of this kind with a cell that holds a sum instead of a value and shows the result whenever you look at it. On a sheet, that kind of cell is a **derived value**, which is a sheet field of the Derived type. It has no stored number, only a **Formula**, and the number is worked out each time it is shown.

Formulas are at work in other places too, in a Track's maximum at creation, a Check's modifier and the bounds of an outcome band. This page covers the Formula itself and the derived field, and _Checks and Outcome Bands_ shows the other uses.

## What a Formula can say

A Formula is a whole-number expression, built from these parts.

| Part              | Written as                                                    |
| ----------------- | ------------------------------------------------------------- |
| Numbers           | `0` to `1000000`, written without a sign                      |
| Arithmetic        | `+`, `-`, `*`, `/`, with the usual order, and brackets        |
| Negation          | `-` in front of a part: `-{wounds}`                           |
| Smallest, largest | `min(a, b, ...)` and `max(a, b, ...)`, with 2 to 10 arguments |
| A field           | `{back}`                                                      |
| A Track's maximum | `{wind.max}`                                                  |
| A Tracker         | `{tracker:winter}`                                            |

Division rounds down, so `7/2` is 3 and `-7/2` is -4. A Formula is at most 200 characters long, and no step of it may pass a billion in either direction. A division by zero is an error when the Formula is worked out.

There are no decimals, no conditions, no `if`, no rounding functions and no text. A Formula that breaks the grammar is refused when you save the field, and again when you publish.

## What it can read

A derived field can read:

- Number fields, as `{key}`.
- Other derived fields.
- A Track's current value as `{key}` and its maximum as `{key.max}`.
- Conditions fields, which read as the number of conditions marked.
- Trackers, as `{tracker:key}`.

It cannot read Text, List, Select or Inventory fields. A Select such as a Dogwatch Station gives no number, as the hint on the field says, and Formulas do not read an Inventory either. That is why a rule like "your carrying limit is your Back" cannot be written as a Formula over the Load, since the capacity is a fixed number on the Inventory.

A Track's start maximum is more restricted still. It is worked out when the Character is created, before the Character has any Track or Tracker value, so it can read only Number and Derived fields.

As you type the Formula of a derived field, Studio shows what the Formula reads and what it computes to with sample values. A reference to a key that does not exist, or to one the field may not read, is shown as an error before you save, and so is a cycle, because a derived value cannot depend on itself, even through another one.

## Honest limit: no preset uses one

Neither Hard Country nor Dogwatch has a derived value, a Formula-sized maximum or a Check modifier. Hard Country keeps its traits as plain Numbers and compares the roll against them in the Check's bands, and the only Formulas in the two presets are band bounds, `{back}`, `{feet}` and `{head}` in Hard Country and `{check:difficulty}-1` in Dogwatch. The examples below were written for this chapter, and you will not find them in either preset.

### Hard Country: grit

Suppose a bearer's staying power were the worst of their three traits. You would add a Derived field `grit` with this Formula.

```
min({back}, {feet}, {head})
```

With Back 11, Feet 8 and Head 9, grit is 8, and if Feet goes up by one, grit becomes 9, the Head. Nobody types the field in, so it cannot go out of date.

### Hard Country: breath left

A second example reads a Track.

```
{wind.max} - {wind}
```

With a Wind maximum of 8 and a current Wind of 3, this is 5, which is the breath the bearer has lost, and it follows Wind as the bearer's Wind changes.

### Dogwatch: ship pressure

A Formula can read a Tracker as well. With Threat at 6 and the Clues counter at 2, a derived field holding `max({tracker:threat} - {tracker:clues}, 0)` gives 4, which is how far the ship is ahead of the crew, and the `max` keeps it from dropping below nothing.

Use this sort of field when you want a number from the campaign on the sheet, but remember that a flow step cannot read it back. A step condition reads Trackers and not sheet fields, so if a step must branch on the number, put the rule in the Tracker.

## When not to use one

A derived value is worth having when a rule says "your X is Y plus Z" and the player would otherwise do the sum at the table. There are two cases where it is not. A simple sum that is used once can stay a line in a tip. When the rule needs a choice, a Formula cannot give it.

## Try it

Write one derived value for your own game as a sentence of the form "Your ____ is ____", then rewrite it as a Formula using only the parts in the table. If you need a word the table does not have, the rule is not a Formula yet.
