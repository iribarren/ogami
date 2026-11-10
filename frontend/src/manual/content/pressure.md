# Pressure

A good game master does a third thing, after pacing and surprise: they make waiting cost something. If the players dither, the guards arrive. If they rest too long, the snow comes. Nobody has to be cruel. The world just keeps moving whether the players are ready or not.

When you play alone, you are not going to be cruel to yourself. You need numbers that do it for you.

## Trackers

A **Tracker** is a number the flow keeps. There are two kinds.

- A **clock** has a fixed number of segments and fills one at a time. It only ever marks time toward something. Winter is a clock of ten segments. Threat is a clock of eight.
- A **counter** moves up and down inside a range and can name its stretches. Burden counts from 0 to 6 and calls itself Light, Heavy, Crushing and Breaking as it climbs. Clues counts from 0 to 8 and calls itself Blind, Hints, A shape and The whole picture.

Use a clock when something is coming, and a counter when something is building. Winter is coming, and you cannot stop it. Burden is building, because you decided to take the favour.

Give every Tracker a **hint**, one line of what it measures. A bare number is a number you stop reading.

## The World turn

Between scenes the world gets a turn. It is a short step, with two or three parts, that you do every round without exception.

1. **Tick the clock.** Add one to the main clock. This is the part that makes waiting cost.
2. **Roll the events table.** One table, rolled every time. It may add to a clock, send you into a scene, or do nothing.
3. **Take something**, if the flow does. Cold Berth picks a crew member each round.

The World turn is why a loop ends. Without it, a player who plays well could stay in the Phase for ever. With it, the clock is always moving, whether or not the player makes a mistake.

## Random events

An event table is a promise that the world has opinions. Make it mostly quiet: in both flows, three entries in nine say that nothing happens. Quiet results matter. If something happened every round, nothing would stand out, and the player would stop reading the result.

The rest of the table does one of two jobs, or both at once.

- **Tick:** a hard frost, a hatch that seals itself, a bad foot. A small cost, and a number moves.
- **Force:** a ford has risen, a sound in the wall, a voice calls from a locked room. The next scene is chosen for you, and it is a scene you did not choose.

## How a clock ends a Phase

A clock decides when a Phase closes. The loop in The Arc's chapter reads the clock at the end of every scene: low, play on; in the middle, roll a die; full, stop. The exact bands are a design choice, and the two flows make different ones.

| Flow           | Clock  | Plays on | Rolls a d6 | Ends for certain |
| -------------- | ------ | -------- | ---------- | ---------------- |
| The Long Carry | Winter | 0 to 5   | 6 to 9     | 10               |
| Cold Berth     | Threat | 0 to 4   | 5 to 7     | 8                |

The roll is a 5 or a 6 on a d6, a one in three chance. A scene ends, the clock is read, and the world turn adds one before the next. That gives the length of the loop. In the Long Carry, if nothing else touches Winter, the road is at least seven scenes and at most eleven. In Cold Berth it is six to nine, and the scenes themselves push Threat: a failed Investigate adds one, and a Repair that is done takes one away.

When the closing ends a Phase, the world does not turn for that scene. The Phase closes instead.

## On paper

Draw each Tracker at the top of the page.

- A **clock** is a row of boxes. Put a mark by the first box where the dice start to be rolled, so you see it coming.
- A **counter** is a line with numbers on it. Write the names of its stretches under it.

Keep a **World turn card** beside the page: a checklist you read after every scene.

1. Mark one box on the clock.
2. Roll the events table.
3. Do what the table says, and mark any other change.

Keep a second card for the closing check: "Read the clock. If low, go on. If in the middle, roll a d6: a 5 or 6 ends the Phase. If full, it ends." Read both cards after every scene. Do not skip a turn because the scene went well.

:::in-ogami
In Studio, **New tracker** on the **Trackers & tags** page asks for a **Key**, a **Name**, a **Hint** and a **Kind**: **Counter** or **Clock**. A clock has **Segments**. A counter has **Min**, **Max**, **Initial** and optional levels. The world turn is a list of steps in the Phase's outline, under **World turn**, beside **Scene closing**. Its steps can add to a Tracker and roll a table. Put an `endPhase` effect on a **condition** band to end the Phase.

In Play, the **Trackers** panel shows each Tracker as filled and empty boxes, with its level label and its hint. You can change a value by hand with the minus and plus buttons, or by typing a number and pressing **Set**. The world turn is recorded in the journal as a **World turn** entry.
:::

## The Long Carry

Winter is the road's clock, and the world turn's first step is to move it. _Winter closes by one. What is different about the light today?_ is a question as well as a tick: the light changes, and you write how.

Then the world turn rolls Road Risks. Of its nine chances, three are quiet. One ticks Winter (the hard frost), one adds to Burden (the bad foot), three send you to a scene (a Stranger, a Ruin or an Omen), and the risen ford does both: a Ford scene and a tick.

A played turn. Winter was 8 when the last scene closed. The closing check said "Near", and a d6 gave a 3: not yet.

> The days shorten. Winter closes by one: nine. "The light is gone before we have finished eating."
>
> Road Risks: _A hard frost in the night. Everything you own is stiff by morning._ Winter closes by one more: ten.

The next scene will close with Winter at its last box, and the first snow ends the Road without a roll. The player did nothing wrong. The road got shorter by itself.

## Cold Berth

Threat is the ship's patience. The watch turns, Threat climbs by one, and the ship may make trouble. Then the third part of the turn: the ship picks a crew member from the manifest and takes them. The scene that follows asks where they were last seen. The clock is not the only pressure here. The manifest is a second clock, shorter each round.

A played turn. Threat is 3.

> The watch turns: four. Ship trouble: _Someone is standing where nobody was a moment ago._ The ship sends the next scene: a Confront.
>
> The pick takes Okafor. "Okafor is not where they were."

Two things went wrong at once, and neither was a mistake. A Confront is coming, and one fewer person can help with it.

## Try it

Choose the clock that ends your loop. Decide how many segments it has, where the die starts, and which events tick it. Then write the events table with nine chances, three of them quiet.
