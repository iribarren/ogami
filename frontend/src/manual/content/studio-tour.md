# A Tour of Studio

Studio is where GameSystems are authored. This page walks the screens in the order you meet them, then each section of a GameSystem in the order the rail lists them. The chapters of Parts I to III say how to design each piece; this one says where the piece lives.

## The Studio home

Studio opens on a list of **Your GameSystems**, one card each, and a **New GameSystem** button. The button starts a blank draft, or copies a published release under a new key. Below the list is the **Library**: the packs of reusable items, with a **New pack** button. A **Manual** link sits in the header, and the same link is in the header of every GameSystem.

A key is lower case letters, digits and hyphens. It names the GameSystem and never changes, so choose it once.

## The GameSystem page

Open a card and you reach the GameSystem page. The header shows the name, the key, the schema version and where the GameSystem stands:

- **Never published**, or the **Latest release** with its version.
- An **Unpublished changes** badge when the draft differs from the latest release.
- A badge counting **library changes** when something the draft imported from a pack has changed or gone.

The **Publish** button is at the right of the header. Under it the page has a **rail** of sections on the left and the open section on the right. The rail has two groups, **Author** and **Ship**. Some sections carry a count pill: how many items they hold, or for Validation the number of errors.

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

The order is the order of this book's design method, from the largest decision to the smallest: the Flow first, its Scene Types after it, then the pieces they use. Sheet, Checks and Gear are the rules half of the GameSystem; Part III covers them.

## Ship

| Section    | What it holds                                                              |
| ---------- | -------------------------------------------------------------------------- |
| Validation | Whether the draft would be accepted as a release, and what is wrong if not |
| Releases   | The published versions, newest first                                       |
| Sections   | A count of the items in each section, with a link to open it               |

Validation, Releases and Library have their own chapters in this part.

## Which sections a library pack has

A library pack is a smaller thing than a GameSystem, so its rail is shorter: **Overview**, **Scene Types**, **Trackers & tags**, **Oracles** and **Validation**. A pack has no Flows, Sheet, Checks, Gear, Library, Releases or Sections, and no Publish button. The next chapter explains why.

## Moving around

Each section has its own address, so a link to one opens that section. Several of Studio's messages carry a link into the right place; the Validation chapter shows the most useful one.

Most lists open an item in an editor of its own. The flow, Scene Type and table editors warn you if you leave with edits you have not saved.

## Hard Country and Dogwatch in the rail

Hard Country's rail shows one Flow, The Long Carry. Dogwatch's shows Cold Berth. Both Sheets have a Currency, and both Checks sections hold the Checks their flows offer: three for Hard Country, eight for Dogwatch. Neither uses the Library section, because neither imported anything from a pack.

## Try it

Open one of your GameSystems and visit each section in the rail's order. For each, write one line: what it holds, and which chapter of this book designs it.
