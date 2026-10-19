# Feature: play-guided-polish

- **Locator:** `odd/tasks/play-guided-polish.md` · Engram topic `odd/play-guided-polish/tasks`
- **Issue:** #222 · **Branch:** `fix/play-guided-polish-1-guide` · **Status:** slice 1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `e2e-shared-sign-in` (PR #220)

## Objective

Fix the open playtest findings of `preset-mythic-flow` ([P7–P13](preset-mythic-flow.md#playtest-findings)) and `preset-guided-sample` ([N8–N13](preset-guided-sample.md#playtest-findings)), roadmap backlog B4, so the next preset playtests find new gaps instead of the same ones. Replay a short guided session in Chrome to confirm.

Out of scope: new step kinds; meaning tables or procedures for random events ([ADR 0019](../../docs/adr/0019-random-events-from-fate-question-doubles.md) keeps them deferred); per-session default scene titles.

## Problem and why

Guided play hides what the Flow decided: roll outcomes and tracker effects leave no journal line, the "Next:" preview names a branch before the dice roll, and the guide keeps the last scene's tips after End scene. Scene numbers count world turns and leave gaps. Each preset playtest has logged the same gaps again.

## Findings checked against `main` (2026-10-19)

| ID | Status on `main` | Evidence | Kind | Decision | Slice |
|---|---|---|---|---|---|
| P7 | Open | `FlowRun::band()` picks a band and records nothing; `band` has no label (`v2.schema.json` `band`, `Studio/Domain/Release/Version2/Bands.php`) | Contract (additive) + Play | Optional `band.label` in schema v2; Play journals the label of the band taken | 2 (contract), 3 (journal) |
| P8 | Open | `mythic-style` `lists` is a bare prompt | Play | Threads and NPCs quick links in the guide and focus mode, opening the panels; preset tip names them | 2 |
| P9 | Open | `RandomEventOffer.tsx` records a plain oracle-table entry at the journal end; "Rolled" is not stored | Play | Optional `forEntry` on the oracle-table record request and content; the event shows under its question with a "What does it mean?" note prompt; the offer hides once an event references it | 4 |
| P10 | **Fixed in the UI** (`SessionControls.tsx`, `GuidedParts.tsx`) | `Campaign::startScene` has no guard | Play | Backend guard: refuse a manual StartScene while a Flow guides | 1 |
| P11 | Partly fixed (`titleAddsToType` hides "Scene 8" in headings) | `Campaign::defaultSceneTitle` counts across the campaign; rename prefills it | Play | Keep the campaign-wide default title; no redundant "· Scene" type label; rename does not prefill a default title | 1 |
| P12 | Open | `FlowRun::nextLabel` previews `target(…, null)`, the default path | Play | Resolve every outcome's target of a branching step: one common target is named, otherwise "Next: decided by the roll" / "…by your answer" | 1 |
| P13 | Open | `Journal.tsx` scene h4 is `uppercase` | Play | Normal case, clamp to two lines, full title as tooltip | 1 |
| N8 | Open | `FlowRun::finish` / `apply`, `FlowRunContext::applyTrackerEffect` apply tracker, switch, next scene and phase-end effects silently | Play | FlowRun collects flow notes per command; one journal entry kind `flowOutcome` (band label, tracker from→to, Scene Type switch, forced next scene, phase end) | 3 |
| N9 | Open | Tips and "So far" follow `campaign.currentSceneNumber`, the last scene even after End scene | Play | Nullable `sceneNumber` on the FlowRun progress; tips and "So far" show only while the FlowRun is in that scene | 1 |
| N10 | Open | Same root as P12; `pickLabel` returns "Next scene: …" and the UI adds "Next: " | Play | P12 fix; pick labels "Scene: Briefing" / "Scene: choose a Scene Type" | 1 |
| N11 | Open | Hook scenes take the next per-session number; journal hook headings omit it, so play scenes skip numbers | Play | Display ordinals: only play scenes are numbered, per session; stored numbers unchanged | 1 |
| N12 | Open, preset only | `night-job` `floor` has no branches; `lose-them` has no outcomes | Preset | `black-ice` entry key, `floor` branches to `ice` only on it; `lose-them`: botch → `objective` set 0 + "They box you in. You get away, but the score stays behind."; fail → `edge` −1 + "Shaking them costs you. What do you leave behind?" | 2 |
| N13 | Open | `PlayHomePage.tsx` lists campaigns before the form; "Loading the GameSystems…" | Play | Form first; "Loading the game systems…" | 1 |

Table steps already branch per rolled entry, so N12 needs no condition kind (the roadmap's "conditions on table results").

## Decisions (2026-10-19, parent, delegated by the user: "decide per finding")

| Question | Decision |
|---|---|
| P7 label source | Additive optional `band.label` (1–80 chars) in schema version 2, validated by Studio and the Play translator; Studio's bands editor gets a label input. A Play-only "→ next step" line was rejected: vague, and nothing to show for `next: end` |
| P8 | Play-only quick links, no contract field |
| P9 | Tie to the question and a free-text meaning prompt; no `randomEvent.meaning` tables |
| P11 / N11 | Display only; stored scene numbers and default titles unchanged |
| N12 Chase loss | botch loses the score, fail costs an edge (above) |

## Constraints

- Contract changes additive in schema version 2; releases stored before stay valid.
- Each slice safe on `main` alone. Band labels have no Play effect until slice 3.
- `make presets` publishes changed presets as new releases; existing campaigns keep their release until upgraded.
- Planning heuristic only: forecasts are not caps; the slice ceiling is ~1,700 changed lines including generated files.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `fix/play-guided-polish-1-guide` | T1–T4 | ~1,300–1,600 (doc ~150, N9 progress field + `make api` ~450, display fixes ~350, Next preview ~350, guard ~150) |
| 2 | `fix/play-guided-polish-2-band-labels` | T5–T7 | ~900–1,200 (contract ~400, Studio input ~200, presets + tests ~300, links ~150) |
| 3 | `fix/play-guided-polish-3-flow-outcomes` | T8 | ~1,100–1,400 (flow notes, `flowOutcome` entry, ~10 handlers, `make api`, renderer) |
| 4 | `fix/play-guided-polish-4-random-events` | T9–T10 | ~700–1,000 (P9 + `make api`, Chrome replay, small replay fixes) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; preset-mythic-flow and preset-guided-sample findings rows point here | parent doc, writer commits | [x] | |
| T2 | 1 | N9 FlowRun progress `sceneNumber`, tips and "So far" filter on it; N11 display ordinals; P11 display; P13 headings; N13 form first | delegated writer (slice 1) | [ ] | |
| T3 | 1 | P12 / N10 "Next:" preview of branching steps and pick labels | delegated writer (slice 1) | [ ] | |
| T4 | 1 | P10 backend guard on a manual StartScene while a Flow guides | delegated writer (slice 1) | [ ] | |
| T5 | 2 | Optional `band.label` in schema v2, Studio validator and bands editor, Play translator, contract doc | | [ ] | |
| T6 | 2 | Preset band labels (both presets), N12 Night Job branches and Chase outcomes | | [ ] | |
| T7 | 2 | P8 Threads and NPCs quick links in guided play | | [ ] | |
| T8 | 3 | P7 / N8 flow notes journaled as `flowOutcome` entries | | [ ] | |
| T9 | 4 | P9 random event tied to its fate question, meaning prompt, offer hidden once rolled | | [ ] | |
| T10 | 4 | Replay a short guided session per preset in Chrome; fix small findings, log the rest | | [ ] | |

## Progress

- 2026-10-19: feature started; 13 findings checked against `main` by a read-only explorer (P10 fixed in the UI, the rest open), decisions taken, issue #222, 4 slices.
- 2026-10-19: T1 done (doc committed, finding rows point here). Route: delegated writer, no tests (docs only).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T1–T4 on `fix/play-guided-polish-1-guide`.
