# Feature: manual-voice

- **Locator:** `odd/tasks/manual-voice.md` · Engram topic `odd/manual-voice/tasks`
- **Issue:** [#399](https://github.com/iribarren/ogami/issues/399) · **Branch:** `docs/manual-voice-1-pilot` · **Status:** done
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** brainstorm of 2026-11-24, after the studio-manual PDF read as machine-written

## Objective

A documentation writer for Ogami manuals (Studio, Play, Admin and game systems) that writes in one house voice, in English and Spain Spanish, with a dial for game-flavoured voice. A pilot chapter lets the user tune the voice before any larger rewrite.

## Problem and why

The studio-manual is clear, but it reads as machine-written. Its paragraphs are runs of short, independent assertions with the reasoning between them left out, and they rarely use connectives (because, so, which means, although). Most paragraphs end on an aphorism ("Everything below it exists to keep it."), explanations turn on colon reveals, and lists of three appear by reflex. Readers notice these tells and trust the text less, and the next manuals (Play, Admin, game systems) would repeat them without a written voice to follow.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Base voice | Designer essay (in the manner of Blades in the Dark or Shape Up): it argues for its method and explains why, not only what | User |
| Narrator | "We", the Ogami team. The reader is "you" | User |
| Personality | Earnest, no humour. Warmth comes from care and clear reasoning | User |
| Languages | English and Spain Spanish (tú, vosotros). Each edition is written natively in its language, not translated sentence by sentence | User |
| Game dial | 0 neutral; 1 played examples and oracle table entries; 2 also in-world sidebars and chapter openers voiced by the pack's narrator. Rules text stays neutral at every level | User |
| Voice packs | Repo files in `docs/voices/`, one per GameSystem: persona, EN and ES lexicon, samples, taboos. The format is shaped so it can later become a GameSystem "Voice" field in Studio | User |
| Spanish slang | Chosen by a person in the voice pack; the agent never invents a translation of genre slang | User |
| Guardrail | The writer self-checks against the style guide, then a separate editor agent reviews the draft and returns line-level notes. No CI lint for now | User |
| Hard Country narrator | An old bearer who has walked the carry before and buried friends on the road. Changeable after the pilot | Parent (user did not pick) |
| User calibration (2026-11-25) | Voice length, structure and tone are right. Fix clarity: idioms that read oddly taken literally, and "promise" used in several senses close together. ES: *el santo*; The Long Carry is *La gran Carga*, so Burden becomes *Carga*. Titles are translated by meaning and resonance, not literally | User |
| Spanish pilot location | The app's manual has no Spanish edition yet, so the Spanish chapter and the dial-2 example are calibration samples in `docs/style/samples/`, not manual content | Parent |

## Constraints

- No product code changes. The English rewrite of The Promise replaces `frontend/src/manual/content/the-promise.md` and must keep its `:::in-ogami` edition blocks (see `docs/style/manual-voice.md`), headings that tests or links rely on, and every factual claim about the flows.
- Glossary terms from `docs/domain/glossary.md` stay exactly as written (`NarrativeFlow`, Tracker, Fact, oracle table…).
- Voice packs evoke a genre; they never copy the text, names or invented slang of a specific existing game.

## Slice plan

Forecasts include docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `docs/manual-voice-1-pilot` | `docs(manual): manual-voice 1/1 doc-writer agent and pilot chapter` | T1–T4 | ~1,200 |

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Voice system: `docs/style/manual-voice.md` (style guide with the AI-tell list, before/after pairs, EN and ES rules, the dial), `docs/voices/README.md` (voice-pack format), `docs/voices/hard-country.md`, `.claude/agents/doc-writer.md`, `.claude/agents/doc-editor.md`, roadmap backlog line | Delegated (writer trigger: 5 non-trivial files) | done | `c633db24` |
| T2 | Pilot: The Promise rewritten in English at dial 0 (in place), a Spanish version and one Long Carry played example at dial 2 in `docs/style/samples/`, drafted by a worker following `doc-writer.md` and reviewed by one following `doc-editor.md` | Delegated (writer trigger; the pilot exercises the agents) | done | `498212d9` |
| T3 | Calibration fixes from the user: clarity of idioms and of the overloaded word "promise" in The Promise (EN and ES); ES uses *el santo* and the title *La gran Carga* (Burden becomes *Carga*, Load another word); the guide gains rules for literal clarity, one meaning per word near a term, and titles translated by meaning; "Most results are quiet" fixed in both preset tips and `flow-the-long-carry.md` (quiet is 3 of 9, the most common single result) | Delegated (`doc-writer` agent, then `doc-editor`) | done | `ca0dda07` |
| T4 | `scripts/verify-changed.sh` skips every `*.md`, so manual chapter edits never run the manual Vitest suites: run them for `frontend/src/**/*.md`, with a `make verify-test` case | Delegated (late finding of this slice) | done | `7d0762b6` |
| T5 | Doc agents point at the guide's tell catalogue instead of copying it; Engram mirror is the parent's job | Delegated | done | `1c41f848` |
| T6 | Apply the doc-editor notes on T3: literal-reading fixes in EN and ES, *mesa* for the group, article for Tracker names, guide models and the title-pun exception, per-file verdicts for doc-editor | Delegated (doc-writer) | done | `efaa2eca` |

## Applicable checks

- `make verify` once per slice (manual content changes run the manual Vitest suites).
- T1, T2: structural readback. T2: the editor's notes are resolved, and every fact in The Promise still matches the Hard Country and Dogwatch presets.
- No runnable RED: the work is prose and agent instructions.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|
| 1 | none | `c633db24`, `498212d9`, `ca0dda07`, `7d0762b6`, `1c41f848`, `efaa2eca` | Review skipped by the user (high risk on the 8-line script change; prose reviewed twice by doc-editor) | [#400](https://github.com/iribarren/ogami/pull/400) | None from a native review. Open decisions (Spanish lexicon to confirm, a Dogwatch voice pack, where the Tracker article rule lives, rewriting the rest of the manual) are in follow-up [#401](https://github.com/iribarren/ogami/issues/401) |

## Progress

- T1 done: style guide `docs/style/manual-voice.md` (house voice, 10 tells with before/after, EN and ES rules, ES terminology, dial, facts and `:::in-ogami` fences), voice-pack format and the draft Hard Country pack (all ES terms proposed, saint's gender and flow title open), `doc-writer` and `doc-editor` agents, roadmap B27; no runnable RED, structural readback and `make verify DRY_RUN=1`.
- T2 done: The Promise rewritten in EN at dial 0 in place, the ES Promise and a Long Carry dial-2 sample (EN and ES) in `docs/style/samples/`, drafted with doc-writer and revised from one doc-editor review; the pilot's gaps closed in the guide (dial-0 played examples, fidelity beyond numbers, ES reserved-word collisions, preset text in ES, quiet-result drift in its samples), the pack README (provisional fill for forced grammar choices) and both agents (stack-down test command, editor read-only `Bash`, multi-file report, minor note defined). Provisional ES choices (*la santa*, *El largo acarreo*, the pack's proposed terms, *El arco*) await the user. Checks: `vitest run src/manual` 10 files, 277 passed; `make verify` plans nothing, because `scripts/verify-changed.sh` skips every `*.md`, manual chapters included.
- T3 done: The Promise EN and ES clarified (figures that failed a literal reading, "promise" and "table" kept to their term senses; ES drops *mesa*, *carga* and *cargar* in other senses), ES samples and pack use *el santo*, *La gran Carga* and *Carga* for Burden (confirmed by the user), Load proposed as *Hatillo* with *huecos*; the guide gains the tells "Figures that fail a literal reading" and "One word in two senses" and a rule for Spanish titles; the quiet-result tip fixed in both presets and `flow-the-long-carry.md` (one roll in three, the most common result), published as dogwatch v3 and hard-country v4. Checks: `vitest run src/manual` 10 files, 277 passed; `make verify` passed (backend-qa, backend-test, api-check).
- T4 done: `verify-changed.sh` now plans `make frontend-test-related FILES="src/manual/pages.ts"` for `frontend/src/manual/content/*.md` (chapters load through a glob in `pages.ts`, so `related` on the .md finds nothing), with two `make verify-test` cases (RED: both failed before the change, 21 passed after); other Markdown stays skipped.
- T5 done: `doc-writer` and `doc-editor` point at the guide's tell catalogue section instead of listing tells, and `doc-writer` notes that the Engram mirror is the parent's job.
- T6 done: the doc-editor notes on `ca0dda07` applied. The Promise EN and ES lose the readings that failed taken literally (the promise as a piece, "another way", the repeated table entry); ES uses *mesa* for the people playing (*grupo* stays the Party), names its referents and drops the two senses of *peso*; Tracker names take the article in ES prose, recorded in the Hard Country pack and applied in both samples; the guide's catalogue models match the chapter, and a title pun is an exception to one meaning per word that stops at the title; `doc-editor` gives one verdict per file and may query fact sources read-only.
- Closed: PR [#400](https://github.com/iribarren/ogami/pull/400); native review skipped by the user; follow-ups in [#401](https://github.com/iribarren/ogami/issues/401).

## Next step

None. Follow-ups are in [#401](https://github.com/iribarren/ogami/issues/401).
