# Feature: preset-real-system

- **Locator:** `odd/tasks/preset-real-system.md` · Engram topic `odd/preset-real-system/tasks`
- **Issue:** #122 · **Branch:** `feat/preset-real-system-2-play-guidance` · **Status:** in progress (slice 2)
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
| Replan after the playtest (user: "your call", 2026-10-15) | Most findings are Play guidance and flow problems, not preset content. Order: Play guidance UI (slice 2), session and scene state (3), inspiration contract (4), preset rework and playtest 2 (5), then sheet effects (6–7) and the finish (8) |
| Open session (Claude, 2026-10-15) | One open session at a time: the backend refuses to start a session while one is open; the UI offers End session instead (slice 3) |
| Character creation methods (Claude, 2026-10-15) | Explained in text for now (roll 3d6×5 with the dice roller, or spend points). Structured methods (dice defaults, point budgets) go to the backlog |

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

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes. Replanned after the playtest (2026-10-15).

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-real-system-1-preset` | `feat(presets): preset-real-system 1/8 percentile horror` | T1, T2 | ~1,500 (done: +1,065 / −13) |
| 2 | `feat/preset-real-system-2-play-guidance` | `feat(play): preset-real-system 2/8 play guidance` | T3, T4 | ~1,200 |
| 3 | `feat/preset-real-system-3-session-scene-state` | `feat(play): preset-real-system 3/8 session and scene state` | T5, T6 | ~1,200 incl. ~300 generated |
| 4 | `feat/preset-real-system-4-inspiration` | `feat(studio): preset-real-system 4/8 inspiration tables and phase descriptions` | T7, T8 | ~1,500 incl. generated |
| 5 | `feat/preset-real-system-5-preset-rework` | `feat(presets): preset-real-system 5/8 preset rework` | T9 | ~1,000; playtest 2 after it merges |
| 6 | `feat/preset-real-system-6-sheet-effects-contract` | `feat(studio): preset-real-system 6/8 sheet effects contract` | T10, T11 | ~1,300 |
| 7 | `feat/preset-real-system-7-sheet-effects-play` | `feat(play): preset-real-system 7/8 sheet effects in play` | T12, T13 | ~1,650 incl. ~400 generated |
| 8 | `feat/preset-real-system-8-finish` | `feat(presets): preset-real-system 8/8 sheet effects in the preset` | T14, T15, T16 | ~800 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0025 (items, inventory and `shop` move post-M3) and ADR index row; ADR 0018 status note and its two open-question rows; vision open question resolved; ADR 0021 consequence note (open-license presets carry their license notice); roadmap: feature 13 in progress, backlog rows `play-items-economy`, `preset-vaesen`, `preset-pbta`, revised `preset-vtm-chronicle` and `preset-west-marches` (D&D 5e SRD 5.2), with starter prompts | delegated writer (slice 1) | [x] | c4082d2 |
| T2 | 1 | Preset `backend/presets/percentile-horror.json` (sheet, derived, checks, flow, oracles, credits) in original wording; `PresetsTest`; flow unit test; check band edge tests | delegated writer (slice 1) | [x] | 0416263 |
| T3 | 2 | Play guidance layout and controls: flow introduction above "Where play stands"; Start session offered where the flow waits for one; scene and phase controls below the note composer with a "scene done" hint; confirmation before ending a phase | | [ ] | |
| T4 | 2 | Play labels and help: no repeated names in progress lines, journal headings and oracle groups; a one-line help text in each play tool panel (Threads explained); View sheet from the focus panel's cast | | [ ] | |
| T5 | 3 | One open session: backend refuses Start session while one is open (409, API test); the UI shows End session instead | | [ ] | |
| T6 | 3 | Scene state: "Where play stands" follows the FlowRun's scene; manual New scene and Scene Type switch hidden or routed while a Flow guides; the player renames the current scene (endpoint, `make api`) | | [ ] | |
| T7 | 4 | ADR: prompt-step inspiration tables and phase descriptions; contract (`v2.schema.json`, Version2 validator, agreement fixtures); glossary | | [ ] | |
| T8 | 4 | Play: translator, FlowRun view and API carry inspiration tables and phase descriptions; UI rolls inspiration inside the prompt step (the player still writes the fact) and shows the phase description at phase start and in the end-phase confirmation | | [ ] | |
| T9 | 5 | Preset rework: phase "Prologue" / Scene Type "Setup"; player-chosen hook with a hooks suggestion table (letter, party invitation, newspaper clipping, old acquaintance, professor); prompts with inspiration instead of table-to-fact; noun, verb and adjective tables; confrontation place and the horror recorded by the player; the mystery opens a Thread; character creation guidance; flow test updated | | [ ] | |
| T10 | 6 | ADR 0026 sheet effects (amends ADR 0022 and ADR 0017); glossary; contract doc section; decides whether a track can start from a formula (R3-004: Sanity starts at POW) | | [ ] | |
| T11 | 6 | Contract: `sheet` effect and `{dice}` value in `v2.schema.json` and the Version2 validator; agreement fixtures; Play translator still refuses `sheet` effects | | [ ] | |
| T12 | 7 | Play: translator reads `sheet` effects; applying them on a check outcome (clamp, dice, all-or-nothing); journal records sheet changes; API and `make api` | | [ ] | |
| T13 | 7 | UI: check entry and step card show sheet changes; Characters panel refreshes; fixtures; Vitest | | [ ] | |
| T14 | 8 | Preset uses sheet effects (Sanity, HP loss) | | [ ] | |
| T15 | 8 | Playtest 2 fixes; e2e: a short Percentile Horror run | | [ ] | |
| T16 | 8 | Wrap-up: roadmap 13 done, MVP complete; feature doc closed | | [ ] | |

## Progress

- 2026-10-14: Exploration done (release contract, sheet v2, Formula kernel, checks, step kinds, effects, presets and their tests). Licenses checked: D&D SRD 5.2 CC BY 4.0; Chaosium Fan Material Policy excludes apps; BRP ORC fully open; Dark Pack non-commercial; Year Zero SRD under Free League's free license. Three decisions taken with the user. Issue #122.
- 2026-10-15: T1 done (docs only, no RED: structural readback of ADR 0025, the ADR 0018/0021 edits, vision and roadmap links).
- 2026-10-15: T2 done. RED: the two new `PresetsTest` cases failed ("Cannot read the file" percentile-horror.json) and the check and flow tests errored on the missing file. GREEN: 39 `PercentileHorror` tests pass; `make presets` publishes `percentile-horror` with no validator error or warning (the existing night-job warning is unchanged). `make qa` clean; `make test`: PHPUnit 3,824 tests, Behat 70 scenarios, Vitest 701 tests, all green.
- 2026-10-15: Slice 1 merged (PR #123). Playtest 1 by the user; findings mapped to code and the remaining work replanned into slices 2–8 (see Playtest findings).
- 2026-10-15: Slice 1 review fixes R3-001..003 (tests only). RED: R3-001 with a placeholder position failed showing the real one (`['open', null]`), now asserted. R3-002 is a label fix and R3-003 adds coverage of existing behaviour (the preset is unchanged), so neither can fail first. GREEN: 40 `PercentileHorror` tests pass.

## Playtest findings

Playtest 1 (user, 2026-10-15, after slice 1 merged):

| Finding | Decision |
|---|---|
| The flow explanation sits below Start session; a novice needs it first | T3 |
| End scene is far from where notes are written; unclear when to move to the next scene | T3 |
| Ending a phase (investigation) asks no confirmation | T3 (generic), T8 (phase description in the dialog) |
| Repeated names: "The letter › The letter › Open play", "The scene - Scene" | T4 |
| Threads are not explained; unclear when to create one | T4 (panel help), T9 (the mystery opens a Thread) |
| The Character sheet should be easy to look at during play | T4 |
| Start session is offered while a session is open | T5 |
| "Where play stands" keeps the old scene title after a flow-started scene (cause: default titles "<Type> N", kept across Scene Type switches; manual Start scene bypasses the FlowRun) | T6 |
| A scene should carry the player's own name ("The letter" is one possible hook, not a type) | T6 (rename), T9 |
| Scene Types should name a narrative purpose ("Setup"), not a specific hook | T9 |
| Table rolls write facts directly; the player should write facts in their own words, with tables (and noun/verb/adjective tables) as inspiration | T7, T8, T9 |
| The confrontation place is fixed by a Setup roll ("the cellar"); it should be discovered and recorded by the player | T9 |
| When is the horror decided? (today: rolled on `horrors` at the confrontation's start; investigation glimpses record nothing) | T9: recorded by the player at the confrontation, `horrors` as inspiration |
| Character creation needs guidance on how to fill the sheet (rolls or point allocation) | T9 (text); structured creation methods to the backlog |

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-6e6002ef38aa04c3` | c4082d2, 0416263 | Approved and acknowledged. Findings: R3-001 failed-reading branch never asserts the next position (`PercentileHorrorPresetFlowTest.php:139-144`); R3-002 data-provider label "99 fails at skill 99" contradicts its `regular` assertion (`PercentileHorrorChecksTest.php:53`); R3-003 the shock step's plain `failure` outcome (+1 dread) is never exercised. R3-001–003 fixed in slice 2; R3-004 (Sanity starts at 99, not POW) goes to T10 | [#123](https://github.com/iribarren/ogami/pull/123) |

## Next step

Slice 2: writer delivers the slice 1 review fixes, T3 and T4 on `feat/preset-real-system-2-play-guidance`.
