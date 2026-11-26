# Pressure

Part of setting the pace, the first of the things The Promise says a game master does, is making waiting cost something. If the players dither, the guards arrive, and if they rest too long, the snow comes. Nobody has to be cruel for that to happen, because the world keeps moving whether the players are ready or not.

When you play alone you are not going to be cruel to yourself, so the flow keeps its pace with numbers that do it for you.

## Trackers

Most board games keep a few numbers beside the board, such as a score or the round you are in, and a **Tracker** is the flow's version of them, a number the flow keeps.

A Tracker comes in two kinds. A **clock** has a fixed number of segments and fills one at a time, and it only ever marks time toward something. Winter is a clock of ten segments, and Threat a clock of eight. A **counter** moves up and down inside a range and can name its stretches. Burden counts from 0 to 6 and calls itself Light, Heavy, Crushing and Breaking as it climbs, while Clues counts from 0 to 8 and calls itself Blind, Hints, A shape and The whole picture.

Use a clock when something is coming, and a counter when something is building. Winter is coming, and you cannot stop it, whereas Burden builds because you decided to take the favour.

Give every Tracker a **hint**, one line saying what it measures, because a bare number is a number you stop reading.

## The World turn

Between scenes the world gets a turn of its own. The World turn is short, with two or three parts, and you play it every round without exception.

1. **Tick the clock.** Add one to the main clock. This is the part that makes waiting cost.
2. **Roll the events table.** It is one table, rolled every time, and it may add to a clock, send you into a scene or do nothing.
3. **Take something**, if the flow does. Cold Berth picks a crew member each round.

The World turn is also why a loop ends. Without it, a player who plays well could stay in the Phase for ever, whereas with it the clock is always moving, whether or not the player makes a mistake.

## Random events

An event table is where the world acts on its own account. Make its quiet result the most common single result, as both flows do by giving the quiet entry a weight of 3, which is three of the nine chances. Quiet results matter, because if something happened every round nothing would stand out, and the player would stop reading the result.

The rest of the table does one of two jobs, or both at once.

- **Tick.** A hard frost, a hatch that seals itself or a bad foot costs a little, and a number moves.
- **Force.** A ford that has risen, a sound in the wall or a voice calling from a locked room chooses the next scene for you.

## How a clock ends a Phase

A clock decides when a Phase closes. The loop that The Arc described reads the clock at the end of every scene, and plays on while the clock is low, rolls a die when it is in the middle and stops when it is full. The exact bands are a design choice, and the two flows make different ones.

| Flow           | Clock  | Plays on | Rolls a d6 | Ends for certain |
| -------------- | ------ | -------- | ---------- | ---------------- |
| The Long Carry | Winter | 0 to 5   | 6 to 9     | 10               |
| Cold Berth     | Threat | 0 to 4   | 5 to 7     | 8                |

The roll needs a 5 or a 6 on a d6, which is a one in three chance. After each scene the clock is read first, and only then does the World turn add one before the next scene begins, so the bands also set how long the loop can last. In The Long Carry, if nothing else touches Winter, **The Road** lasts at least seven scenes and at most eleven. In Cold Berth, if nothing else touches Threat, **The Ship** lasts six to nine, but there the scenes themselves push Threat. A failed Investigate, for example, adds one, and a Repair that is done takes one away.

When the closing ends a Phase, the world does not turn for that scene, and the Phase closes instead.

## On paper

Draw each Tracker at the top of the page. A **clock** is a row of boxes, and you put a mark by the first box where the dice start to be rolled, so that you see it coming. A **counter** is a line with numbers on it, with the names of its stretches written under it.

Keep a **World turn card** beside the page, on an index card or a page of your notebook, as a checklist you read after every scene.

1. Mark one box on the clock.
2. Roll the events table.
3. Do what the table says, and mark any other change.

Keep a second card of the same kind for the closing check, with this on it: "Read the clock. If low, go on. If in the middle, roll a d6: a 5 or 6 ends the Phase. If full, it ends." Read both cards after every scene, and do not skip a turn because the scene went well.

:::in-ogami
In Studio, **New tracker** on the **Trackers & tags** page asks for a **Key**, a **Name**, a **Hint** and a **Kind**: **Counter** or **Clock**. A clock has **Segments**. A counter has **Min**, **Max**, **Initial** and optional levels. The World turn is a list of steps in the Phase's outline, under **World turn**, beside **Scene closing**. Its steps can add to a Tracker and roll a table. Put an `endPhase` effect on a **condition** band to end the Phase.

In Play, the **Trackers** panel shows each Tracker as filled and empty boxes, with its level label and its hint. You can change a value by hand with the minus and plus buttons, or by typing a number and pressing **Set**. The World turn is recorded in the journal as a **World turn** entry.
:::

## The Long Carry

Winter is the road's clock, and the first step of the World turn moves it. That step's prompt, _Winter closes by one. What is different about the light today?_, is a question as well as a tick, because the light changes and you write how.

Then the World turn rolls Road Risks. Of its nine chances three are quiet, one ticks Winter (the hard frost), one adds to Burden (the bad foot) and three send you to a scene (a Stranger, a Ruin or an Omen), while the risen ford does both, with a Ford scene and a tick.

Winter was at 8 when the last scene closed. The closing check said "Near", and a d6 gave a 3, so it was not yet time.

> The days shorten, and Winter closes by one, to nine. I write: "The light is gone before we have finished eating."
>
> Road Risks gives _A hard frost in the night. Everything you own is stiff by morning._ Winter closes by one more, to ten.

The next scene will close with Winter at its last box, and the first snow will end **The Road** without a roll. The player did nothing wrong, and the road got shorter by itself.

## Cold Berth

Threat is the ship's patience. The watch turns, Threat climbs by one and the ship may make trouble, and then comes the third part of the turn, in which the ship picks a crew member from the manifest and takes them. The turn's last step then asks where they were last seen, and when the turn is over you still pick the next scene yourself, unless Ship trouble has forced one. So the clock is not the only pressure in Cold Berth, because the manifest runs down as well, one name shorter each round.

Threat is at 3 when the World turn starts.

> The watch turns, and Threat climbs to four. Ship trouble gives _Someone is standing where nobody was a moment ago._ The ship sends the next scene, and it is a Confront.
>
> The pick takes Okafor, and the next step reads "Okafor is not where they were".

Two things went wrong at once, and neither was a mistake. A Confront is coming, and there is one fewer person to help with it.

## Try it

Choose the clock that ends your loop, and decide how many segments it has, where the die starts and which events tick it. Then write the events table with nine chances, three of them quiet.
