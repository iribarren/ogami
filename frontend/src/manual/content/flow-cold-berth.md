# Cold Berth, Complete

_What is aboard, who let it on, and why were we woken?_

Cold Berth is played on Dogwatch, in two to three sessions. This page is the whole flow on one spread. The cards are printed separately.

## The premise

You play a small crew woken from suspension on a ship that should still be asleep. You are the whole table: you describe, you roll, you decide.

Two numbers keep score. **Threat** is a clock of eight boxes: the nearer it is to full, the nearer the ship is to showing what it is. **Clues** count what you have pieced together. Together they choose the ending.

## The Phases

| Phase        | Run    | Selection  | What happens                                               |
| ------------ | ------ | ---------- | ---------------------------------------------------------- |
| Session Zero | once   | `sequence` | The crew, the ship, the Truths                             |
| Wake         | once   | `sequence` | The empty berth, the custodian's refusal                   |
| The Ship     | `loop` | `player`   | Pick a job and a compartment, play it, then the World turn |
| Reveal       | once   | `sequence` | Open the Truths                                            |
| Epilogue     | once   | `sequence` | Who gets out, by Clues and Threat                          |

### The Ship

Lay the four job cards face up. Each round:

1. Pick one job: Investigate, Repair, Confront or Hide. Choose a compartment card for it, or name a new one, and play it.
2. Do the World turn. Threat climbs by one: mark a box and write what has changed on the ship. Roll Ship trouble. The ship then takes a crew member from the manifest at random: cross them off, write where they were last seen and start a Thread to find them.
3. Read the closing check. Threat 4 or less: play on. 5 to 7: roll a d6, and a 5 or 6 brings the Reveal. 8 ends it.

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

Each job opens by picking a compartment, a Place made in Session Zero.

## Trackers

| Tracker | Kind    | Range  | Moves                                                                              |
| ------- | ------- | ------ | ---------------------------------------------------------------------------------- |
| Threat  | clock   | 8      | Up one every World turn and by checks that go wrong; a Repair that works lowers it |
| Clues   | counter | 0 to 8 | Up by an Investigation or a Confrontation that works, and by some Treason results  |

## Fact slots

- **What is aboard:** the first Truth.
- **Who let it on:** the second Truth.
- **Why we were woken:** the third Truth.

## The Truths

The Truths are sealed. In Session Zero, roll each on its table, write the result on a slip and seal the slips without reading them. At the Reveal, open them and write the one story that makes all three true.

Treason is a fourth table, rolled in Investigate scenes. Its results name a suspect and add a Clue, or raise Threat.

## The endings

Threat 5 or less is contained, more is boiling over. Clues 2 or less is little, more is plenty.

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
