# Truths and Endings

A mystery needs an answer, and a solo game has a problem a table does not: the person who writes the answer is the person who has to be surprised by it. A flow has to decide when the answer is fixed, and how the story ends once it is known.

## Three kinds of Truth

A **Truth** is something about the story that is true whether or not you have found it out yet.

**Sealed.** The answer is decided before play and kept out of sight. You write it down, or roll it, fold the page and put it away. You play to find it. A sealed Truth gives a mystery its shape, because every clue means something fixed. Its risk is the reveal: if the answer is dull, there is nothing to rescue the evening.

**Emergent.** There is no answer at the start. The Truth is whatever the play adds up to. Each time the story offers a hint you write what you now believe, and the last thing you write is the Truth. An emergent Truth is never wrong and never a surprise from outside. Its risk is that it can be vague, so give it a place to be written and a question that asks for it.

**Fixed.** The author has the answer, and the player does not. A fixed Truth is how a published mystery works: the solution is printed, and the reader plays to reach it. It needs a second person, the author, and it does not work for a flow you play against yourself.

| Kind     | Who decides             | When        | Best for                    |
| -------- | ----------------------- | ----------- | --------------------------- |
| Sealed   | The dice, or you        | Before play | A puzzle with a real answer |
| Emergent | You, a little at a time | During play | A story about belief        |
| Fixed    | The author              | Before play | A scenario handed to others |

Pick the kind that suits the promise. A story about what a body means to the people who carry it wants an emergent Truth. A story about what is hiding aboard a ship wants a sealed one.

## Multiple endings

An ending that depends on how you played is an ending that feels earned. Give the flow a final Phase that reads the numbers and chooses.

1. Pick two Trackers that mean something to the promise.
2. Cut each into two stretches, at a number you choose.
3. Four cells make up a grid. Write an ending in each, or in three of them if two cells share one.

The endings should differ in what they cost, not in whether you win. Every ending needs a question for the player to answer, because the Epilogue is where the story is written down.

## On paper

**Sealed:** before the first scene, roll or choose the answer to each question on a slip of paper. Fold each one and put them in an envelope. Session Zero is where you write your hunches on a separate page, so that at the Reveal you can compare. At the Reveal, open the slips and write the one story that makes all three true.

**Emergent:** leave a line for the Truth in the notebook, and write it again each time the story offers a hint. The last version stands.

**Fixed:** the author writes the solution on a card and gives it to the player sealed. This is a sidebar for authors: if you plan to hand a flow to someone else, a fixed solution is the simplest way to give them a mystery.

For the ending, write the grid on one card. Two Tracker names, two cut points, four cells.

:::in-ogami
There is no hidden Fact in Ogami. A Fact slot is shown to the player as soon as it is declared, and a flow cannot keep it back. So a sealed Truth cannot be sealed. A flow rolls it at the Reveal instead: a **table** step with a `fillFact` effect writes the result into the slot. Session Zero only sets down hunches, as ordinary prompts.

An emergent Truth is a prompt with a `fillFact` effect and no table, which refills the slot each time the player answers. Endings are **condition** steps whose bands name the next step with **Next**, ending at a step whose **Next** is `end`.
:::

## The Long Carry

The Truth is emergent. Nobody tells you what the saint did. Each Omen scene asks _What do you now believe the saint did?_ and writes the answer into the Sin, replacing what was there. The flow's note says it: the last Sin you write is the Truth. When the Shrine Gate arrives, the keeper asks what you carried, and the flow reads the Sin back to you.

The ending comes from two numbers, in two questions.

| Question                    | Cut point                 |
| --------------------------- | ------------------------- |
| Did you beat the deep snow? | Winter 7 or less: In time |
| Did you arrive light?       | Burden 2 or less: Light   |

Three endings come out of it. In time and light is _Laid down in time_. In time and heavy, or late and light, is _Laid down at a price_. Late and heavy is _The carry is not finished_. Two cells share the middle ending, because both are a price, paid in different currencies.

A played ending. Winter is 8 and Burden is 2.

> Did you beat the deep snow? Winter is 8, which is "Late". Did you arrive light? Burden is 2, which is "Light". Late and light: _Laid down at a price._
>
> The prompt asks what the shrine wants in exchange. I write: "The keeper asks for the boots Maud gave us, and for the one who carried them. Ansel steps forward, and he knows."

## Cold Berth

The Truth is sealed. There are three questions, each with its own table of eight answers: what is aboard, who let it on, and why you were woken. Session Zero asks for hunches only. The Reveal opens the Truths.

On paper you roll the three tables in Session Zero and seal the results, so the answers are chosen by the dice and hidden from you. The hunches you write now are what you compare them with.

A fixed solution would be a sidebar for another flow. If you wrote Cold Berth for a friend, you would choose the three answers instead of rolling them, seal them and hand the flow over. The friend would play to find what you wrote.

A played Reveal. The three tables are rolled.

> What is aboard: _A passenger who is not the person the manifest says._ Who let it on: _The one who died on the last leg, and signed for it first._ Why we were woken: _The ship weighs more than when it left._
>
> I write the one story that makes all three true: "Hale died on the last leg and a stowaway took the berth. The stowaway signed Hale's name, and the ship is heavier by one person who should not be asleep."

The ending reads Threat and Clues, with four cells.

| Threat    | Clues 2 or less           | Clues 3 or more             |
| --------- | ------------------------- | --------------------------- |
| 5 or less | _Out, and none the wiser_ | _Everyone out, and knowing_ |
| 6 or more | _The ship keeps you_      | _Out, but not all of you_   |

## Try it

Choose a kind of Truth for your promise. Write down who decides it and when. Then pick two Trackers, set a cut point on each, and write one line for each ending.
