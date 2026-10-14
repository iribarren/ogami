# Feature: preset-real-system

- **Locator:** `odd/tasks/preset-real-system.md` · Engram topic `odd/preset-real-system/tasks`
- **Issue:** #122 · **Branch:** `feat/preset-real-system-1-preset` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-checks` (PRs #115–#121)

## Objective

Roadmap feature 13 (milestone M3, completes the MVP). Hand-author a release for a small, license-safe real game system (sheet, derived values, checks, flow), play a full session with it, and fix the schema gaps it reveals. Settle the open question on items, inventory and a `shop` step kind ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md):100, [vision](../../docs/vision.md):81).

Out of scope: items, inventory, currency and a `shop` step kind (moved to the post-M3 feature `play-items-economy`); dice pools and success counting (ADR 0024 known gaps; backlog presets); Studio editors (M4).

## Problem and why

- The only real-system preset, Night Job, is a fan one-shot built while the contract grew; nothing has been authored against the finished M3 contract (sheet v2, derived values, checks).
- Nothing in a release can change a Character's sheet: effects touch trackers, scenes, NPCs, Threads and facts only ([ADR 0022](../../docs/adr/0022-character-sheets-in-schema-version-2.md):33, contract "The sheet has no effects"). Every real system loses HP, Sanity or supply in play.
- Sheet `list` fields are flat strings formulas cannot read; there is no currency or `shop` step. The open question must be settled for the MVP.

## Decisions

| Question | Decision |
|---|---|
| System (user, 2026-10-14) | A Call of Cthulhu 7e-style game: 1920s Lovecraftian investigation, d100 roll-under skills, regular/hard/extreme successes, critical and fumble, Sanity, Luck, Magic Points |
| License framing (user, 2026-10-14) | **Open base:** built on *Basic Roleplaying: Universal Game Engine* (Chaosium, ORC License), 7e-style mechanics in original wording, public-domain Lovecraft names (e.g. Kingsport, 1921–1925 stories). No Chaosium trademarks and no "Call of Cthulhu" name: Chaosium's Fan Material Policy excludes "software, apps, and virtual tabletops". Credits carry the ORC notice |
| Items, inventory, `shop` (user, 2026-10-14, with the system choice) | **Moved post-M3** to backlog feature `play-items-economy`. Investigators' gear is narrative: a flat `gear` list is enough. Recorded in ADR 0025 |
| Backlog presets (user, 2026-10-14) | Starter prompts for D&D 5e (SRD 5.2, CC BY 4.0; revises `preset-west-marches`), Vampire: the Masquerade (revises `preset-vtm-chronicle`: dice pools), Vaesen (Year Zero SRD, Free League's free license: d6 pools, push), and a PbtA preset (Thirsty Sword Lesbians / Bluebeard's Bride / Ironsworn), each naming the gaps it closes |
| Preset identity (Claude) | GameSystem `percentile-horror` "Percentile Horror"; one flow `kingsport-letter` "The Kingsport Letter" (one-shot) |
| Check bands (Claude) | `total = 1d100` (`d%`), no modifier. Bands in order: `critical` upTo 1, `extreme` upTo `{x} / 5`, `hard` upTo `{x} / 2`, `regular` upTo `{x}`, `failure` upTo `95 + 4 * min(1, {x} / 50)` (fumble on 96+ below 50, on 100 from 50), `fumble` catches the rest. No conditional needed. Required level per step is mapped by its `outcomes` (a Hard step sends `regular` to the failure branch) |
| Order of work (Claude) | Preset first with what fits today (slice 1), then the playtest, then the gap fixes. Sheet effects are known before the playtest; they get their own two slices |

## Design

### Preset (slice 1)

- `gameSystem`: key `percentile-horror`, name, description, credits `{notice, license: "ORC License", url: "https://www.chaosium.com/orclicense"}`. The notice: rules adapted from *Basic Roleplaying: Universal Game Engine* © Chaosium Inc., licensed under the ORC License (Library of Congress registration and online locations, all warranties disclaimed); not published, endorsed or approved by Chaosium Inc.; original text otherwise. No logos.
- Theme: `eldritch` (existing; no new theme).
- Sheet (groups): Investigator (`occupation` text, `age` number, `backstory` multiline text); Characteristics (`str con siz dex app int pow edu`, numbers 1–99, default 50); Derived (`hp-max = ({con} + {siz}) / 10`, `mp-max = {pow} / 5`, `starting-sanity = {pow}`); Tracks (`hp` and `mp` per-Character max, `sanity` fixed max 99, `luck` fixed max 99); Skills (~16 numbers 0–99 with 7e-style base defaults: accounting, charm, climb, cthulhu-mythos, dodge, fast-talk, fighting-brawl, firearms-handgun, first-aid, history, library-use, listen, occult, persuade, psychology, spot-hidden, stealth); `gear` list.
- Checks (~22): one per skill, plus `str con dex pow` characteristic rolls, `luck` and `sanity` (vs track current). Bands as decided above; original labels and hints.
- Flow `kingsport-letter`: phases `the-letter:once` (a `character` step; a letter from a relative in Kingsport; fact slots), `investigation:loop` (Scene Types: archive, interview, site, night; clue and event tables; `check` steps for Library Use, Spot Hidden, Persuade/Psychology; a Sanity check step on a horror reveal with "lose 1/1d6 Sanity: adjust the sheet" until sheet effects land; trackers `clues` counter and `dread` clock; a `condition` step ends the loop at enough clues or full dread), `confrontation:once` (checks and a Sanity check), `epilogue:once`.
- Oracles: a likelihood oracle (as Mythic-style), tables for 1920s names, locations, clues, strange events, the horror; original wording only.
- Tests: `PresetsTest` covers the new preset (publish, credits, sheet, checks); a flow unit test like `NightJobPresetFlowTest` walks the whole flow; check band tests at the edges (01 critical, `x/5`, `x/2`, `x`, fumble at 96 for skill 49 and at 100 for skill 50).

### Sheet effects (slices 2–3, draft; final rules in ADR 0026)

- Effect `{kind: "sheet", field, op: "add" | "subtract" | "set", value: int | {formula} | {dice}}`, schema version 2 additive. Allowed in a `check` step's `outcomes[].effects`; the target is the checked Character. Field must be a `number` or `track` (a track changes its current value). Results clamp to the field's bounds.
- `{dice}` is a new effect value form: rolled with the injected RNG when applied; the roll is logged. Formula scope: the Character's sheet plus trackers.
- The check's journal entry records each sheet change (field, before, after, dice rolled). All-or-nothing with the step: an evaluation failure is a 422 and nothing is rolled or saved.
- Open for slice 2: effects in other step kinds (e.g. after a `character` step), and whether a track's starting value can come from a formula (Sanity starts at POW, not full).

### Playtest (after slice 1 merges)

A full session of "The Kingsport Letter": the user plays (Claude can drive a browser run alongside). Every gap found goes to **Playtest findings** with a decision: fix in slice 4, or backlog.

## Constraints

- Schema version 2 extended additively; JSON Schema and Version2 validator agree; existing hashes unchanged. Play reads releases only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)): original wording; no Chaosium book text, trademarks or logos; ORC notice in credits.
- `make api` after response changes; commit `openapi.json` and `schema.d.ts`.
- Every slice safe on `main` alone: slice 1 is a preset and docs; slice 2 validates content no preset uses (Play's translator refuses `sheet` effects until slice 3).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-real-system-1-preset` | `feat(presets): preset-real-system 1/4 percentile horror` | T1, T2 | ~1,500 (preset JSON ~750) |
| 2 | `feat/preset-real-system-2-sheet-effects-contract` | `feat(studio): preset-real-system 2/4 sheet effects contract` | T3, T4 | ~1,300 |
| 3 | `feat/preset-real-system-3-sheet-effects-play` | `feat(play): preset-real-system 3/4 sheet effects in play` | T5, T6 | ~1,650 incl. ~400 generated |
| 4 | `feat/preset-real-system-4-playtest` | `feat(presets): preset-real-system 4/4 playtest fixes` | T7, T8, T9 | ~1,200 (depends on playtest findings; split if it grows) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0025 (items, inventory and `shop` move post-M3) and ADR index row; ADR 0018 status note and its two open-question rows; vision open question resolved; ADR 0021 consequence note (open-license presets carry their license notice); roadmap: feature 13 in progress, backlog rows `play-items-economy`, `preset-vaesen`, `preset-pbta`, revised `preset-vtm-chronicle` and `preset-west-marches` (D&D 5e SRD 5.2), with starter prompts | delegated writer (slice 1) | [x] | |
| T2 | 1 | Preset `backend/presets/percentile-horror.json` (sheet, derived, checks, flow, oracles, credits) in original wording; `PresetsTest`; flow unit test; check band edge tests | delegated writer (slice 1) | [ ] | |
| T3 | 2 | ADR 0026 sheet effects (amends ADR 0022 and ADR 0017); glossary; contract doc section | | [ ] | |
| T4 | 2 | Contract: `sheet` effect and `{dice}` value in `v2.schema.json` and the Version2 validator; agreement fixtures; Play translator still refuses `sheet` effects | | [ ] | |
| T5 | 3 | Play: translator reads `sheet` effects; applying them on a check outcome (clamp, dice, all-or-nothing); journal records sheet changes; API and `make api` | | [ ] | |
| T6 | 3 | UI: check entry and step card show sheet changes; Characters panel refreshes; fixtures; Vitest | | [ ] | |
| T7 | 4 | Preset uses sheet effects (Sanity, HP loss) | | [ ] | |
| T8 | 4 | Playtest fixes decided under Playtest findings; e2e: a short Percentile Horror run | | [ ] | |
| T9 | 4 | Wrap-up: roadmap 13 done, MVP complete; feature doc closed | | [ ] | |

## Progress

- 2026-10-14: Exploration done (release contract, sheet v2, Formula kernel, checks, step kinds, effects, presets and their tests). Licenses checked: D&D SRD 5.2 CC BY 4.0; Chaosium Fan Material Policy excludes apps; BRP ORC fully open; Dark Pack non-commercial; Year Zero SRD under Free League's free license. Three decisions taken with the user. Issue #122.
- 2026-10-15: T1 done (docs only, no RED: structural readback of ADR 0025, the ADR 0018/0021 edits, vision and roadmap links).

## Playtest findings

None yet (playtest after slice 1).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer delivers T1 and T2 on `feat/preset-real-system-1-preset`.
