# Choosing the Next Scene

A Phase is made of scenes, and each scene is an instance of a Scene Type. The Phase also has to say how the next scene gets chosen, and that rule, the **selection**, decides who does the steering, whether it is the flow, you or the dice.

There are three rules, and it helps to think of each one as a way of handling a pile of cards, with one card for each Scene Type.

## Sequence: an ordered stack

A sequence stacks the cards in the order you will play them, and you play the top one and nothing else. The choice belongs to the flow, which made it in advance.

Use a sequence when the order is the point. Making characters comes before the road, an arrival comes after it and an ending comes last. A Phase that runs `once` is usually written as a sequence.

A sequence can also sit in a loop. When the stack runs out you start it again from the top, so a loop with three cards in sequence plays as a fixed rhythm of one, two, three, one, two, three.

::flow-diagram{flow="the-long-carry" phase="session-zero"}

## Player: a face-up spread

All the cards of a spread lie face up, and you choose which one to play, so the flow makes the offer and you make the decision.

Use a spread when the story is about what the player chooses to do, and when each choice should cost something different. Each card is a job, and the player reads the clocks to decide which job the moment needs. A Scene Type appears once in a spread, since a spread with the same card twice has fewer choices than cards.

::flow-diagram{flow="cold-berth" phase="the-ship"}

## Oracle: a shuffled deck

In a deck the cards are shuffled and you draw, so the choice belongs to the dice.

The deck is a weighted oracle table, and **weight is copies**. A card with weight 3 is in the deck three times, which makes it three times as likely as a card with weight 1. You do not need to count, because all you have to ask is how often each kind of scene should turn up. Once you have the answer, you write it down as a number.

Each card in the deck names its Scene Type. The table entry is the card, and the entry's text is what is printed on it.

::flow-diagram{flow="the-long-carry" phase="the-road"}

## Which serves the Promise

| Rule     | Who decides | Use it when the promise is about                     |
| -------- | ----------- | ---------------------------------------------------- |
| Sequence | The flow    | Something that has to happen, in an order            |
| Player   | You         | A choice, and what each choice costs                 |
| Oracle   | The dice    | A road or a world that comes at you and does not ask |

Ask three questions of each Phase, one for each rule.

- **Must every scene be played?** Then use a sequence, because a deck can leave a card undrawn and a spread lets you skip it.
- **Does a refusal mean something?** Then use a spread, where choosing Hide over Repair is the story.
- **Should you be surprised?** Then use a deck, because if you wrote the next scene yourself, you would write the one you were hoping for.

A flow mixes the rules, and between them the two flows here use all three. Both play their opening and closing Phases in sequence, The Long Carry deals **The Road** from a deck and Cold Berth lays **The Ship** out as a spread.

## On paper

Make one card per Scene Type, either the index card the Scene Types chapter describes or a playing card that stands for it, and then handle the cards by the rule.

- **Sequence.** Stack the cards face down in order. Play the top card and put it aside, or in a loop put it under the pile instead.
- **Player.** Lay the cards face up. Pick one, play it, and put it back where it was.
- **Oracle.** Make the deck. For each Scene Type, count its weight and take that many cards, or that many copies of its card. Shuffle them, draw one, play it and shuffle it back in. The drawn card is not removed. Chapter 10 covers the deck in more detail.

If a table or an event says "the next scene is a Ford", play a Ford without drawing, since the deck is only for the scenes that nobody has forced.

:::in-ogami
Open a Phase in the flow editor and set its **Rule** to **Plays in order**, **The player picks** or **An oracle table picks**. The first two show a list of Scene Types, where you choose one in **Add a Scene Type** and move it with **Up** and **Down**. A player list names each Scene Type once, whereas a sequence may repeat one. The **Map** tab, covered in the chapter on the arc, also lets you drag Scene Types into a Phase. An oracle rule shows an **Oracle table** field instead. Each entry of that table has a **Scene Type** and a **Weight**, which you set in the table editor under **Oracles**, on a table of the weighted kind.

In Play, the scene pick shows the rule in its heading. A sequence says **The Flow sets the next Scene Type** and offers one card. A spread says **Choose the next Scene Type** and offers a card for each, each with **Play this**. A deck says **Roll the next Scene Type** and shows **Roll on** followed by the table's name, and pressing it starts the scene the entry names. A Scene Type forced by an effect shows **Forced for this scene**.

A sequence in a loop starts again from its first Scene Type. A `once` Phase with the player or oracle rule ends after one scene.
:::

## The Long Carry

In The Long Carry you travel from one place to another, and the two ends of the journey are fixed. What the flow leaves open is what you meet on the way, because **The Road** between the two ends is a deck that chooses every scene nothing else has forced, and since it is shuffled, each journey meets its own scenes in its own order. Its table, The road deck, has thirteen copies.

| Card                                          | Copies |
| --------------------------------------------- | ------ |
| Camp: a place to stop                         | 3      |
| Ford: water in the way                        | 2      |
| Stranger: someone on the road                 | 3      |
| Ruin: what the road left behind               | 2      |
| Omen: the road says something about the saint | 3      |

The copies set how often each kind of scene turns up, and so what kind of road this is. There are three Camps because Camp is the only place where the bearers recover, and a road without rests would be a death march. Fords and Ruins are rarer, since each of them costs something. The Omens are common because the Sin is written there, and the story needs a steady supply of Omens.

The Phases around **The Road** are sequences. **Session Zero** is a sequence because nobody should be able to skip the vow, and it plays the bearers, the saint and the vow in that order. **The Shrine Gate** and the **Epilogue** are one scene each.

The last scene is over, the World turn is done and Road Risks said the road was quiet, so the player draws the next scene.

> I draw _Ruin: what the road left behind_ from the road deck. That is two copies of thirteen, so it was not likely, and it is what I have.
>
> Setup. "A farm. The fence is mended and the door is shut." The Ruins table gives _A farm with the table laid for twelve_ when I roll it.
>
> I write: "Twelve places, and the plates are clean. Whoever laid it knew they would not eat."

The deck did not say what was in the farm. It only said there was a Ruin, and the Ruin's own table and questions did the rest. When the World turn forces a scene instead, you do not draw that round.

## Cold Berth

**The Ship** is a spread whose four cards, Investigate, Repair, Confront and Hide, lie face up every round. Nothing in the flow says which one to play, so the choice comes from the state of the crew.

The spread also has a second layer. Every one of these Scene Types opens by picking a compartment, and that pick is a smaller spread of the places named in **Session Zero**, laid face up, from which the player chooses one. That second layer is why **The Ship** is built on choice, since a round becomes two decisions, a job and a place, and both of them can be wrong.

Nerve is low, Threat stands at four and Clues at one when the player chooses the next job.

> The World turn gave a quiet hour. The spread is Investigate, Repair, Confront, Hide. I have been hurt by the last two scenes and I do not want to be seen, so I play Hide.
>
> The ship offers the compartments I named: the Wake Bay, the Drive Spine, the Cold Hold. I take the Drive Spine. It is loud, and a loud place hides a quiet crew member.

A deck would have chosen for the crew, whereas the spread lets the crew choose the risk. The World turn still has a say, though, because some Ship trouble results force a Repair, a Hide, an Investigate or a Confront, and a forced scene takes the choice away.

## Try it

For each Phase of your flow, write one of the words `sequence`, `player` or `oracle` beside it. For each oracle Phase, list the Scene Types, give each one a weight and add the weights up. If any weight is more than half the total, the deck is not a deck any more.
