# Feature: play-guided-polish

- **Locator:** `odd/tasks/play-guided-polish.md` · Engram topic `odd/play-guided-polish/tasks`
- **Issue:** #222 · **Branch:** `fix/play-guided-polish-4-random-events` · **Status:** slice 4 in progress (slices 1–3 merged, PRs #223, #224, #225)
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
| 3 | `fix/play-guided-polish-3-flow-outcomes` | F2, T8 | ~1,100–1,400 (flow notes, `flowOutcome` entry, ~10 handlers, `make api`, renderer) |
| 4 | `fix/play-guided-polish-4-random-events` | F3, T9–T10 | ~700–1,000 (P9 + `make api`, Chrome replay, small replay fixes) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; preset-mythic-flow and preset-guided-sample findings rows point here | parent doc, writer commits | [x] | `05bf425` |
| T2 | 1 | N9 FlowRun progress `sceneNumber`, tips and "So far" filter on it; N11 display ordinals; P11 display; P13 headings; N13 form first | delegated writer (slice 1) | [x] | `c2aa2f3`, `3e2f393` |
| T3 | 1 | P12 / N10 "Next:" preview of branching steps and pick labels | delegated writer (slice 1) | [x] | `d7f7cc7` |
| T4 | 1 | P10 backend guard on a manual StartScene while a Flow guides | delegated writer (slice 1) | [x] | `eb4aca0` |
| F1 | 2 | Slice 1 review fixes: check-step preview compares the default next for unmapped results; a paused FlowRun reports no guided `sceneNumber`; the refusal test compares a deep copy | delegated writer (slice 2) | [x] | `76fb2e2` |
| T5 | 2 | Optional `band.label` in schema v2, Studio validator and bands editor, Play translator, contract doc | delegated writer (slice 2) | [x] | `df08441` |
| T6 | 2 | Preset band labels (both presets), N12 Night Job branches and Chase outcomes | delegated writer (slice 2) | [x] | `5b1db37` |
| T7 | 2 | P8 Threads and NPCs quick links in guided play | delegated writer (slice 2) | [x] | `5569377` |
| F2 | 3 | Slice 2 review fixes: a completed FlowRun hides the list links (test); the JSON schema rejects a whitespace-only band label (pattern + agreement case) | delegated writer (slice 3) | [x] | `05b53be` |
| T8 | 3 | P7 / N8 flow notes journaled as `flowOutcome` entries | delegated writer (slice 3) | [x] | `30e20a4` |
| F3 | 4 | Slice 3 review fix: handler-level tests that SkipFlowStep, MoveOn, EndFlowScene, ResumeGuidance, StartSession and PickSceneTypeByOracle write a `flowOutcome` entry when the command notes something | delegated writer (slice 4) | [x] | |
| T9 | 4 | P9 random event tied to its fate question, meaning prompt, offer hidden once rolled | | [ ] | |
| T10 | 4 | Replay a short guided session per preset in Chrome; fix small findings, log the rest | | [ ] | |

## Progress

- 2026-10-19: feature started; 13 findings checked against `main` by a read-only explorer (P10 fixed in the UI, the rest open), decisions taken, issue #222, 4 slices.
- 2026-10-19: T1 done (doc committed, finding rows point here). Route: delegated writer, no tests (docs only).
- 2026-10-19: T2 done. RED: `FlowRunViewTest` failed on the missing `progress.sceneNumber`, then GREEN; legacy row (no stored `sceneNumber`) tested in `DoctrineCampaignRepositoryTest`; `playOrdinals`/`typeLabel` helpers were written before their unit tests (GREEN only); Vitest `src/play` 412 passed. P11: rename field starts empty on a default title (detected with `titleAddsToType`; the API has no default-title flag). Route: delegated writer, direct.
- 2026-10-19: T3 done. RED: `FlowRunNextPreviewTest` (21 cases) failed on the old default-path preview, GREEN after `FlowRun::nextOf` (shared `StepEffects::outcomes`); mythic `twist`/`check` and night-job `double-cross` preview tests; old "Next scene:" assertions updated; backend Play unit + integration 2,482 passed. Check-kind outcomes are covered through the shared helper only. Route: delegated writer, direct.
- 2026-10-19: T4 done. Guard: `Campaign::startScene` refuses with `SceneCannotStartWhileGuided` (409) while the FlowRun is active; FlowRun starts scenes through the new `startGuidedScene`. RED: with the guard in, 10 existing tests that started scenes by hand mid-guidance failed (fixed with pause/resume or `withScenesStartedByHand`, a stored-before state); the new unit and API tests were written after the code (GREEN only). Paused and complete Flows still allow manual scenes. Route: delegated writer, direct.
- 2026-10-19: F1 done. RED: 2 new `FlowRunNextPreviewTest` check cases and the paused `FlowRunViewTest` case failed, GREEN after `nextOf` (adds the default next when the step maps fewer results than its Check has bands) and a null `sceneNumber` in the view while paused; the refusal test now compares `unserialize(serialize())`, GREEN only (test-only change). Route: delegated writer, direct.
- 2026-10-19: T5 done. RED: validator, agreement and translator cases for `band.label` failed (unknown property), GREEN after `Bands::outcomes`, the schema, `Band::$label` and the translator (same 1-80 rule, null rejected on both sides; the old "unknown band property" case now uses `colour`); `stepDraft` round-trip and the Label input tests were written after the code (GREEN only). No endpoint changed, so no `make api`. Route: delegated writer, direct.
- 2026-10-19: T6 done. RED: the `floor`→`ice` branch skip and both `lose-them` outcomes failed in `NightJobPresetFlowTest`, GREEN after the preset edits; `PresetBandLabelsTest` (every roll band labelled) was written after the labels (GREEN only). Decision: the Chase notes are prompt steps (`boxed-in`, `shaken`) the botch and fail outcomes branch to, since effects carry no note; the check step's own `next` is `end` so a success leaves the Chase. The Mythic `lists` tip (T7 text) landed here. Route: delegated writer, direct.
- 2026-10-19: T7 done. Tests written after the code (GREEN only): `ListLinks`, guide and focus mode open a collapsed Threads / NPCs panel and focus its title, none for a campaign played freely or a completed Flow. The release declares no lists (the panels always exist), so "has the lists" means the screen shows the panels: `CampaignPage` provides the opener (context), `GuidedBody` renders the links, which serves the guide and focus mode alike. The opener is a `play-panel` window event (`openPlayPanel`). Route: delegated writer, direct.
- 2026-10-19: F2 done. RED: the new `v2-band-label-blank` agreement case failed without the schema `pattern` (the validator already said "must not be blank"), GREEN with `"pattern": "\\S"`; the completed-FlowRun test already existed as `say the Flow is complete`, which gained a no-Lists-navigation assertion (GREEN only; the hiding code was already in `GuidedParts`). Route: delegated writer, direct.
- 2026-10-19: T8 done. RED: `FlowRunNotesTest` (9 cases) failed on the missing `Campaign::takeFlowNotes()`, GREEN after `FlowNote`, the context hook and the `band()` / `apply()` / switch notes; frontend `flowOutcome` view and Journal caption tests RED then GREEN; `FlowOutcomeContentTest`, `FlowOutcomeEntryHandlerTest`, the repository contract kind, the malformed stored content and the unstored-notes tests were written after the code (GREEN only). Mechanism: notes live in a transient `Campaign::$flowNotes` filled through `FlowRunContext::note()` on the command's draft copy (so a failing command keeps none) and drained once by `CampaignJournal::flowOutcome()` / `FlowOutcomes::take()` before the save (so no note is stored, even in the in-memory repository) and the entry added by `record()` after it; stored FlowRun JSON is unchanged. Entry lands right after the step's own (or sheet-change) entry, else in the scene left current; with no current scene (a session closing that ends the session) the notes are dropped (documented, tested). Handlers journaling it: complete step, skip step, move on, end scene, end session, pick Scene Type (table and oracle), resume, start session, hand switch of the Scene Type (its new setup can settle a condition step); StartScene is refused while guided and a hand tracker edit is no Flow decision, so neither. Direct tests: complete step, pick, hand switch, end session (dropped), failure; the other handlers share `FlowOutcomes`. A band note comes only from `band()` (roll and condition steps); check, table and oracle outcomes already show in their own entries. `CampaignFlowChecksApiTest` now sees the alarm note. Route: delegated writer, direct.
- 2026-10-19: F3 done. Test-only, GREEN only (the handlers already journal): `FlowOutcomeEveryHandlerTest` (6 cases, own release extending `FlowNotesReleases`) asserts the entry's notes, session, scene and no step for skip (lands on a condition step), end scene (closing), move on (phase closing hook scene), start session and resume (auto-picked scene setup) and pick by oracle; removing the `record()` call from `MoveOnHandler` failed its case, so the tests bite. No handler bug found. Route: delegated writer, direct.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-cb716070de79b851` | `05bf425`…`eb4aca0` | Approved, acknowledged. Non-blocking R3: check preview ignores unmapped results; paused FlowRun keeps its guided `sceneNumber`; refusal test compares a shallow clone. Fixed in slice 2 (F1) | #223 |
| 2 | `review-f83003d7caa55060` | `76fb2e2`…`5569377` | Approved, acknowledged. Non-blocking R3: no test for hidden list links on a completed FlowRun; the JSON schema accepts a whitespace-only band label. Fixed in slice 3 (F2) | #224 |
| 3 | `review-bdb938bf8875dc19` | `05b53be`…`30e20a4` | Approved, acknowledged. Non-blocking R3: six note-producing handlers have no handler-level `flowOutcome` test. Fixed in slice 4 (F3) | #225 |

## Next step

Slice 4 (last): F3, T9 on `fix/play-guided-polish-4-random-events`, then T10 Chrome replay by the parent; PR closes #222.
