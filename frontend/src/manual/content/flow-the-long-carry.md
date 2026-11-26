# The Long Carry, Complete

_Will you get the saint to the shrine before first snow, and what will it cost?_

The Long Carry is played on Hard Country, over three to four sessions. This page sets out the whole flow on one spread. Its cards are printed separately.

## The premise

You play a small party of bearers carrying a saint's body across hard country to a shrine, before the first snow closes the passes. Nobody else is playing, so you describe what happens, roll the dice and make every decision.

The ending is chosen by the two numbers that keep score. **Winter** is a clock of ten boxes. The nearer it comes to full, the nearer the first snow is. **Burden** counts what you took on along the way, such as favours owed, things taken and things said aloud.

## The Phases

| Phase               | Run    | Selection  | What happens                                                                |
| ------------------- | ------ | ---------- | --------------------------------------------------------------------------- |
| **Session Zero**    | once   | `sequence` | The bearers, the saint, the vow                                             |
| **The Road**        | `loop` | `oracle`   | A scene from the road deck, then the World turn, until the gate is in sight |
| **The Shrine Gate** | once   | `sequence` | The arrival                                                                 |
| **Epilogue**        | once   | `sequence` | The ending, chosen by Winter and Burden                                     |

### The Road

Shuffle the road deck, and seed the Shrine Gate card into its bottom five cards. Then play each round in this order.

1. Turn the top card and play its Scene Type.
2. Read the closing check. If Winter is 5 or less, play on. From 6 to 9, roll a d6, and a 5 or 6 ends **The Road**. At 10, **The Road** ends.
3. Unless the closing check ended **The Road**, do the World turn. Winter closes by one, so mark a box and write how the light is different today. Then roll Road Risks.

**The Road** also ends when the Shrine Gate card turns up.

## The Scene Types

| Scene Type      | Purpose                                                              |
| --------------- | -------------------------------------------------------------------- |
| The bearers     | Make the people who will carry the saint                             |
| The saint       | Say whose body you carry and where it is going                       |
| The vow         | Say what you promised and what you will pay if you break it          |
| Camp            | Stop, rest and say what cannot be said while walking                 |
| Ford            | Cross water, and learn what the crossing costs                       |
| Stranger        | Meet someone who wants something from the road, or from you          |
| Ruin            | Find what earlier travellers left, and decide whether to take it     |
| Omen            | Let the road say something about the saint, and decide what it means |
| The Shrine Gate | Arrive, and be asked what you carried                                |
| Epilogue        | Read what the road made of you                                       |

The road deck holds thirteen cards: three Camps, two Fords, three Strangers, two Ruins and three Omens.

## Trackers

| Tracker | Kind    | Range  | Moves                                                                                                                              |
| ------- | ------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| Winter  | clock   | 10     | Up one every World turn, by some Road Risks and by a Ford check that fails                                                         |
| Burden  | counter | 0 to 6 | Up by hard choices and a bad foot, by a Ford check that goes badly, and at the Shrine Gate by 1 on a failure or 2 if it goes badly |

## Fact slots

- **The saint:** whose body you carry. Written in **Session Zero**.
- **The Sin:** what the saint did, as you now believe it. Each Omen can change it. The last Sin you write is the Truth.
- **The vow:** what you promised, and to whom. Written in **Session Zero**.

## The tables

Road Risks is rolled in every World turn. Its quiet result is the most common, coming up one roll in three. Every other result either costs a point of Winter or Burden or forces a Ford, a Stranger, a Ruin or an Omen as the next scene, except the risen ford, which does both. It adds a point of Winter and makes the next scene a Ford, and it calls for a Back check. Three more tables, Strangers, Omens and Ruins, give ideas for the scenes they are named after.

## The Truth

The Truth is emergent, which means that nobody tells you what the saint did. Instead, each Omen asks what you now believe, and in answer you write the Sin again.

## The endings

The ending is chosen by Winter and Burden rather than by you. You are in time if Winter is 7 or less, and light if Burden is 2 or less.

| Winter  | Burden | Ending                    |
| ------- | ------ | ------------------------- |
| In time | Light  | Laid down in time         |
| In time | Heavy  | Laid down at a price      |
| Late    | Light  | Laid down at a price      |
| Late    | Heavy  | The carry is not finished |

Your part is the aftermath, where you say what each bearer does next.

:::in-ogami
The cards for this flow are a separate PDF: [The Long Carry cards](https://github.com/iribarren/ogami/releases/download/manual-1.0/cards-the-long-carry.pdf).

In Ogami the flow is the preset `hard-country`, flow `the-long-carry`, and you can open every piece of it in Studio.
:::
