# Choosing the Next Scene

A Phase is made of scenes, and each scene is an instance of a Scene Type. The Phase also has to say how the next scene gets chosen. That rule is the **selection**, and it decides who is steering: the flow, you or the dice.

There are three rules. Think of each as a way of handling a pile of cards, one card per Scene Type.

## Sequence: an ordered stack

The cards are stacked in the order you will play them. You play the top one and nothing else. The flow chose, and it chose in advance.

Use a sequence when the order is the point. Making characters comes before the road. An arrival comes after it. An ending comes last. A sequence is how a Phase that runs `once` is usually written.

A sequence can also sit in a loop. When the stack runs out, you start it again from the top, so a loop with three cards in sequence is a fixed rhythm: one, two, three, one, two, three.

## Player: a face-up spread

All the cards lie face up and you choose which to play. The flow offers; you decide.

Use a spread when the story is about what the player chooses to do, and when each choice should cost something different. Each card is a job, and the player reads the clocks and decides which job the moment needs. A Scene Type appears once in a spread. A spread with the same card twice is a spread with fewer choices.

## Oracle: a shuffled deck

The cards are shuffled and you draw. The dice choose.

The deck is a weighted oracle table, and **weight is copies**. A card with weight 3 is in the deck three times, so it is three times as likely as a card with weight 1. You do not need to count. You only need to ask how often each kind of scene should turn up, and write the answer as a number.

Each card in the deck names its Scene Type. The table entry is the card, and its text is what is printed on it.

## Which serves the Promise

| Rule     | Who decides | Use it when the promise is about                     |
| -------- | ----------- | ---------------------------------------------------- |
| Sequence | The flow    | Something that has to happen, in an order            |
| Player   | You         | A choice, and what each choice costs                 |
| Oracle   | The dice    | A road or a world that comes at you and does not ask |

Ask three questions of each Phase.

- **Must every scene be played?** Then use a sequence. A deck can leave a card undrawn, and a spread lets you skip it.
- **Does a refusal mean something?** Then use a spread. Choosing Hide over Repair is the story.
- **Should you be surprised?** Then use a deck. If you wrote the next scene yourself, you would write the one you were hoping for.

A flow mixes them. Both flows here use all three rules, one for each kind of Phase.

## On paper

One card per Scene Type, as the Scene Types chapter describes. Then handle the cards by the rule.

- **Sequence.** Stack the cards face down in order. Play the top card and put it aside. In a loop, put it under the pile instead.
- **Player.** Lay the cards face up. Pick one, play it, and put it back where it was.
- **Oracle.** Make the deck. For each Scene Type, count its weight and take that many cards, or copies of the card. Shuffle them, draw one, play it, and shuffle it back in. The drawn card is not removed. Chapter 10 covers the deck in more detail.

If a table or an event says "the next scene is a Ford", play a Ford and do not draw. The deck is for the scenes nobody has forced.

:::in-ogami
Open a Phase in the flow editor and set its **Rule** to **Plays in order**, **The player picks** or **An oracle table picks**. The first two show a list of Scene Types: choose one in **Add a Scene Type**, and move it with **Up** and **Down**. A player list names each Scene Type once; a sequence may repeat one. An oracle rule shows an **Oracle table** field instead. Each entry of that table has a **Scene Type** and a **Weight**, set in the table editor under **Oracles**, on a table of the weighted kind.

In Play, the scene pick shows the rule in its heading. A sequence says **The Flow sets the next Scene Type** and offers one card. A spread says **Choose the next Scene Type** and offers a card for each, each with **Play this**. A deck says **Roll the next Scene Type** and shows **Roll on** followed by the table's name; pressing it starts the scene the entry names. A Scene Type forced by an effect shows **Forced for this scene**.

A sequence in a loop starts again from its first Scene Type. A `once` Phase with the player or oracle rule ends after one scene.
:::

## The Long Carry

The Road is a deck. Its table, The road deck, has thirteen copies.

| Card                                          | Copies |
| --------------------------------------------- | ------ |
| Camp: a place to stop                         | 3      |
| Ford: water in the way                        | 2      |
| Stranger: someone on the road                 | 3      |
| Ruin: what the road left behind               | 2      |
| Omen: the road says something about the saint | 3      |

The numbers say what the road is like. There are three Camps because Camp is the only place the bearers recover, and a road without rests is a death march. Fords and Ruins are rarer: each one costs something. The Omens are common: the Sin is written there, and the story needs a steady supply of them.

The Phases around the Road are sequences. Session Zero plays the bearers, the saint and the vow, in that order. The Shrine Gate is one scene. The Epilogue is one scene. Nobody should be able to skip the vow.

A played draw. The last scene is over, the world has turned and Road Risks said the road was quiet. Time to draw.

> I draw from the road deck: _Ruin: what the road left behind._ That is two copies of thirteen, so it was not likely, and it is what I have.
>
> Setup. "A farm. The fence is mended and the door is shut." The Ruins table gives _A farm with the table laid for twelve._
>
> I write: "Twelve places, and the plates are clean. Whoever laid it knew they would not eat."

The deck did not say what was in the farm. It said there was a Ruin, and the Ruin's own table and questions did the rest. If the world turn forces a scene instead, you do not draw this round.

## Cold Berth

The Ship is a spread. The four cards are Investigate, Repair, Confront and Hide, and they lie face up every round. Nothing in the flow says which to play. The crew's state does.

The spread has a second layer. Every one of these Scene Types opens by picking a compartment, which is a smaller spread: the places named in Session Zero, face up, and the player chooses one. That is why the Ship is built on choice. A round is two decisions, a job and a place, and both can be wrong.

A played choice. Nerve is low, Threat stands at four and Clues at one.

> The world turn gave a quiet hour. The spread is Investigate, Repair, Confront, Hide. I have been hurt by the last two scenes and I do not want to be seen, so I play Hide.
>
> The ship offers the compartments I named: the Wake Bay, the Drive Spine, the Cold Hold. I take the Drive Spine. It is loud, and a loud place hides a quiet crew member.

A deck would have chosen for the crew. The spread lets the crew choose the risk, and the world turn still has a say: some Ship trouble results force a Repair, a Hide, an Investigate or a Confront, which takes the choice away.

## Try it

For each Phase of your flow, write one of the words sequence, player or oracle beside it. For each oracle Phase, list the Scene Types and give each a weight, then add the weights up. If any weight is more than half the total, the deck is not a deck any more.
