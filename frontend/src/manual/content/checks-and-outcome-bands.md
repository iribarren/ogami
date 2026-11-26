# Checks and Outcome Bands

Every tabletop game has a moment when a character tries something and a die decides how it goes. A **Check** is that moment written down as a rule, and its work is to turn a roll into a meaning, much as a teacher turns a mark out of a hundred into a pass or a fail. You choose what is rolled and cut the possible results into **outcome bands**, and a flow then decides what each band costs or gives. The Checks of a GameSystem live in Studio's **Checks** section, and a `check` step of a flow is where the player is offered them.

Keep those two jobs apart. The Check says what the numbers mean, as in "a 1 is clean, at or under Back is a success", and the step says what happens because of them, such as Winter climbing and Wind falling.

## Dice notation

A Check rolls dice written in notation, the short form in which `1d20` stands for one twenty-sided die.

| Written     | Means                                                     |
| ----------- | --------------------------------------------------------- |
| `1d20`      | One twenty-sided die                                      |
| `2d6+1d4-2` | Two d6 and a d4, minus 2                                  |
| `(1d6+2)*3` | Brackets and `*`, `/` work as usual; division rounds down |
| `4d6kh3`    | Roll four d6 and keep the highest three                   |
| `2d8kh1`    | Roll two d8 and keep the highest one                      |

There are four selectors, `kh` to keep the highest dice, `kl` to keep the lowest, `dh` to drop the highest and `dl` to drop the lowest, and each of them applies to one dice term only. A notation is at most 100 characters long, rolls at most 100 dice and uses dice of 2 to 1,000 sides. Studio checks the notation when you save the Check.

Studio also offers two other roll types. A dice pool counts the dice that succeed, and challenge dice count the dice that a score beats. Since neither of the GameSystems in this book uses them, this chapter covers the plain dice roll.

### The notation does not read the sheet

The dice notation is fixed text, so it cannot say "roll the die written in the Helm field". This is a real constraint, and Dogwatch is built around it.

In Dogwatch each Station has a die, a d4, d6, d8 or d10, and that die is stored in a Select field, which no Formula can read. Dogwatch therefore has eight Checks, one for every die size in a plain and a helped version.

| Check                                       | Dice                                    |
| ------------------------------------------- | --------------------------------------- |
| `d4`, `d6`, `d8`, `d10`                     | `1d4`, `1d6`, `1d8`, `1d10`             |
| `d4-help`, `d6-help`, `d8-help`, `d10-help` | `2d4kh1`, `2d6kh1`, `2d8kh1`, `2d10kh1` |

When a roll comes up, the player looks at the die of their Station on the sheet and picks the Check that matches it. A helped Check rolls two dice and keeps the highest, which is the one case where `kh` is the right tool.

Hard Country goes the other way. It has one die, a d20, for all three of its Checks, and the trait goes into the bands.

## Bands

A band is a key, a label and an upper bound, and the bands of a Check are in order. A roll belongs to the first band whose bound it does not pass, while the last band has no bound and catches whatever is left.

A bound can be one of four kinds.

- **None**, for the last band.
- **A number**, such as `1` or `19`.
- **A Tracker**, read as its value.
- **A Formula**, so that a band can follow the sheet or the Difficulty.

Hard Country's Back Check uses all of them except the Tracker.

| Band      | Label         | Up to    |
| --------- | ------------- | -------- |
| `clean`   | Clean success | 1        |
| `success` | Success       | `{back}` |
| `failure` | Failure       | 19       |
| `badly`   | Goes badly    | none     |

The roll is `1d20`. A 1 is clean, a roll up to the Back value is a success, a roll above it and up to 19 is a failure, and a 20 goes badly. Because the trait sits in the bound as a Formula, the same Check works for any bearer, and Feet and Head are the same Check with `{feet}` and `{head}`.

The Back Check holds one trap, which comes from the bands being cut from the bottom. The Back field caps at 18, so the best bearer has Success up to 18 and fails only on a 19. If a trait could reach 19, the Failure band would be empty, and a 20 would still go badly. Studio does not refuse a band that cannot be reached, so test the extremes in the sandbox.

### Difficulty

A Check can ask the player for a **Difficulty** at roll time, in the way a game master might tell you how hard a lock is to pick before you try it. Studio declares its label, its default and its range, and a band reads it as `{check:difficulty}`. Dogwatch's Checks declare a Difficulty from 2 to 8, with a default of 3, and use three bands.

| Band    | Label       | Up to                  |
| ------- | ----------- | ---------------------- |
| `wrong` | Goes wrong  | 1                      |
| `short` | Falls short | `{check:difficulty}-1` |
| `done`  | Done        | none                   |

A 1 always goes wrong, a roll under the Difficulty falls short, and a roll at or over it is done. With a d6 and Difficulty 4, the rolls 2 and 3 fall short and 4 to 6 are done.

The limits of this show at the edges. At Difficulty 2 nothing falls short, because the only roll below 2 is a 1, and a 1 already goes wrong. At the other end, a d4 can never reach a Difficulty above 4. That is a design choice to make knowingly, since a range of 2 to 8 lets a d10 take on a hard job and leaves a d4 no hope of it.

## Check sets

A `check` step offers two to ten Checks to choose from. Together they are a **Check set**, and they must share band keys. The step then has one outcome per band, whichever Check the player picks.

Dogwatch offers its eight every time, since Investigate, Repair, Confront, Hide and The Reveal each list all eight, and the bands are `wrong`, `short` and `done` for every one of them. A step does not need an outcome for every band, though. Repair has none for `short`, so in that step the band costs nothing.

The Long Carry's Ford offers Back or Feet. Both Checks have the bands `clean`, `success`, `failure` and `badly`, and the step gives outcomes for `failure`, where Winter goes up one and you lose d6 Wind, and for `badly`, where Burden goes up one and you lose d6 Wind.

The effects of an outcome are the same kinds you met in Part I, which move a Tracker or a sheet field. The dice value `-1d6` on Wind is rolled when the band is reached.

## Testing a Check

The Checks section has a **Test roll** sandbox. It rolls the saved Check with sample values for the sheet and the Trackers and shows where the bounds fall for those values, without recording anything. Sample the extremes, which means the lowest trait, the highest and the Difficulty at both ends, and look for a band you can never reach.

## Try it

Pick a roll in your game and write its bands as a table on an index card, or a page of your notebook, giving each band a key, a label and an "up to". Test it with the lowest and the highest values the sheet allows. Then decide what the flow does for each band, and write "nothing" where nothing happens.
