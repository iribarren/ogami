# Feature: manual-voice-rewrite

- **Locator:** `odd/tasks/manual-voice-rewrite.md` · Engram topic `odd/manual-voice-rewrite/tasks`
- **Issue:** [#402](https://github.com/iribarren/ogami/issues/402) · **Branch:** `docs/manual-voice-rewrite-3-toolbox` · **Status:** in progress (slice 3)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** the follow-ups of [`manual-voice`](manual-voice.md) (#399, PR #400) and [`manual-voice-followups`](manual-voice-followups.md) (#401, PR #403)

## Objective

Rewrite every chapter of the manual in `frontend/src/manual/content/`, except The Promise, in the house voice of `docs/style/manual-voice.md`, in English and in place.

## Problem and why

The Promise was rewritten as the pilot and calibrated by the user, but the other 39 chapters still read as machine-written: staccato assertions, colon reveals, punchline closers and reflexive triads. A reader moving from The Promise to The Arc hears two different books.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Language | English only. A Spanish edition of the app manual becomes a follow-up issue, because it needs the Dogwatch Spanish lexicon and the ES title of The Arc confirmed, and the app has no Spanish manual route | User, 2026-11-25 |
| Dial | Style guide defaults: dial 0 for front matter, Parts I–IV, Blank Templates, Glossary, From Paper to Ogami and Credits and Inspirations; dial 1 for The Long Carry, Cold Berth, Hard Country Rules and Dogwatch Rules, with their voice packs. Rules statements stay neutral everywhere | User, 2026-11-25 |
| Slicing | Five slices by part, each read by the user for calibration before the next | User, 2026-11-25 |
| Old-text fixes, slice 1 | what-you-need: stop at a natural pause, not when a set time runs out ("clock" was ambiguous). the-two-games: **Epilogue**, the preset's phase name. how-to-use: Paper is played with pencil, paper, cards and dice only, the kit is in What you need. scene-types: two parts of text (purpose, tips) and three of play; Cold Berth jobs differ in what they do to Threat, Clues and the crew's Nerve (Clues is a counter, not a clock); its shared setup also asks Hide's question. Phase names are bolded exactly as the presets name them (**Epilogue**, **Reveal**), in this slice and the next ones; Cold Berth's Scene Type keeps "The Reveal". choosing-the-next-scene: between them the two flows use all three selection rules (The Long Carry `sequence` and `oracle`, Cold Berth `sequence` and `player`). Quoted preset entries match exactly, end punctuation included, in every slice. A writer's added reason is a new claim and goes, even when it is true by arithmetic | User, 2026-11-25 |
| Calibration read, slice 1 | New terms get a friendly first explanation, introduced through something familiar (three acts of a film for the arc, a chase or a fight for Scene Types); chapters lean only on ideas already introduced; warmth from the reader's play (what your character did, the people you met); no remarks about the text that carry no information, no tacked-on qualifiers. Content: the two editions are play at a table vs. with the companion app; index cards are optional and a text file is a valid notebook; Where you play is about a cosy mood (music); The Two Games introduces GameSystem and NarrativeFlow gently and says the manual builds a flow that plays differently each time. Follow-through: On paper instructions say "an index card, or a page of your notebook" (here and in later slices, e.g. cards-and-print); The Arc's three-act opening is about shape, and its Acts section links the Act label back to film acts. The general rules are in `docs/style/manual-voice.md` ("New ideas arrive with something familiar", "Signposting and filler") | User, 2026-11-26 |
| Slice 1 open points | Keep the playing-card substitute in choosing-the-next-scene (On paper), since index cards are optional. The cut-off third how-to-use note is dropped | User, 2026-11-26 |
| Old-text fixes, slice 2 | Every mention of a Phase name is bolded exactly as the preset spells it, mid-sentence too (**The Road**, **The Shrine Gate**), in every chapter including slice 1's, outside `:::in-ogami` blocks. Capitalization fixes ("World turn") are allowed inside blocks, nothing else. Old text that a preset contradicts is corrected to match it. The glossary gains the `place` Fact slot type and Place tags, which the code and `dogwatch.json` already use | User, 2026-11-26 |
| Per-task cycle | `doc-writer` drafts (no commit) → `doc-editor` reviews → `doc-writer` revises on a `revise` verdict → the parent runs the manual Vitest suite and commits | Parent |

## Constraints

- Prose only: no product code, no preset changes. A disagreement between a chapter and its preset is reported, not resolved.
- Every `:::in-ogami` block, every heading other chapters or the app link to, and every chapter title in `chapters.ts` survives unchanged. In a chapter in both editions, text outside the blocks never names Ogami, Studio or the app and holds no `](/` link.
- Glossary terms are written exactly as `docs/domain/glossary.md` spells them. Quoted oracle table entries match the presets word for word.
- A restyle drops no point and adds no claim (style guide, "Facts, terms and fences").

## Slice plan

A rewrite counts about twice its source lines (deletions plus additions). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Chapters (source lines) | Forecast |
|---|---|---|---|---|
| 1 | `docs/manual-voice-rewrite-1-part-one` | `docs(manual): manual-voice-rewrite 1/5 front matter and Part I opening` | how-to-use, what-you-need, the-two-games, credits-and-license, the-arc, scene-types, steps, choosing-the-next-scene (619) | ~1,300 |
| 2 | `docs/manual-voice-rewrite-2-part-one-close` | `docs(manual): manual-voice-rewrite 2/5 rest of Part I` | memory, pressure, session-rituals, truths-and-endings, cards-and-print, playtest-and-share (548) | ~1,150 |
| 3 | `docs/manual-voice-rewrite-3-toolbox` | `docs(manual): manual-voice-rewrite 3/5 Solo Toolbox` | likelihood-oracles, oracle-tables, inspiration-and-random-events, trackers-and-clocks, cards-and-dice, journaling-and-lists (566) | ~1,200 |
| 4 | `docs/manual-voice-rewrite-4-studio` | `docs(manual): manual-voice-rewrite 4/5 authoring and Studio` | Parts III and IV: rules-and-story … publishing-and-campaigns (670) | ~1,400 |
| 5 | `docs/manual-voice-rewrite-5-appendices` | `docs(manual): manual-voice-rewrite 5/5 appendices` | flow-the-long-carry, flow-cold-berth, rules-hard-country, rules-dogwatch (dial 1), blank-templates, glossary, paper-to-ogami, credits-and-inspirations (557) | ~1,200 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Follow-up issue for a Spanish edition of the app manual, and its `docs/roadmap.md` backlog line | Inline | done | `a343f47c` |
| T2 | 1 | Front matter at dial 0: how-to-use, what-you-need, the-two-games, credits-and-license | Delegated (`doc-writer`, `doc-editor`) | done | `fb9467dd` |
| T3 | 1 | The Arc and Scene Types at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | `63fe23fe` |
| T4 | 1 | Steps and Choosing the Next Scene at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | `bcf83192` |
| T5 | 1 | The user's calibration notes on slice 1 applied to seven chapters, and the general lessons added to the style guide | Delegated (`doc-writer`, `doc-editor`); guide inline | done | `41f17be9` |
| T6 | 2 | Memory and Pressure at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | `52ab5b7d` |
| T7 | 2 | Session Rituals and Truths and Endings at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | `3c04fb57` |
| T8 | 2 | Cards and Print and Playtest and Share at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | `a064a068` |
| T9 | 2 | Every Phase name bolded in the slice 1 chapters; the glossary gains the `place` Fact slot type and Place tags | Delegated (`doc-writer`, Sonnet; mechanical) | done | `0ead3d4f` |
| T10 | 3 | Likelihood Oracles and Oracle Tables at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | |
| T11 | 3 | Inspiration and Random Events and Trackers and Clocks at dial 0 | Delegated (`doc-writer`, `doc-editor`) | done | |
| T12 | 3 | Cards and Dice and Journaling and Lists at dial 0 | Delegated (`doc-writer`, `doc-editor`) | todo | |

## Applicable checks

- Per task: `doc-editor` verdict `ship` for every chapter, and the manual Vitest suite (`make pnpm ARGS="exec vitest run src/manual"`), which checks edition blocks, Paper fences and links.
- Per slice: `make verify` (a manual chapter runs only the Vitest suites that depend on `pages.ts`), and the user's calibration read.
- No runnable RED: the work is prose, so the tests guard structure, not voice.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|
| 1 | `review-94fecfc8297b1652` (T1–T4), `review-913cf0cea1d31847` (T5), `review-8c92f1a591338de8` (whole branch) | `a343f47c`, `fb9467dd`, `63fe23fe`, `bcf83192`, `41f17be9` | Low risk, closed approved, acknowledged | [#405](https://github.com/iribarren/ogami/pull/405) | None blocking |
| 2 | `review-f3091fc08e2859e8` | `52ab5b7d`, `3c04fb57`, `0ead3d4f`, `a064a068` | Low risk, closed approved, acknowledged | [#406](https://github.com/iribarren/ogami/pull/406) | None blocking. Preset mismatch found while writing: follow-up [#407](https://github.com/iribarren/ogami/issues/407) |

## Progress

- Explored: 39 chapters, 2,960 source lines outside The Promise; dials, slicing and the Spanish edition decided with the user.
- T1 done: issue [#404](https://github.com/iribarren/ogami/issues/404) `manual-spanish-edition` (`type:docs`, `follow-up`) and roadmap B30; B29 records the decisions. Check: structural readback.
- T2 done: front matter rewritten at dial 0; doc-editor ship for how-to-use, the-two-games and credits-and-license, revise for what-you-need (connectives that claimed false causes), fixed with the user's three old-text decisions. "World turn" capitalized as the glossary spells it. Check: manual Vitest 10 files, 277 tests passed.
- T3 done: The Arc (doc-editor ship) and Scene Types (revise: a repeated contrast frame replacing the old colons, a garbled tip clause) rewritten at dial 0 and revised; the user's old-text decisions applied. Check: manual Vitest 10 files, 277 tests passed.
- T4 done: Steps and Choosing the Next Scene rewritten at dial 0; doc-editor revise for both (the "all three rules" claim the presets contradict, an added reason, full stops on quoted entries the presets lack, a repeated example frame), fixed with the user's decisions. Check: manual Vitest 10 files, 277 tests passed.
- T5 done: the user's calibration notes applied to seven chapters (friendly first explanations through films and familiar scenes, the editions as table play vs. the companion app, optional index cards and a text-file notebook, a cosy Where you play); doc-editor asked for revisions on The Two Games (Session Zero is not a whole sitting, Cold Berth has three Truths, one definition of a flow), The Arc (the Act bridge) and the guide, all fixed. The Two Games now says the ship "picks a crew member to go missing", as the preset's "Who goes missing?" step does (the old text said "who is missing"). Guide: new section "New ideas arrive with something familiar", warmth from the player's side, two filler habits. Check: manual Vitest 10 files, 277 tests passed.
- T6 done: Memory (doc-editor ship) and Pressure (revise: a quote with a full stop the preset lacks, a loop-length sentence that misread) rewritten at dial 0 and revised. Old text corrected to the presets: quiet is three of the nine chances, the "last seen" question is the World turn's last step, Cold Berth's loop length gets the Threat qualifier, the opening no longer contradicts The Promise's three jobs, the NPC list. "Five places" became "Five kinds of record" (unlinked). Check: manual Vitest 10 files, 277 tests passed.
- T7 done: Session Rituals (doc-editor ship) and Truths and Endings (revise: an edition block reworded beyond capitals, unbolded Phase names) rewritten at dial 0 and revised. "Sitting" became "session" as the glossary names it, including the unlinked headings and the On paper card. Five quoted entries lose the final period the presets lack. The Burden Tracker's levels (only 0 is "Light") disagree with the Epilogue condition (2 or less is "Light"); the chapter quotes the condition, and the preset question is a follow-up. Check: manual Vitest 10 files, 277 tests passed.
- T9 done: every Phase name mention bolded in the slice 1 chapters and The Promise (the-arc 27 including its Phase tables, choosing-the-next-scene 8, scene-types 3, the-promise 1, the-two-games 1); quoted literal names, table names, the ship as a vessel and "a Ship round" left plain. The glossary's Fact slot, Tag and Place rows now state the `place` slot type and Place tags the code supports (`FactSlotType::Place`, `TAG_KINDS`). Check: manual Vitest 277 passed, Prettier clean, edition blocks unchanged.
- T8 done: Playtest and Share (doc-editor ship) and Cards and Print (revise) rewritten at dial 0 and revised. User decisions on old text: the rules card points to Chapters 6, 7 and 9 for its parts (the old "Chapter 7 describes what goes on it" was wrong), and a seeded finale in the bottom five of fifteen ends between the eleventh and the fifteenth draw (not the tenth). Three quoted entries lose the final period the presets lack; the Shrine Gate card stays plain (a Scene Type card, not the Phase). Check: manual Vitest 277 passed, Prettier clean, edition blocks unchanged.

- Slice 2 delivered in PR #406 (`make verify` 235 passed); the user's calibration read brought no notes. The Burden Tracker / Epilogue "Light" mismatch is follow-up [#407](https://github.com/iribarren/ogami/issues/407) (`hard-country-burden-light`), roadmap B31.
- T10 done: Likelihood Oracles and Oracle Tables rewritten at dial 0 (doc-editor ship for both; voice notes applied: repeated "If you have ever…" and "In this played…" openers, wrong "so" connectives, padding, a closing aphorism). User decisions on old text: rerolling a table, on another table or the same one, is fine when a result makes no sense or gives too little, with a remark that too many rolls can leave the first meaningless, and no hard limit (the old "do not nest more than twice, because at three rolls…" contradicted itself); quiet lines are roll-based, about one roll in three as Road Risks has (3 of 9 copies), and Try it now matches; the Long Carry table list names the road deck. One quoted Strangers entry loses a full stop the preset lacks; the Thread is quoted as the preset names it. Check: manual Vitest 277 passed, Prettier clean, edition blocks unchanged.
- T11 done: Inspiration and Random Events and Trackers and Clocks rewritten at dial 0; doc-editor revise for both (a "mostly quiet" claim, a d8 stated without its Station, circular and false connectives, a definition pointed at before it was given), fixed. The reroll rule now matches T10's: reroll when a result does not fit or gives too little, write both, no "once" limit. Old text corrected to the presets: quiet is the most common single result (3 of 9 chances), not "mostly"; one line can do both jobs (Road Risks' risen ford); only a ford that goes badly adds Burden (a plain failure adds Winter); "the Ship Phase" is **The Ship**; Clues is read in the **Epilogue**, not the Reveal. "World turn" capitalised inside one edition block. Check: manual Vitest 277 passed, Prettier clean.

## Next step

Slice 3: T10–T12 (Solo Toolbox, six chapters at dial 0), then `make verify`, native review, PR and the user's calibration read before slice 4.
