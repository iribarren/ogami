# From Paper to Ogami

Everything in this book can be played on paper, and each paper tool also has a place in Ogami. The two tables below show where each one lives, so that you can move a game you designed at the table into the app, or take one from the app back to the table.

## Designing

| On paper                                   | In Ogami                                                                                                                     |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| The rules sheet of a game                  | A **GameSystem**, authored in Studio. Its Sheet, Checks and Gear sections hold the rules                                     |
| The flow plan                              | A **Flow**, in the Flows section of the GameSystem                                                                           |
| A Phase on the plan                        | A Phase of the Flow, with its run (once or loop) and its selection (sequence, player or oracle)                              |
| A Scene Type card                          | A **Scene Type**, in the Scene Types section, with setup, play and closing steps                                             |
| The steps written on a card                | Steps in the Scene Type editor, of the kinds prompt, character, roll, condition, oracle, table, choice, pick, check and shop |
| A clock or counter                         | A **Tracker**, in the Trackers & tags section                                                                                |
| A line in the notebook for a Truth         | A **Fact slot**, in the same section                                                                                         |
| A label you write beside an NPC or a Place | A **Tag**, in the same section                                                                                               |
| An oracle table                            | An **Oracle table**, in the Oracles section                                                                                  |
| A yes or no oracle                         | A **Likelihood oracle**, in the same section                                                                                 |
| The road deck                              | An oracle table where the **weight** of an entry is its number of copies                                                     |
| The World turn list                        | The Flow's **World turn** hook                                                                                               |
| A character sheet                          | The **Sheet** section                                                                                                        |
| A Check on the rules sheet                 | A **Check** in the Checks section, with its outcome bands                                                                    |
| The gear list and the purse                | The **Gear** section and the Sheet's Currency                                                                                |
| A copy of a table you reuse                | A **Library pack**, imported into each GameSystem that wants it                                                              |
| The credits page                           | The **Credits** on the Overview                                                                                              |
| Handing a flow on                          | **Publish** a release                                                                                                        |

## Playing

| On paper                                   | In Ogami                                                        |
| ------------------------------------------ | --------------------------------------------------------------- |
| The notebook                               | The campaign's **Journal**                                      |
| Your dice                                  | The **Dice** panel, and the Checks the flow offers              |
| Marking a clock or a counter               | The **Trackers** panel                                          |
| Facts and Truths in the notebook           | The **Facts** panel                                             |
| The Thread, Place, NPC and character lists | The **Threads**, **Places**, **NPCs** and **Characters** panels |
| An oracle table or a likelihood oracle     | The **Oracles** panel                                           |
| Following the flow card by card            | The **Guided play** panel, which offers the next step           |

## What changes

- A paper deck is shuffled once and then drawn in order, whereas in Ogami every draw is a new roll on a table, so the deck never runs out and nothing is discarded.
- The app has no way to hide a sealed Truth from you, which is why Cold Berth rolls its three Truths at the **Reveal**.
- Cards and sheets belong to the table. In the app the same pieces live in the panels listed above, so there is nothing to cut out.
- Version your changes by publishing them. A campaign plays the release it started on until its player upgrades it.

## Moving a game across

1. Build the GameSystem first, taking the Sheet, Checks and Gear from the rules sheet.
2. Add the Trackers, Fact slots, Tags and tables.
3. Write each Scene Type from its card.
4. Build the Flow from the flow plan.
5. Run Validation, then Publish, and start a campaign on the release.
