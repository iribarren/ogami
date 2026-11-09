# Feature: studio-manual

- **Locator:** `odd/tasks/studio-manual.md` · Engram topic `odd/studio-manual/tasks`
- **Issue:** [#387](https://github.com/iribarren/ogami/issues/387) · **Branch:** `feat/studio-manual-3-hard-country` · **Status:** in progress (slice 3)
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
| Dogwatch rules | Designed in slice 4: one skill die per check, with sizes by skill, and help as rolling that die twice and keeping the highest (`2d8kh1`, because keep-highest works only within one dice term) | Parent |
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
| 4 | `feat/studio-manual-4-dogwatch` | `feat(presets): studio-manual 4/11 Dogwatch and Cold Berth` | T7 | ~1,300 |
| 5 | `docs/studio-manual-5-flow-design` | `docs(manual): studio-manual 5/11 promise to steps` | T8 | ~1,300 |
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
| T16 | Slice 2 review findings: `Markdown.tsx` element overrides, `editionBlocks.ts` fence detection, the four missing tests | delegated: slice runs under one writer | [x] | |
| T17 | Replace Cairn and 24XX with Hard Country and Dogwatch in the front matter, credits, ADR 0050 and roadmap | delegated: slice runs under one writer | [ ] | |
| T6 | Hard Country GameSystem preset and The Long Carry flow, with credits | delegated: slice runs under one writer | [ ] | |
| T7 | Dogwatch GameSystem preset and Cold Berth flow, with credits | delegated | [ ] | |
| T8 | Part I, chapters 1–4 with examples | delegated | [ ] | |
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

## Next step

Build slice 3 (T16, T17, T6) on `feat/studio-manual-3-hard-country`, review it, push and open its PR.
