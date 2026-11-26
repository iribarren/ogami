# Memory

Of the things The Promise says a game master does for a group, the last is memory. A game master knows who the stranger was, what the saint is said to have done and which door was locked, and the players take it for granted that someone is keeping all of it.

When you play alone, nobody else is keeping it. Two sessions after you met Maud on the road you will have forgotten that she wanted her husband's boots taken to the shrine, and once you have forgotten it, as far as the story is concerned the world has forgotten it too. A solo game needs something that remembers for you, and a flow gives you five kinds of record for what matters.

## Five kinds of record

If you have watched a detective series, you have seen the wall in the incident room, with photographs of the people involved, pins in a map, the things the detectives are sure of and the questions they still cannot answer. A flow's notebook keeps those four kinds of thing under names of its own, and adds a fifth, the tags that let the flow find them again.

**Facts.** A Fact is a statement about the world that you now hold to be true, such as "The saint was a ferryman." Some Facts are written in a **Fact slot**, a labelled blank that the flow gives you, and The Long Carry has three of them. They hold the saint, the Sin and the vow. A slot can be filled again, and what you write last replaces what you wrote before, which is how a belief changes in the notebook. Other Facts are free, and you write them whenever you want.

**Threads.** A Thread is a question the story has opened and not yet answered, such as "Where is Ines?" or "Who falsified the cryo log?". It stays open until you answer it, and then you close it, so the open Threads are how the flow remembers what it still owes you.

**NPCs.** An NPC is a person who is not one of the characters you play, as Maud was. A name is enough, with a line about what they want. An NPC stays open while they are part of the story and is closed when they are gone.

**Places.** A Place is somewhere the story has been or may go, such as a shrine, a hold or a compartment of a ship, and a Place can be open or closed too.

**Tags.** A tag is a word you attach to an NPC, a Thread or a Place so that the flow can find it later. "Crew" on a person means the ship may take them, and "Compartment" on a Place means the crew may go there. Tags are what turn a list into something the flow can draw from, since it can then ask for a random crew member, or offer you the compartments to choose from.

An NPC, a Thread or a Place can also carry a **weight** from one to three. A weight of three means it matters more, and so it comes up more often when something is drawn from the list.

## What deserves writing down

You do not write down everything, because a notebook that records every detail is a notebook you stop reading. Write a thing down when one of these is true of it.

- **You will want to find it again**, as you will with a name, a place or something a character swore to do.
- **It changes something**, the way a suspicion moves a clock or a belief moves the ending.
- **The flow will ask for it.** A pick step needs a list to pick from, and if **Session Zero** did not make one, the Phase will find an empty page.

When none of them is true, the detail was colour, which you enjoy while you play it and then let go. Keep each entry you do write to a line, and if one needs a paragraph, write it as the two entries it really is.

## On paper

Keep four lists in one notebook, each on a page of its own.

| List    | What you write                              | Columns                                |
| ------- | ------------------------------------------- | -------------------------------------- |
| Facts   | The slots at the top, then free Facts below | The label, then the current text       |
| Threads | One line per open question                  | The line, a weight, open or closed     |
| NPCs    | A name and what they want                   | The line, tags, weight, open or closed |
| Places  | A name and a word about it                  | The line, tags, weight, open or closed |

Write the slots on the first page before you play, even while they are blank, because a labelled blank line on page one tells you before anything has happened that what goes on that line will matter.

To close an entry, strike it through. Do not erase it, because a crossed-out name is a person you can still remember. Change a slot the same way, by crossing out the old line and writing the new one beneath it, so that the last line is always the one that holds.

When the flow asks you to pick someone, make the pick from the list, and if you want it random, number the open entries that carry the tag and roll.

:::in-ogami
The notebook is the set of Play panels named **Facts**, **Threads**, **Places** and **NPCs**. The **Facts** panel lists the flow's slots, each with a **Fill** button (**Refill** once it holds a text), and the free Facts under them with **Add fact**. The other three have **Add Thread**, **Add Place** and **Add NPC**, and each entry can be edited, given a **Weight**, and closed or reopened with **Close** and **Reopen**. An NPC has an **Agenda** too, and can be promoted to a Character.

In Studio, the **Trackers & tags** page declares what a flow can use. **New tag** has a **Key**, a **Label**, a **Hint** and **Applies to**: Characters, NPCs, Threads or Places. **New fact slot** has the same fields and a **Type**: text, npc, thread or place. In a step, **Add effect** offers `createNpc`, `createThread`, `createPlace`, `closeThread` and `fillFact`, and the first three take **Tags**.

A step's text can read a slot as `{fact:sin}`, using the key of the slot.
:::

## The Long Carry

The Long Carry remembers three things in slots, and everything else it lets you keep as you like.

| Slot      | What it holds                             |
| --------- | ----------------------------------------- |
| The saint | Whose body you carry                      |
| The Sin   | What the saint did, as you now believe it |
| The vow   | What you promised, and to whom            |

The saint and the vow are written once, in **Session Zero**, while the Sin is the slot that moves. Every Omen asks _What do you now believe the saint did?_, and the answer goes into the slot in place of the one before. The slot's hint says as much in its own words, "Each Omen can change it."

The flow also makes people and places as it goes. Each Stranger becomes an NPC from your answer to _Who is on the road ahead?_, and the shrine becomes a Place when you say where it stands. None of them is tagged, because nothing is ever drawn from these lists, which serve only as the notebook of your journey.

The player has reached an Omen, and the Sin so far reads "A debt to a river, unpaid."

> I roll the Omens table and it gives _Dogs that will not cross the shadow of the litter_.
>
> I write: "Dogs go round us in a wide curve, as if the litter threw something on the ground. The saint did not owe the river. The river owes the saint, and has been waiting to collect." I cross out the old line and write the new one under it.

The previous Sin is struck through but not erased, and when **The Shrine Gate** asks what you carried, the flow reads the last line back to you.

## Cold Berth

Cold Berth remembers more, because it draws from its lists.

Its three Truths are slots, _What is aboard_, _Who let it on_ and _Why we were woken_, and they stay blank until the **Reveal**, for reasons that Chapter 9 explains.

The ship scene of **Session Zero** fills the other lists. Each compartment you name becomes a Place tagged **compartment**, and each person on the manifest becomes an NPC tagged **crew**. Those two tags are the reason the loop works, since every job opens by asking for a compartment and the World turn asks for a crew member.

The loop also opens Threads, such as "Where is Ines?" when someone goes missing and "Who falsified the cryo log?" when a table asks it.

The World turn asks the ship to take a crew member, and the open crew on the manifest are Ines, Pell and Okafor.

> I number them in pairs and roll a d6, so that 1 or 2 is Ines, 3 or 4 is Pell and 5 or 6 is Okafor. The d6 gives a 2, which is Ines. The prompt reads _Where were they last seen, and what was left behind?_, and the flow opens a Thread, "Where is Ines?"
>
> I write: "Last seen at the manifest desk. Her cup is still warm." I cross Ines off the NPC list, so the ship cannot take her twice, and add the Thread at the foot of the Threads page.

Closing Ines in the NPC list is what keeps the ship honest, because a person who is already gone cannot go again.

## Try it

List the five kinds of record for your flow, and write down which of them each Phase needs before it starts. Then, for each tag, name the Phase that will ask for it and the page where **Session Zero** makes the first one.
