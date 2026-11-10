# Checks and Outcome Bands

A **Check** turns a roll into a meaning. You choose what is rolled, you cut the possible results into **outcome bands**, and a flow decides what each band costs or gives. Studio's **Checks** section holds the Checks of a GameSystem, and a `check` step of a flow offers them.

Keep the two jobs apart. The Check says what the numbers mean: "a 1 is clean, at or under Back is a success". The step says what happens: Winter climbs, Wind falls.

## Dice notation

A Check rolls dice written in notation.

| Written     | Means                                                     |
| ----------- | --------------------------------------------------------- |
| `1d20`      | One twenty-sided die                                      |
| `2d6+1d4-2` | Two d6 and a d4, minus 2                                  |
| `(1d6+2)*3` | Brackets and `*`, `/` work as usual; division rounds down |
| `4d6kh3`    | Roll four d6 and keep the highest three                   |
| `2d8kh1`    | Roll two d8 and keep the highest one                      |

The selectors are `kh` keep highest, `kl` keep lowest, `dh` drop highest and `dl` drop lowest, and each applies to one dice term only. A notation is at most 100 characters, rolls at most 100 dice and has dice of 2 to 1,000 sides. Studio checks the notation when you save the Check.

Studio also offers two other roll types, a dice pool that counts the dice which succeed and challenge dice that count the dice a score beats. Neither of this book's GameSystems uses them, so this chapter covers the plain dice roll.

### The notation does not read the sheet

The dice notation is fixed text. It cannot say "roll the die written in the Helm field". This is a real constraint, and Dogwatch is built around it.

Dogwatch gives each Station a die: d4, d6, d8 or d10. The die is stored in a Select field, which no Formula can read. So Dogwatch has eight Checks, one for every die size, plain and helped:

| Check                                       | Dice                                    |
| ------------------------------------------- | --------------------------------------- |
| `d4`, `d6`, `d8`, `d10`                     | `1d4`, `1d6`, `1d8`, `1d10`             |
| `d4-help`, `d6-help`, `d8-help`, `d10-help` | `2d4kh1`, `2d6kh1`, `2d8kh1`, `2d10kh1` |

The player looks at their Station's die on the sheet and picks the Check that matches. Helped means two dice, keep the highest, which is the one case where `kh` is the right tool.

Hard Country goes the other way. It has one die, a d20, for all three Checks, and the trait goes into the bands.

## Bands

A band is a key, a label and an upper bound. The bands are in order. A roll belongs to the first band whose bound it does not pass, and the last band has no bound and catches the rest.

A bound can be:

- **None**, for the last band.
- **A number**: `1`, `19`.
- **A Tracker**, read as its value.
- **A Formula**, so a band can follow the sheet or the Difficulty.

Hard Country's Back Check uses all of these but the Tracker.

| Band      | Label         | Up to    |
| --------- | ------------- | -------- |
| `clean`   | Clean success | 1        |
| `success` | Success       | `{back}` |
| `failure` | Failure       | 19       |
| `badly`   | Goes badly    | none     |

The roll is `1d20`. A 1 is clean. Up to the Back value is a success. Above it, up to 19, is a failure, and a 20 goes badly. The trait is a Formula in the bound, so the same Check works for any bearer. Feet and Head are the same Check with `{feet}` and `{head}`.

One trap: bands are cut from the bottom. The Back field caps at 18, so the best bearer has Success up to 18 and fails only on a 19. If a trait could reach 19, the Failure band would be empty, and a 20 would still go badly. Studio does not refuse a band that cannot be reached. Test the extremes in the sandbox.

### Difficulty

A Check can ask the player for a **Difficulty** at roll time. Studio declares its label, default and range. A band reads it as `{check:difficulty}`. Dogwatch's Checks declare a Difficulty from 2 to 8, default 3, and use three bands:

| Band    | Label       | Up to                  |
| ------- | ----------- | ---------------------- |
| `wrong` | Goes wrong  | 1                      |
| `short` | Falls short | `{check:difficulty}-1` |
| `done`  | Done        | none                   |

A 1 always goes wrong. Under the Difficulty falls short. At or over it is done. With a d6 and Difficulty 4, the rolls 2 and 3 fall short and 4 to 6 are done.

The limits show at the edges. At Difficulty 2, nothing falls short: the only roll below 2 is a 1, and a 1 is already Goes wrong. And a d4 can never reach a Difficulty above 4. That is a design choice to make knowingly. The range 2 to 8 lets a d10 take on a hard job and leaves a d4 no hope of it.

## Check sets

A `check` step offers two to ten Checks to choose from. They are a **Check set**, and they must share band keys. The step then has one outcome per band, whichever Check the player picks.

- Dogwatch offers its eight, every time: Investigate, Repair, Confront, Hide and the Reveal each list all eight. The bands are `wrong`, `short` and `done` for every one. A step does not need an outcome for every band: Repair, for instance, has none for `short`, so that band costs nothing.
- The Long Carry's Ford offers Back or Feet. Both have the bands `clean`, `success`, `failure` and `badly`, and the step gives outcomes for `failure` (Winter up one, lose d6 Wind) and `badly` (Burden up one, lose d6 Wind).

The outcome effects are the same kinds you met in Part I. They move a Tracker or a sheet field. The dice value `-1d6` on Wind is rolled when the band is reached.

## Testing a Check

The Checks section has a **Test roll** sandbox. It rolls the saved Check with sample values for the sheet and the Trackers, shows where the bounds fall for those values, and records nothing. Sample the extremes: the lowest trait, the highest, the Difficulty at both ends. Look for a band you can never reach.

## Try it

Pick a roll in your game and write its bands on a card as a table: key, label, up to. Test it with the lowest and the highest values the sheet allows. Then decide, for each band, what the flow does, and write "nothing" where nothing happens.
