# Feature: manual-voice-rewrite

- **Locator:** `odd/tasks/manual-voice-rewrite.md` · Engram topic `odd/manual-voice-rewrite/tasks`
- **Issue:** [#402](https://github.com/iribarren/ogami/issues/402) · **Branch:** `docs/manual-voice-rewrite-1-part-one` · **Status:** in progress (slice 1)
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
| Old-text fixes, slice 1 | what-you-need: stop at a natural pause, not when a set time runs out ("clock" was ambiguous). the-two-games: **Epilogue**, the preset's phase name. how-to-use: Paper is played with pencil, paper, cards and dice only, the kit is in What you need. scene-types: two parts of text (purpose, tips) and three of play; Cold Berth jobs differ in what they do to Threat, Clues and the crew's Nerve (Clues is a counter, not a clock) | User, 2026-11-25 |
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
| T2 | 1 | Front matter at dial 0: how-to-use, what-you-need, the-two-games, credits-and-license | Delegated (`doc-writer`, `doc-editor`) | done | |
| T3 | 1 | The Arc and Scene Types at dial 0 | Delegated (`doc-writer`, `doc-editor`) | todo | |
| T4 | 1 | Steps and Choosing the Next Scene at dial 0 | Delegated (`doc-writer`, `doc-editor`) | todo | |

## Applicable checks

- Per task: `doc-editor` verdict `ship` for every chapter, and the manual Vitest suite (`make pnpm ARGS="exec vitest run src/manual"`), which checks edition blocks, Paper fences and links.
- Per slice: `make verify` (a manual chapter runs only the Vitest suites that depend on `pages.ts`), and the user's calibration read.
- No runnable RED: the work is prose, so the tests guard structure, not voice.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|

## Progress

- Explored: 39 chapters, 2,960 source lines outside The Promise; dials, slicing and the Spanish edition decided with the user.
- T1 done: issue [#404](https://github.com/iribarren/ogami/issues/404) `manual-spanish-edition` (`type:docs`, `follow-up`) and roadmap B30; B29 records the decisions. Check: structural readback.
- T2 done: front matter rewritten at dial 0; doc-editor ship for how-to-use, the-two-games and credits-and-license, revise for what-you-need (connectives that claimed false causes), fixed with the user's three old-text decisions. "World turn" capitalized as the glossary spells it. Check: manual Vitest 10 files, 277 tests passed.

## Next step

Slice 1: T1, then T2–T4 through the writer/editor cycle.
