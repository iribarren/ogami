# Cold Berth, Complete

_What is aboard, who let it on, and why were we woken?_

::image{file="dw-berth-deck.webp" style="dogwatch" size="banner" alt="A long berth deck at half-light with rows of closed berths and one open lid." subject="A long berth deck at half-light seen down its length, rows of closed berths on both sides, a single lid standing open."}

Cold Berth is played on Dogwatch, over two to three sessions. This page sets out the whole flow on one spread. Its cards are printed separately.

## The premise

You play a small crew woken from suspension on a ship that should still be asleep. Since you play alone, you are the one who describes what happens, rolls the dice and decides.

The ending is chosen by the two numbers that keep score. **Threat** is a clock of eight boxes, and the nearer it comes to full, the nearer the ship is to showing what it is. **Clues** count what you have pieced together.

## The Phases

| Phase            | Run    | Selection  | What happens                                               |
| ---------------- | ------ | ---------- | ---------------------------------------------------------- |
| **Session Zero** | once   | `sequence` | The crew, the ship, the Truths                             |
| **Wake**         | once   | `sequence` | The empty berth, the custodian's refusal                   |
| **The Ship**     | `loop` | `player`   | Pick a job and a compartment, play it, then the World turn |
| **Reveal**       | once   | `sequence` | Open the Truths                                            |
| **Epilogue**     | once   | `sequence` | Who gets out, by Clues and Threat                          |

::flow-diagram{flow="cold-berth"}

### The Ship

Lay the four job cards face up. Then play each round in this order.

1. Pick one job (Investigate, Repair, Confront or Hide) and choose a compartment card for it. If none is left, name a new compartment. Then play the job.
2. Read the closing check. If Threat is 4 or less, play on. From 5 to 7, roll a d6, and a 5 or 6 ends **The Ship** and brings the **Reveal**. At 8, **The Ship** ends.
3. Unless the closing check ended **The Ship**, do the World turn. Threat climbs by one, so mark a box and write what has changed on the ship. Then roll Ship trouble. After that the ship takes a crew member from the manifest at random, and you cross them off, write where they were last seen and start a Thread to find them.

## The Scene Types

| Scene Type      | Purpose                                                               |
| --------------- | --------------------------------------------------------------------- |
| The crew        | Make the people who were woken                                        |
| The ship        | Say what the ship is, who else is on it, and where you can go         |
| The Truths      | Set down what you fear, before the ship answers                       |
| The empty berth | Wake, and find that someone is gone                                   |
| The custodian   | Ask the ship why you were woken, and be refused                       |
| Investigate     | Look closely at one part of the ship, and learn what is wrong with it |
| Repair          | Fix something that must work, and pay for what it takes               |
| Confront        | Stand in front of the thing or person you have been avoiding          |
| Hide            | Get out of sight and wait for it to pass                              |
| The Reveal      | Open the three Truths, and see how near you were                      |
| Epilogue        | Read who gets out                                                     |

Each job opens by picking a compartment, which is one of the Places you made in **Session Zero**, or a new one you name when none is left.

## Trackers

| Tracker | Kind    | Range  | Moves                                                                                                                                                                                                                       |
| ------- | ------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Threat  | clock   | 8      | Up one every World turn, by any check that goes wrong, by a check that falls short in Investigate, Hide or the Reveal, by pushing the custodian and by some Ship trouble and Treason results; a Repair that works lowers it |
| Clues   | counter | 0 to 8 | Up by an Investigation or a Confrontation that works, and by some Treason results                                                                                                                                           |

## Fact slots

- **What is aboard:** the first Truth.
- **Who let it on:** the second Truth.
- **Why we were woken:** the third Truth.

## The Truths

The Truths are sealed. In **Session Zero**, roll each one on its table, write the result on a slip and seal the slips without reading them. At the **Reveal**, open them and write the one story that makes all three true.

Treason is a fourth table, which you may roll in an Investigate scene when a person is part of it. Some of its results name a suspect, add a Clue or raise Threat, and some change nothing.

## The endings

::image{file="dw-frosted-hatch.webp" style="dogwatch" size="spot" alt="A closed hatch with a frosted porthole." subject="A closed bolted hatch with a small porthole furred with frost, in dim corridor light."}

Threat at 5 or less counts as contained and anything higher as boiling over, while Clues at 2 or less count as little and anything higher as plenty.

| Threat       | Clues  | Ending                    |
| ------------ | ------ | ------------------------- |
| Contained    | Plenty | Everyone out, and knowing |
| Contained    | Little | Out, and none the wiser   |
| Boiling over | Plenty | Out, but not all of you   |
| Boiling over | Little | The ship keeps you        |

:::in-ogami
The cards for this flow are a separate PDF: [Cold Berth cards](https://github.com/iribarren/ogami/releases/download/manual-1.0/cards-cold-berth.pdf).

In Ogami the flow is the preset `dogwatch`, flow `cold-berth`, and the three Truths are rolled at the Reveal instead of sealed in Session Zero.
:::
