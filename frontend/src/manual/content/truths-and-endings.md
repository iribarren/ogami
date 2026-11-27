# Truths and Endings

A mystery needs an answer, and a solo game has a problem with it that a group with a game master does not have, because the person who writes the answer is the same person who has to be surprised by it. So a flow has to decide when the answer is fixed, and how the story ends once the answer is known.

## Three kinds of Truth

A **Truth** is something about the story that is true whether or not you have found it out yet. What sets the kinds apart is who decides it, and when.

**Sealed.** A sealed answer is decided before play and kept out of sight, the way the culprit, the weapon and the room sit in the envelope from the start of a game of Cluedo. You write it down or roll it, fold the page and put it away, and then you play to find it. A sealed Truth gives a mystery its shape, because every clue points at something that is already fixed. Its risk comes at the moment the answer is opened, since if it is dull, there is nothing to rescue the evening.

**Emergent.** An emergent Truth has no answer at the start, and it is whatever the play adds up to. Each time the story offers a hint you write what you now believe, and the last thing you write is the Truth. An emergent Truth is never wrong, and it never arrives as a surprise from outside. Its risk is that it can be vague, so give it a place to be written and a question that asks for it.

**Fixed.** A fixed Truth is one the author has and the player does not. This is how a published mystery works, with the solution printed in the book and the reader playing to reach it. Since it needs a second person to be the author, it does not work for a flow you play against yourself.

| Kind     | Who decides             | When        | Best for                    |
| -------- | ----------------------- | ----------- | --------------------------- |
| Sealed   | The dice, or you        | Before play | A puzzle with a real answer |
| Emergent | You, a little at a time | During play | A story about belief        |
| Fixed    | The author              | Before play | A scenario handed to others |

Pick the kind that suits the promise. A story about what a body means to the people who carry it wants an emergent Truth, whereas a story about what is hiding aboard a ship wants a sealed one.

## Multiple endings

An ending that depends on how you played is an ending that feels earned, so give the flow a final Phase that reads the numbers and chooses one.

1. Pick two Trackers that mean something to the promise.
2. Cut each into two stretches, at a number you choose.
3. The two cuts make a grid of four cells. Write an ending in each, or in three of them if two cells share one.

The endings should differ in what they cost, not in whether you win. Every ending needs a question for the player to answer, because the **Epilogue** is where the story is written down.

## On paper

**Sealed.** Before the first scene, roll or choose the answer to each question on a slip of paper, then fold each slip and put them all in an envelope. In **Session Zero** you write your hunches on a separate page, so that at the **Reveal** you have something to compare. When the **Reveal** comes, open the slips and write the one story that makes all three true.

**Emergent.** Leave a line for the Truth in the notebook, and write it again each time the story offers a hint. The last version is the one that stands.

**Fixed.** The author writes the solution on an index card, or a page of a notebook, and gives it to the player sealed. This one is a sidebar for authors who plan to hand a flow to someone else, since for them a fixed solution is the simplest way to give the player a mystery.

For the ending, draw the grid on an index card, or a page of your notebook, with the two Tracker names, the two cut points and the four cells.

:::in-ogami
There is no hidden Fact in Ogami. A Fact slot is shown to the player as soon as it is declared, and a flow cannot keep it back. So a sealed Truth cannot be sealed. A flow rolls it at the Reveal instead: a **table** step with a `fillFact` effect writes the result into the slot. Session Zero only sets down hunches, as ordinary prompts.

An emergent Truth is a prompt with a `fillFact` effect and no table, which refills the slot each time the player answers. Endings are **condition** steps whose bands name the next step with **Next**, ending at a step whose **Next** is `end`.
:::

## The Long Carry

The Truth of The Long Carry is emergent, and nobody tells you what the saint did. Each Omen scene asks _What do you now believe the saint did?_ and writes your answer into the Sin, in place of what was there. The flow's note on that step reads "The last Sin you write is the Truth." When **The Shrine Gate** arrives, the keeper asks what you carried, and the flow reads the Sin back to you.

The ending comes from two numbers, read as two questions.

| Question                    | Cut point                 |
| --------------------------- | ------------------------- |
| Did you beat the deep snow? | Winter 7 or less: In time |
| Did you arrive light?       | Burden 2 or less: Light   |

Three endings come out of the grid. In time and light is _Laid down in time_. In time and heavy, or late and light, is _Laid down at a price_, and late and heavy is _The carry is not finished_. Two cells share the middle ending because each of them is a price, paid in lateness in one cell and in Burden in the other.

The player reaches the **Epilogue** with Winter at 8 and Burden at 2.

> Did you beat the deep snow? Winter is 8, which is "Late". Did you arrive light? Burden is 2, which is "Light". Late and light gives _Laid down at a price_.
>
> The prompt asks what the shrine wants in exchange. I write: "The keeper asks for the boots Maud gave us, and for the one who carried them. Ansel steps forward, and he knows."

## Cold Berth

::image{file="dw-sealed-slips.webp" style="dogwatch" size="half" alt="Three sealed blank slips on a steel table under a handlight." subject="Three folded and sealed blank slips lying on a steel table in the pool of light of a single handlight."}

The Truth of Cold Berth is sealed. There are three questions (what is aboard, who let it on and why you were woken), and each has its own table of eight answers. **Session Zero** asks for hunches only, and the **Reveal** opens the Truths.

On paper you roll the three tables in **Session Zero** and seal the results, so that the dice choose the answers and the seal keeps them hidden from you. The hunches you write then are what you compare them with.

A fixed solution would be a sidebar for another flow. If you wrote Cold Berth for a friend, you would choose the three answers instead of rolling them, seal them and hand the flow over, and your friend would play to find what you wrote.

At the **Reveal**, the player rolls the three tables.

> What is aboard: _A passenger who is not the person the manifest says_. Who let it on: _The one who died on the last leg, and signed for it first_. Why we were woken: _The ship weighs more than when it left_.
>
> I write the one story that makes all three true: "Hale died on the last leg and a stowaway took the berth. The stowaway signed Hale's name, and the ship is heavier by one person who should not be asleep."

The ending reads Threat and Clues, in a grid of four cells.

| Threat    | Clues 2 or less           | Clues 3 or more             |
| --------- | ------------------------- | --------------------------- |
| 5 or less | _Out, and none the wiser_ | _Everyone out, and knowing_ |
| 6 or more | _The ship keeps you_      | _Out, but not all of you_   |

## Try it

Choose a kind of Truth for your promise, and write down who decides it and when. Then pick two Trackers, set a cut point on each, and write one line for each ending.
