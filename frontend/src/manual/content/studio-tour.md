# A Tour of Studio

Studio is where GameSystems are authored. This page takes you through its screens in the order you meet them, and then through each section of a GameSystem in the order the rail lists them. Since the chapters of Parts I to III say how to design each piece, this one says where the piece lives.

## The Studio home

Studio opens on a list headed **Your GameSystems**, with one card for each, and a **New GameSystem** button that starts either a blank draft or a copy of a published release under a new key, picked from a list of every published GameSystem, the presets included. Below the list is the **Library**, which holds the packs of reusable items that more than one GameSystem can share, and it has a **New pack** button of its own. A **Manual** link sits in the header, and the same link is in the header of every GameSystem.

Each GameSystem has a key, written in lower case letters, digits and hyphens, which names the GameSystem and never changes, so you choose it once.

## The GameSystem page

Opening a card takes you to the GameSystem page, whose header shows the name, the key and the schema version beside where the GameSystem stands. A GameSystem that has never been published says **Never published**, and one that has shows the **Latest release** with its version. An **Unpublished changes** badge appears when the draft differs from the latest release, and a badge counting **library changes** appears when something the draft imported from a pack has changed or gone.

The **Publish** button is at the right of the header. Below the header the page is split in two, with a **rail** of sections down the left, much like a table of contents down the side of a page, and the open section on the right. The rail has two groups, **Author** and **Ship**, and some of its sections carry a pill, which shows how many items they hold, the number of errors for Validation, or the latest version for Releases (for example "v3").

## Author

| Section         | What it holds                                                                                                                                      |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Overview        | Name and description, the Credits notice, license, link and badge, and the Theme. A GameSystem that was never published can also be discarded here |
| Flows           | The NarrativeFlows of the GameSystem: phases, steps and selection                                                                                  |
| Scene Types     | The kinds of scene, with their setup, play and closing steps                                                                                       |
| Trackers & tags | Three lists: Trackers, Tags and Fact slots                                                                                                         |
| Oracles         | Oracle tables and Likelihood oracles                                                                                                               |
| Sheet           | The fields and groups of the Character sheet, and the Currency                                                                                     |
| Checks          | The Checks and their outcome bands                                                                                                                 |
| Gear            | The catalog of Gear                                                                                                                                |
| Library         | What the draft imported from library packs                                                                                                         |

The sections follow the order of this book's design method, which goes from the largest decision to the smallest, so the Flow comes first, its Scene Types after it and then the pieces they use. Sheet, Checks and Gear make up the rules half of the GameSystem, which Part III covers.

## Ship

| Section    | What it holds                                                              |
| ---------- | -------------------------------------------------------------------------- |
| Validation | Whether the draft would be accepted as a release, and what is wrong if not |
| Releases   | The published versions, newest first                                       |
| Sections   | A count of the items in each section, with a link to open it               |

Validation, Releases and Library each have a chapter of their own in this part.

## Which sections a library pack has

A library pack is a smaller thing than a GameSystem, so its rail is shorter and lists only **Overview**, **Scene Types**, **Trackers & tags**, **Oracles** and **Validation**. A pack has no Flows, Sheet, Checks, Gear, Library, Releases or Sections, and no Publish button, for reasons the next chapter explains.

## Moving around

Each section has an address of its own, so a link to one opens that section, and several of Studio's messages carry a link of this kind into the right place (the Validation chapter shows the most useful one). Inside a section, most lists open an item in an editor of its own, and the flow, Scene Type, table, Likelihood oracle, Checks, Currency and Overview editors warn you if you leave with edits you have not saved.

## Hard Country and Dogwatch in the rail

Hard Country's rail shows one Flow, The Long Carry, and Dogwatch's shows Cold Berth. Both Sheets have a Currency, and both Checks sections hold the Checks their flows offer, three for Hard Country and eight for Dogwatch. Neither GameSystem uses the Library section, because neither imported anything from a pack.

## Try it

Open one of your GameSystems and visit each section in the order of the rail. For each one, write a line saying what it holds and which chapter of this book designs it.
