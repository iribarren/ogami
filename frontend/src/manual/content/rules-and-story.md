# Rules and Story

Parts I and II taught the story side of a game, which is a flow, its Scenes and the tools you roll at the table. This part turns to the other half, the **rules**, and to the way they sit beside the story in Studio. If you have ever owned a rulebook and an adventure written for it, you already know the shape of the split, because the rulebook tells you how a roll works and the adventure tells you where the road goes.

A **GameSystem** is the whole thing you author, with its sheet, Checks, Gear, oracles, Trackers, Scene Types and flows, and a **NarrativeFlow** is one guided way to play it. Studio keeps the two apart on purpose, and most of the design mistakes in a first GameSystem come from letting one leak into the other, so that a rule ends up written into a story or a story into a rule.

## What belongs where

| The rules (the GameSystem)                        | The story (a NarrativeFlow)                            |
| ------------------------------------------------- | ------------------------------------------------------ |
| The sheet: what a Character is made of            | The Phases and the order of play                       |
| Checks: how a die roll turns into an outcome      | Which Check a step offers, and what each outcome costs |
| Gear and Currency: what can be carried and bought | A shop step that sells some of it                      |
| Trackers, oracle tables, Fact slots, tags         | Which of them this flow reaches and reads              |
| Scene Types: reusable kinds of scene              | Which Scene Type a Phase picks, and in what order      |

When you are not sure which side something belongs on, ask whether it would be reused. If a second story set in the same world would want it unchanged, it is a rule, and if it only makes sense inside one story, it belongs to the flow.

Hard Country is the rules of a hard fantasy trek, made of three traits, a d20 Check, Wind and Wounds, a load of eight slots and money counted in pennies, and The Long Carry is a story told with them. Nothing on the Hard Country sheet knows about the saint, the shrine or Winter. Winter is a Tracker, and the flow reaches it.

Dogwatch makes the same split in space. Its rules are four Stations, eight Checks, Nerve and Injuries, six slots of kit, and chits, while Cold Berth is the story. The sheet says nothing about a berth or a custodian.

## One GameSystem, many flows

A GameSystem release holds zero or more flows, and every flow in it shares the same sheet, Checks and Gear. As a rule of thumb, write the second flow before you decide the first one is finished, and use it to test the sheet. If the second flow can use the sheet as it is, the split is right. If it needs a new field to make sense, ask whether the rule is general or whether the field belongs to the first story.

Neither GameSystem in this book has a second flow to show you, since Hard Country has only The Long Carry and Dogwatch only Cold Berth. A second flow for Hard Country would be a different journey with the same bearers, a winter siege, say, or a harvest fair. It would pick from the same Scene Types where they fit and add its own where they do not. Trackers are declared once in the GameSystem, and each flow lists the Trackers and oracles it reaches, so the new flow would name only its own.

## What a flow cannot read

A flow is written on top of the rules, and it sees less of them than you would like. A step `condition` branches on Trackers and item counts, but it does not read sheet fields, and since a Hard Country bearer's Wind is on the sheet, no step can say "if Wind is 0, then...".

That limit belongs to the engine, and both presets live with it in the same way, by writing the harm rule as a sentence in a step tip. A bearer with no Wind marks a Wound, and a crew member with no Nerve marks an Injury. The effects take the Wind or the Nerve, and the player marks the rest. When your own rules need a hard branch on a sheet value, push the number into a Tracker, which a flow can read, or write the branch as a question for the player.

## The rail follows the order

Studio lists the sections of a GameSystem in the order this book teaches them, which is Overview, Flows, Scene Types, Trackers & tags, Oracles, Sheet, Checks, Gear, Library, Validation, Releases and Sections, so the story comes first and the rules after it. You can follow that path, but nothing makes you, and a rules-heavy game may start with the sheet.

The next pages take the rules in the order a Character meets them, starting with the sheet and what it computes, then the Checks, and last what the Character carries.

## Try it

Take a game you would like to run solo. Write two lists, one headed Rules and one headed Story, and sort every idea you have for it into one of them. Then go through the Rules list and ask, for each item, whether a second story would use it unchanged.
