# Feature: manual-voice

- **Locator:** `odd/tasks/manual-voice.md` · Engram topic `odd/manual-voice/tasks`
- **Issue:** [#399](https://github.com/iribarren/ogami/issues/399) · **Branch:** `docs/manual-voice-1-pilot` · **Status:** in progress
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
| T2 | Pilot: The Promise rewritten in English at dial 0 (in place), a Spanish version and one Long Carry played example at dial 2 in `docs/style/samples/`, drafted by a worker following `doc-writer.md` and reviewed by one following `doc-editor.md` | Delegated (writer trigger; the pilot exercises the agents) | done | |
| T3 | Calibration fixes from the user: clarity of idioms and of the overloaded word "promise" in The Promise (EN and ES); ES uses *el santo* and the title *La gran Carga* (Burden becomes *Carga*, Load another word); the guide gains rules for literal clarity, one meaning per word near a term, and titles translated by meaning; "Most results are quiet" fixed in both preset tips and `flow-the-long-carry.md` (quiet is 3 of 9, the most common single result) | Delegated (`doc-writer` agent, then `doc-editor`) | done | |
| T4 | `scripts/verify-changed.sh` skips every `*.md`, so manual chapter edits never run the manual Vitest suites: run them for `frontend/src/**/*.md`, with a `make verify-test` case | Delegated (late finding of this slice) | todo | |

## Applicable checks

- `make verify` once per slice (manual content changes run the manual Vitest suites).
- T1, T2: structural readback. T2: the editor's notes are resolved, and every fact in The Promise still matches the Hard Country and Dogwatch presets.
- No runnable RED: the work is prose and agent instructions.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|

## Progress

- T1 done: style guide `docs/style/manual-voice.md` (house voice, 10 tells with before/after, EN and ES rules, ES terminology, dial, facts and `:::in-ogami` fences), voice-pack format and the draft Hard Country pack (all ES terms proposed, saint's gender and flow title open), `doc-writer` and `doc-editor` agents, roadmap B27; no runnable RED, structural readback and `make verify DRY_RUN=1`.
- T2 done: The Promise rewritten in EN at dial 0 in place, the ES Promise and a Long Carry dial-2 sample (EN and ES) in `docs/style/samples/`, drafted with doc-writer and revised from one doc-editor review; the pilot's gaps closed in the guide (dial-0 played examples, fidelity beyond numbers, ES reserved-word collisions, preset text in ES, quiet-result drift in its samples), the pack README (provisional fill for forced grammar choices) and both agents (stack-down test command, editor read-only `Bash`, multi-file report, minor note defined). Provisional ES choices (*la santa*, *El largo acarreo*, the pack's proposed terms, *El arco*) await the user. Checks: `vitest run src/manual` 10 files, 277 passed; `make verify` plans nothing, because `scripts/verify-changed.sh` skips every `*.md`, manual chapters included.
- T3 done: The Promise EN and ES clarified (figures that failed a literal reading, "promise" and "table" kept to their term senses; ES drops *mesa*, *carga* and *cargar* in other senses), ES samples and pack use *el santo*, *La gran Carga* and *Carga* for Burden (confirmed by the user), Load proposed as *Hatillo* with *huecos*; the guide gains the tells "Figures that fail a literal reading" and "One word in two senses" and a rule for Spanish titles; the quiet-result tip fixed in both presets and `flow-the-long-carry.md` (one roll in three, the most common result), published as dogwatch v3 and hard-country v4. Checks: `vitest run src/manual` 10 files, 277 passed; `make verify` passed (backend-qa, backend-test, api-check).

## Next step

T4: verify-changed.sh runs manual tests for .md changes; then the slice review and PR.
