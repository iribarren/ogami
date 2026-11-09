# Feature: studio-manual

- **Locator:** `odd/tasks/studio-manual.md` · Engram topic `odd/studio-manual/tasks`
- **Issue:** [#387](https://github.com/iribarren/ogami/issues/387) · **Branch:** `docs/studio-manual-5-flow-design` · **Status:** in progress (slice 5)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** brainstorm of 2026-11-09, to share the public alpha (`1.0.0-alpha`) with friends

## Objective

A manual that teaches how to build a narrative flow and gives a person very good guidance to play a solo TTRPG, on paper or in Ogami, with two complete worked examples.

## Problem and why

Friends joining the alpha have no guide to Studio. The value of Studio is the flow, and nothing explains how to design one. A pen-and-paper edition makes the method useful without the app.

## Decisions

| Item | Decision | By |
|---|---|---|
| Packaging | One source, two editions. Paper never mentions the app; Full adds "In Ogami" blocks and the Studio reference | User |
| Paper edition | Parts I and II, the two complete flows with printable cards, one rules sheet per GameSystem | User |
| Teaching order | Top-down, from the Promise to Playtest and share | User |
| GameSystems | Two original GameSystems, Hard Country (fantasy) and Dogwatch (science fiction). They replace the earlier plan to adapt Cairn and 24XX, which Claude cannot build here under its session instructions. No third-party rules text is used | User, 2026-11-09 |
| Hard Country rules | Three traits named for what a bearer uses: Back (carry, fight, endure), Feet (move, dodge, sneak), Head (notice, reason, hold your nerve), each 2d6+3. A check is d20 at or under the trait: a 1 is clean, a 20 goes badly. Wind (d6+2) absorbs harm; with no Wind left, harm is a Wound, and three Wounds put you down. Load is eight slots. Currency is pennies | Parent |
| Dogwatch rules | Four Stations: Helm (move, pilot, get there), Hands (fix, force, build), Eyes (notice, read, search), Voice (persuade, lie, calm). A crew card assigns d10, d8, d6 and d4 across them. A check rolls that die against a Difficulty the player sets (2–8, default 3): a 1 goes wrong, under the Difficulty falls short, at or over it succeeds. Help rolls the die twice and keeps the highest (`2d8kh1`, because keep-highest works only within one dice term). Nerve (track, max 6) takes harm first; with none left harm is an Injury, and three put you down | Parent |
| Dogwatch checks | Dice notation cannot read the sheet, so each die size is its own Check. A `check` step offers the set of eight: d4, d6, d8 and d10, plain and helped. They share band keys, as a Check set requires | Parent |
| Missing crew | No effect closes an NPC, and a pick cannot exclude one by state. The `gone` step tells the player to close the crew member in the NPC list; a closed NPC is never picked, so nobody is taken twice and the `none` branch ends the turn. No engine change | Parent |
| Confront cost | The tip is reworded to match the bands: only a confrontation that goes badly costs Nerve. The bands stay as built | Parent |
| NarrativeFlows | The Long Carry (Hard Country), Cold Berth (Dogwatch). Stories are flows inside the systems | User |
| Truths | Teach sealed, emergent and fixed truths | User |
| License | Whole manual and both presets CC BY-SA 4.0. Kept from the first plan, though no share-alike source forces it any more | User, kept by Parent |
| Delivery | Full edition in the app; Paper edition and card sheets as PDFs | User |
| Alpha scope | The whole manual is finished before sharing the alpha | User |
| Examples | Each chapter adds to both flows, with short played excerpts showing an oracle table roll driving the story | User |
| Presets | Hard Country and Dogwatch ship as GameSystem presets, built alongside the manual, each with its flow | User |
| Create from a step | "New table" in the step editor's table pickers | User |
| New table scope | Prompt inspiration and `table` steps, in the Scene Type and Flow editors of a GameSystem draft. Library packs, `oracle` steps, Scene Type shortcuts and `check` steps keep today's behaviour | Parent |
| New table draft | A weighted table with the seed entry `newTable` already gives (contract: 1–1000 entries), Key and Name fields as in the Oracles tab, selected in the step once created, with a link to fill it in | Parent |
| New table and the open editor | Sent with the editor's `readAt`, so the editor's unsaved step survives and it adopts the returned version. A 409 keeps the draft and shows the usual reload path | Parent |
| Sealed Truths in Ogami | The glossary and contract have no hidden Fact. On paper the Truth is sealed; in Ogami it is rolled at Reveal | Parent |
| Terms | The paper edition uses the glossary terms, so a reader moves between editions without translation | Parent |
| Language | English | Parent |
| Studio rail order | Overview, Flows, Scene Types, Trackers & tags, Oracles, Sheet, Checks, Gear, Library, Releases | Parent |
| Manual source | One Markdown file per page in `frontend/src/manual/content/`, ordered by a typed manifest (slug, title, part, editions). The Paper build in slice 8 reads the same files | Parent |
| Edition blocks | `:::in-ogami` and `:::` on their own lines, split from the source before Markdown rendering, so no directive parser is needed. An unclosed or nested block is an error, caught by a test over all content | Parent |
| Markdown rendering | `react-markdown` with `remark-gfm`, no raw HTML. Elements map to the app's tokens; no typography plugin | Parent |
| Manual access | Public `/manual` route with no role guard, a Manual link in the main nav for everyone, and links from Studio. The app shows the Full edition only | Parent |

## Manual structure

Every chapter: concept, on paper, In Ogami (Full only), examples from both flows.

**Front matter:** how to use the book, what you need, the two games, credits and license.

**Part I, The Narrative Flow** (both editions)

1. The Promise: the jobs a GM does (pacing, surprise, memory), premise, tone, length
2. The Arc: Phases, `once` and `loop`, Acts, Session Zero
3. Scene Types: purpose, tips, setup/play/closing
4. Steps: prompts, choices, branches, effects, writing questions instead of answers
5. Choosing the next scene: `sequence` (ordered stack), `player` (face-up spread), `oracle` (shuffled deck, weight is copies)
6. Memory: Facts, Threads, NPCs, Places, Tags
7. Pressure: Trackers, clocks, World turn, random events
8. Session rituals: Hooks
9. Truths and endings: sealed, emergent, fixed; multiple endings
10. Cards and print: card anatomy, drawing in order or at random, seeding a finale, discard or reshuffle
11. Playtest and share: handing a flow on; on paper a flow travels alone, in Ogami it lives inside a release

**Part II, Solo Toolbox** (both editions): likelihood oracles; oracle tables (ranged, weighted, nested); inspiration and random events; trackers and clocks at the table; cards and dice as oracles; journaling and lists.

**Part III, Authoring a GameSystem** (Full only, examples Hard Country and Dogwatch): rules versus story, one system with many flows; sheets and groups; derived values and Formulas; checks and outcome bands; gear and Currency; licensing and Credits.

**Part IV, Studio Reference** (Full only): tour of each section; Library packs; validation; Releases, Restore, Themes; what publishing does to running campaigns.

**Appendices:** both complete flows with card sheets, one rules sheet per system, paper-to-Ogami map, blank templates, glossary, credits and "Inspired by" lists.

## Flows

**The Long Carry** (Hard Country, 3–4 sessions). Will you get the saint to the shrine before first snow, and what will it cost?

| Phase | Run | Selection | Content |
|---|---|---|---|
| Session Zero | `once` | `sequence` | The bearers, the saint, the vow |
| The Road | `loop` | `oracle` | Camp, Ford, Stranger, Ruin, Omen; finale seeded low in the deck; World turn adds to Winter and rolls Road Risks |
| The Shrine Gate | `once` | `sequence` | The arrival |
| Epilogue | `once` | `sequence` | Three endings by Winter and Burden |

Trackers: Winter (clock), Burden (counter). Fact slots: Saint, Sin, Vow. Truth: emergent, the Sin written as Omens accumulate. Oracle example: Road Risks gives "the ford has risen", forcing a Ford scene, adding 1 to Winter and calling for a Back check. Inspired by: the translation of St Cuthbert's body; *As I Lay Dying*; *The Three Burials of Melquiades Estrada*.

**Cold Berth** (Dogwatch, 2–3 sessions). What is aboard, who let it on, and why were we woken?

| Phase | Run | Selection | Content |
|---|---|---|---|
| Session Zero | `once` | `sequence` | Crew cards, seal three Truths |
| Wake | `once` | `sequence` | The empty berth, the custodian's refusal |
| The Ship | `loop` | `player` | Investigate, Repair, Confront, Hide, then pick a compartment (Place); World turn raises Threat and picks a missing crew member |
| Reveal | `once` | `sequence` | Open the Truths |
| Epilogue | `once` | `sequence` | Who gets out, by Clues and Threat |

Trackers: Threat (clock), Clues (counter). Fact slots: What is aboard, Who let it on, Why we were woken. Truth: sealed, with a sidebar on a fixed solution. Oracle example: the Treason table gives "falsified cryo log", naming a suspect and adding a Clue. Inspired by: *Alien*; *2001: A Space Odyssey*; *Passengers*. Avoid *Alien*'s creature life cycle, android-traitor reveal and plot sequence.

## Constraints

- No text, names or characters from other games or from the "Inspired by" works. Hard Country and Dogwatch are written from scratch, in their own words.
- A frontend library for rendering Markdown is added only in slice 2 ([ADR 0004](../../docs/adr/0004-react-vite-spa-ui-toolkit.md)).
- Chapters are written from the built presets, so every "In Ogami" claim is checked against the app.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. One slice per session.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-manual-1-authoring-order` | `feat(studio): studio-manual 1/11 authoring order` | T1–T4 | ~800 |
| 2 | `feat/studio-manual-2-reader` | `feat(frontend): studio-manual 2/11 in-app manual reader` | T5 | ~1,000 |
| 3 | `feat/studio-manual-3-hard-country` | `feat(presets): studio-manual 3/11 Hard Country and The Long Carry` | T16, T17, T6 | ~1,600 |
| 4 | `feat/studio-manual-4-dogwatch` | `feat(presets): studio-manual 4/11 Dogwatch and Cold Berth` | T18, T7 | ~1,300 |
| 5 | `docs/studio-manual-5-flow-design` | `docs(manual): studio-manual 5/11 promise to steps` | T19, T8 | ~1,400 |
| 6 | `docs/studio-manual-6-flow-running` | `docs(manual): studio-manual 6/11 selection to rituals` | T9 | ~1,300 |
| 7 | `docs/studio-manual-7-flow-sharing` | `docs(manual): studio-manual 7/11 truths to sharing` | T10 | ~1,100 |
| 8 | `feat/studio-manual-8-print` | `feat(manual): studio-manual 8/11 Paper edition and cards` | T11 | ~1,400 |
| 9 | `docs/studio-manual-9-toolbox` | `docs(manual): studio-manual 9/11 solo toolbox` | T12 | ~1,000 |
| 10 | `docs/studio-manual-10-gamesystem` | `docs(manual): studio-manual 10/11 authoring a GameSystem` | T13 | ~1,000 |
| 11 | `docs/studio-manual-11-reference` | `docs(manual): studio-manual 11/11 Studio reference and appendices` | T14 | ~1,400 |

## Tasks

| ID | Task | Route | Done | Commit |
|---|---|---|---|---|
| T1 | This feature doc and slice plan | inline: one file, already understood | [x] | `119b293e` |
| T2 | Reorder the Studio rail to the top-down order | delegated: slice runs under one writer | [x] | `bf6ddf93` |
| T3 | "New table" in the step editor's table pickers (prompt inspiration, `table` step): creates a named table without leaving the step. Find out whether item rules need a first entry, and seed one if so | delegated: slice runs under one writer | [x] | `a7699c5e`, `63d78359` |
| T4 | ADR for the manual; roadmap line; glossary: a `pick` step also picks Places and Characters, as the contract says | delegated: slice runs under one writer | [x] | `9ed3a24f` |
| T15 | Slice 1 review findings: New table in Library packs, unsaved step edits on the link to the new table, dialog dismissal while a create is pending | delegated: slice runs under one writer | [x] | `8224f080` |
| T5 | In-app Manual route, Markdown rendering, edition blocks, links from Studio; front matter as the first content | delegated: slice runs under one writer | [x] | `30c52278`, `ef0533be`, `2e1f831a` |
| T16 | Slice 2 review findings: `Markdown.tsx` element overrides, `editionBlocks.ts` fence detection, the four missing tests | delegated: slice runs under one writer | [x] | `f164a51d` |
| T17 | Replace Cairn and 24XX with Hard Country and Dogwatch in the front matter, credits, ADR 0050 and roadmap | delegated: slice runs under one writer | [x] | `5493e5a8` |
| T6 | Hard Country GameSystem preset and The Long Carry flow, with credits | delegated: slice runs under one writer | [x] | `507c5194` |
| T18 | Slice 3 review findings: CRLF in `editionBlocks.ts` fence detection, Ford bands against the harm rule, four flow test gaps | delegated: slice runs under one writer | [x] | `cd1538e1` |
| T7 | Dogwatch GameSystem preset and Cold Berth flow, with credits | delegated: slice runs under one writer | [x] | `193ca200` |
| T19 | Slice 4 review findings: Confront tip against its bands, Hide Nerve gain and Nerve floor tests, mandatory picks, a crew member taken twice | delegated: slice runs under one writer | [x] | |
| T8 | Part I, chapters 1–4 with examples | delegated | [x] | |
| T9 | Part I, chapters 5–8 with examples | delegated | [ ] | |
| T10 | Part I, chapters 9–11 with examples | delegated | [ ] | |
| T11 | Paper edition build without "In Ogami" blocks, PDF pipeline, card sheets for both flows | delegated | [ ] | |
| T12 | Part II | delegated | [ ] | |
| T13 | Part III | delegated | [ ] | |
| T14 | Part IV, appendices, final PDFs | delegated | [ ] | |

## Applicable checks

- `make verify` once per slice; CI runs the full suites and e2e.
- Test first for T2, T3 and T5 (Vitest). T6 and T7: publish the preset (`make presets`) and play the flow end to end. T8–T14: structural readback, plus every "In Ogami" claim tried in the app.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|
| 1 | `review-5fe06daa59406812` | `bf6ddf93`, `a7699c5e`, `9ed3a24f`, `63d78359` | Approved | [#388](https://github.com/iribarren/ogami/pull/388), merged | Three, fixed in slice 2 (T15): New table may appear in a Library pack's Scene Type editor; the link to the new table may lose unsaved step edits; the dialog can be dismissed while a create is pending |
| 2 | `review-0bc46f3f61d7ce00` | `8224f080`, `30c52278`, `ef0533be` approved; `2e1f831a` (e2e expectation) review skipped by the user | Approved | [#389](https://github.com/iribarren/ogami/pull/389), merged | Three, fixed in slice 3 (T16): `Markdown.tsx` overrides spread `node` onto the DOM and let Markdown's `className` replace the app's classes; `editionBlocks.ts` fence detection is looser than CommonMark, so a false or unclosed fence can hide later markers; missing tests for the `FlowEditor` Library pack negative, the last-page pager, the Studio Manual links and raw HTML as visible text |
| 3 | `review-b82baad154574b9d` | `f164a51d`, `5493e5a8`, `507c5194` | Approved | [#390](https://github.com/iribarren/ogami/pull/390), merged | Three, fixed in slice 4 (T18): `editionBlocks.ts:36` fence regex fails on CRLF lines; `hard-country.json:182` Ford bands disagree with the harm rule (Wind first, then Wounds); flow test gaps (a second Omen replaces the Sin, Winter edges 5/6/9, the far case leaves no pending gate step, the Camp rest cap) |
| 4 | `review-a353d854eee7de83` | `cd1538e1`, `193ca200` | Approved | [#391](https://github.com/iribarren/ogami/pull/391), merged | Five, fixed in slice 5 (T19): `dogwatch.json:241` Confront tip promises a Nerve cost its `done` band does not take; `:267` Hide `done` Nerve gain unproved, as the tests start at full Nerve; `:249` Nerve floor unproved (harm at Nerve 0, a 2-point loss at Nerve 1); `:348` the `missing` pick and the four job-opening picks are not mandatory and their skip path is untested; `:349` a crew NPC can go missing twice and the `none` branch needs manual closing. Open product note: Treason's "falsified cryo log" names a suspect through a Thread, as no effect tags an NPC |

## Progress

- Planned on 2026-11-09.
- Slice 1 started on 2026-11-09.
- T2 done: rail reordered; the order test in `GameSystemPage.test.tsx` updated (RED observed with the old rail).
- T3 done: "New table" opens a dialog with the Oracles tab's `CreateForm`; draft version is draft-wide, so the create sends the editor's `readAt` and the editor adopts the answer; a 409 sets the editor's conflict state. Item rules need no extra first entry beyond the seed `newTable` gives. RED observed (7 failing), then GREEN.
- T4 done: ADR 0050, roadmap B26, glossary Pick step row.
- T15 done: a pack reaches both editors (`SectionRail.tsx` lists Scene Types for packs; the Scene Type route takes any key), so both editors now offer "New table" only when `useStudioGameSystem` says `game-system` (RED: pack test failed). The unsaved-step link was already blocked by `UnsavedChangesGuard` (new editor tests passed at once, no fix). `NewTableButton` ignores dismissal while a create is pending (RED: 3 tests failed), so a failure stays visible.
- T5 done: `/manual` and `/manual/$chapter` (public, `beforeLoad` not-found), `splitEditionBlocks`, `react-markdown` + `remark-gfm` without raw HTML, four front matter pages, Manual links in the main nav, Studio home and GameSystem header. Splitter tests were written with the splitter, so no separate RED; the AppShell Manual test is the only one that failed first.
- Slice 2 size: about 1,828 changed lines, 868 of them `pnpm-lock.yaml`. Kept as one slice by the user's decision, dependency in its own commit.
- T16 done: `Markdown.tsx` overrides drop `node` and merge `className`; `editionBlocks.ts` follows CommonMark fences and throws on an unclosed one. RED: 5 new tests failed on the old code (fence rules, unclosed fence, merged class); the Library pack, pager, Manual link and raw-HTML-text tests passed at once, as they cover behaviour that already worked.
- T17 done: front matter, credits, ADR 0050 (dated amendment) and roadmap B26 now name Hard Country and Dogwatch; Cairn and 24XX remain only as design influences. Docs only, so structural readback plus the content tests.
- T6 done: `hard-country.json` (key `hard-country`) with The Long Carry; `make presets` published it (v1, then v2 after wording changes). The Road uses `oracle` selection on a weighted `road-deck` table (weight is copies), and the finale comes late through two Winter-driven closing steps: a condition (nothing below Winter 6, a d6 from 6 to 9, certain at 10) that ends the phase, so the gate is not a card in the deck. The engine cannot pin the check a table entry calls for: the risen ford (Road Risks) forces a Ford scene and adds 1 to Winter, and the Ford scene lets the player pick Back or Feet, with the table text asking for Back. Wind is a track with max 8 set to d6+2 by the bearer step. `HardCountryPresetFlowTest` (29 tests) plays the flow; it was written with the preset, so its first failures were test mistakes, not preset ones (no separate RED).
- T18 done: `editionBlocks.ts` splits on `/\r?\n/` (RED: the CRLF test failed first). The engine cannot branch a flow on a sheet field (`condition` reads only Trackers and item counts), so the Ford `badly` band now takes d6 Wind like a failure and keeps its Burden, and the step tip says a bearer with no Wind left marks a Wound; no other Hard Country step harms a bearer. Four flow tests added (second Omen, Winter edges 5/6/9, no pending gate step when far, rest cap at 8); they passed at once, as they cover behaviour that already worked.
- T7 done: `dogwatch.json` (key `dogwatch`, flow `cold-berth`) published as v1 by `make presets`, no warnings. Stations are `select` fields over d4–d10, eight checks share the bands `wrong`/`short`/`done`, and every `check` step offers all eight. The engine cannot branch on a sheet field, so each harm effect takes Nerve and the step tip says a crew member with no Nerve left marks an Injury. The Ship loop ends on Threat: 4 or less ends the closing, 5 to 7 rolls a d6 (5 or 6 ends the phase), 8 ends it for certain. The three Truths are `table` steps with `fillFact` at the Reveal; Session Zero only sets down hunches. Crew and compartments are NPCs (tag `crew`) and Places (tag `compartment`) made in Session Zero; the World turn picks a random crew NPC and opens a Thread, and each job opens by picking a Place, with a prompt that names a new one when none is left. A pick with no candidates is completed with an empty list. `DogwatchPresetFlowTest` (51 tests) was written with the preset, so its first failures were test mistakes, not preset ones (no separate RED).
- T19 done: Confront tip now says only a confrontation that goes badly costs Nerve (bands unchanged). The `missing` pick and the four job-opening `where` picks are `mandatory`; the `gone` step has a tip to close the crew member in the NPC list. RED: the five mandatory-pick tests failed on the old preset. The Hide `done` Nerve gain from Nerve 3, the Nerve floor (harm at 0, 2-point loss at 1 and at 0: Nerve stops at 0, the Injury is marked by hand) and the repeat World turn (a closed crew member is not offered, an empty pick ends the turn) passed at once, as they cover behaviour that already worked. `make presets` published Dogwatch v2 with no warnings of its own.
- T8 done: `the-promise`, `the-arc`, `scene-types` and `steps` in `frontend/src/manual/content/`, listed under `Part I, The Narrative Flow` in `chapters.ts`. Each has the concept, an `## On paper` section, one `:::in-ogami` block and examples from both flows, with played excerpts driven by Road Risks (the risen ford, in Scene Types), Ship trouble (chapters 1 to 3), Strangers and Treason (the falsified cryo log, in Steps). `content.test.ts` now checks that each Part I page has an On paper section and exactly one In Ogami block. Documentation, so structural checks only (content tests, a grep for the app outside the blocks); every In Ogami claim is listed in the writer's report for the parent to try in the app.

## Next step

Build slice 5 (T19, T8) on `docs/studio-manual-5-flow-design`, review it, push and open its PR.
