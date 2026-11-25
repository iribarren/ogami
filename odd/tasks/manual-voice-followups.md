# Feature: manual-voice-followups

- **Locator:** `odd/tasks/manual-voice-followups.md` · Engram topic `odd/manual-voice-followups/tasks`
- **Issue:** [#401](https://github.com/iribarren/ogami/issues/401) · **Branch:** `docs/manual-voice-followups-1-packs` · **Status:** done
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** the closing of [`manual-voice`](manual-voice.md) (#399, PR #400)

## Objective

Settle the open items `manual-voice` left: confirm the Hard Country Spanish lexicon, give Dogwatch a voice pack, and decide where the Spanish article rule for Tracker names lives. The rewrite of the rest of the manual becomes a feature of its own.

## Problem and why

The Hard Country pack is still `draft`, so every Spanish chapter on it would ship flagged terms. Dogwatch has no pack at all, so the Spanish sample of The Promise uses plain Spanish for Cold Berth's words and flags them as gaps. Both block a Spanish manual, and the rewrite of the manual needs both packs before it starts.

## Decisions

| Topic | Decision | Source |
|---|---|---|
| Hard Country lexicon | Every proposed Spanish term is confirmed as written. Load is *Hatillo* and its slots are *huecos*; *bultos* is dropped. The pack moves to `confirmed` | User, 2026-11-25 |
| Tracker article rule | Stays per pack: each pack whose Tracker names take the article restates it. Not added to `docs/style/manual-voice.md` | User, 2026-11-25 |
| Dogwatch pack | `doc-writer` drafts the full pack with every Spanish term `proposed` and `status: draft`; the user confirms the lexicon later | User, 2026-11-25 |
| Manual rewrite | A separate feature with its own issue and roadmap line, not part of #401 | User, 2026-11-25 |

## Constraints

- No product code changes. Preset text changes only to fix a finding, and the preset is then published with `make presets`.
- The Dogwatch pack follows `docs/voices/README.md` exactly (front matter, fixed headings in order) and draws facts only from `backend/presets/dogwatch.json`, `rules-dogwatch.md`, `flow-cold-berth.md` and The Promise.
- A pack evokes its genre and never copies the text, names or slang of an existing game.

## Slice plan

Forecasts include docs. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `docs/manual-voice-followups-1-packs` | `docs(manual): manual-voice-followups 1/1 voice packs` | T1–T4 | ~300 |

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Hard Country pack confirmed: every Spanish term `confirmed`, Load = *Hatillo* / *huecos*, `status: confirmed`, the open question closed; the ES sample's provisional note updated | Inline (one mechanical file plus a comment) | done | `938b151c` |
| T2 | Dogwatch voice pack `docs/voices/dogwatch.md`, drafted by `doc-writer` with every ES term `proposed`, restating the Tracker article rule; the ES sample's Cold Berth note points at it | Delegated (`doc-writer`) | done | `72ef45c7` |
| T3 | New issue and roadmap backlog line for the manual rewrite; B28 records what this feature settled | Inline | done | `638f2b5f` |
| T4 | Dogwatch preset tips that leaked Hard Country words (doc-writer finding): Repair's check tip says "A bearer", its scene tip "costs breath"; fixed in the preset and in the tip quoted by `scene-types.md`, published | Inline (two strings in one file, one quote) | done | `e8a6eb5d` |

## Applicable checks

- Structural readback of both packs against the README format.
- Every fact in the Dogwatch pack's samples matches the preset and its chapters.
- `make verify` plans nothing for `docs/**/*.md`; no runnable RED, the work is prose.

## Reviews

| Slice | Lineage | Commits | Outcome | PR | Findings |
|---|---|---|---|---|---|
| 1 | none | `938b151c`, `638f2b5f`, `72ef45c7`, `e8a6eb5d` | Review skipped by the user (medium risk, from the preset tip change) | [#403](https://github.com/iribarren/ogami/pull/403) | None from a native review. Open decisions (the Dogwatch Spanish lexicon, the ES title for The Arc) are in [#402](https://github.com/iribarren/ogami/issues/402) |

## Progress

- T1 done: every Hard Country Spanish term `confirmed` (Load *Hatillo* / *huecos*, *bultos* dropped), pack `status: confirmed`, its open question removed; the ES sample of The Promise keeps only *El arco* as provisional. Check: structural readback, `grep -c proposed` on the pack is 0.
- T3 done: issue [#402](https://github.com/iribarren/ogami/issues/402) `manual-voice-rewrite` opened for the rewrite of the manual (`type:docs`); roadmap B28 records what this feature settled and B29 links #402.
- T2 done: `docs/voices/dogwatch.md` (draft, 153 lines) drafted by `doc-writer`. The narrator is a watch officer who was once woken out of turn. Every ES term is `proposed`, Cold Berth is *Despertar en frío*, Ship trouble is *Incidencias de a bordo*, and the Tracker article rule is restated. The parent's fact check corrected the dial-1 EN sample, where the `air` entry adds Threat but does not force Repair. The ES sample note of The Promise now points at the pack. Check: structural readback against the README format, quoted entries match `dogwatch.json` word for word.
- T4 done: Repair tips in `dogwatch.json` say "A crew member" and "costs Nerve" (the wrong band takes one Nerve), `scene-types.md` quotes the new tip, published as dogwatch v4.
- Closed: `make verify` passed (PHPStan no errors, PHPUnit 8733 OK, Behat 187/187, manual Vitest 235/235); PR [#403](https://github.com/iribarren/ogami/pull/403); native review skipped by the user.

## Next step

None. The Dogwatch Spanish lexicon and the ES title for The Arc are tracked in [#402](https://github.com/iribarren/ogami/issues/402).
