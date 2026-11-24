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
| Spanish pilot location | The app's manual has no Spanish edition yet, so the Spanish chapter and the dial-2 example are calibration samples in `docs/style/samples/`, not manual content | Parent |

## Constraints

- No product code changes. The English rewrite of The Promise replaces `frontend/src/manual/content/the-promise.md` and must keep its edition fences (`::: full` / `::: paper`), headings that tests or links rely on, and every factual claim about the flows.
- Glossary terms from `docs/domain/glossary.md` stay exactly as written (`NarrativeFlow`, Tracker, Fact, oracle table…).
- Voice packs evoke a genre; they never copy the text, names or invented slang of a specific existing game.

## Slice plan

Forecasts include docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `docs/manual-voice-1-pilot` | `docs(manual): manual-voice 1/1 doc-writer agent and pilot chapter` | T1, T2 | ~1,000 |

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Voice system: `docs/style/manual-voice.md` (style guide with the AI-tell list, before/after pairs, EN and ES rules, the dial), `docs/voices/README.md` (voice-pack format), `docs/voices/hard-country.md`, `.claude/agents/doc-writer.md`, `.claude/agents/doc-editor.md`, roadmap backlog line | Delegated (writer trigger: 5 non-trivial files) | done | |
| T2 | Pilot: The Promise rewritten in English at dial 0 (in place), a Spanish version and one Long Carry played example at dial 2 in `docs/style/samples/`, drafted by a worker following `doc-writer.md` and reviewed by one following `doc-editor.md` | Delegated (writer trigger; the pilot exercises the agents) | todo | |

## Applicable checks

- `make verify` once per slice (manual content changes run the manual Vitest suites).
- T1, T2: structural readback. T2: the editor's notes are resolved, and every fact in The Promise still matches the Hard Country and Dogwatch presets.
- No runnable RED: the work is prose and agent instructions.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|

## Progress

- T1 done: style guide `docs/style/manual-voice.md` (house voice, 10 tells with before/after, EN and ES rules, ES terminology, dial, facts and `:::in-ogami` fences), voice-pack format and the draft Hard Country pack (all ES terms proposed, saint's gender and flow title open), `doc-writer` and `doc-editor` agents, roadmap B27; no runnable RED, structural readback and `make verify DRY_RUN=1`.

## Next step

T2: pilot chapter drafted with doc-writer and reviewed with doc-editor.
