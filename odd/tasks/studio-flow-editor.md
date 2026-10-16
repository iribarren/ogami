# Feature: studio-flow-editor

- **Locator:** `odd/tasks/studio-flow-editor.md` · Engram topic `odd/studio-flow-editor/tasks`
- **Issue:** #151 · **Branch:** `feat/studio-flow-editor-2-commands` · **Status:** in progress (slice 2)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-oracle-editor` (PRs #142–#150)

## Objective

Roadmap feature 18 (milestone M4). In a schema 2 GameSystem draft, a GAME_MANAGER edits the NarrativeFlow model of ADR 0017 as amended by ADR 0018 with a list/outline editor: trackers, tags, fact slots, Scene Types and flows with their phases, hooks, steps, branches and effects. The publisher's rules are the editor's rules. Existing presets (Mythic-style, guided sample) are copied into a draft as starting points, as today (ADR 0028).

Out of scope: editing schema 1 `flow.steps`; a preset picker in the New dialog (copying by source key already works); the library (18b); the sheet and check editors (16, 17: their sections are only read here, as pickers); a node graph (ADR 0004); live authoring warnings beyond what the Validation tab shows.

## Problem and why

- A draft's narrative sections (`trackers`, `tags`, `factSlots`, `sceneTypes`, `flows`) can only come from a copied or restored release; the Sections tab is a placeholder. Guided flows were proven with hand-authored presets (features 9, 9b, 14); Studio now needs to author them.
- Unlike the oracles section, Scene Type and flow steps are validated against a catalog built from every other section (`Catalog`, `ReleaseVersion2::validate`), and flows add flow-wide coherence (`FlowReferences`, per-flow placeholders). A section-local invariant like `OraclesSection` does not carry over.
- The oracle editor kept table entry `sceneType`/`effects` and `chaos.tracker` read-only until the effect and tracker editors exist (ADR 0029).

## Model summary (publisher, schema 2)

Validation lives in `backend/src/Studio/Domain/Release/Version2/` (`ReleaseVersion2` 546, `Steps` 453, `Effects` 262, `Bands` 130, `FlowReferences` 355, `Placeholders` 60, `StepParts` 267, `Catalog`). Contract: `docs/contracts/gamesystem-release.md` §Schema version 2. Order in `ReleaseVersion2::validate`: trackers → oracles → chaos trackers → fact slots → tags → Scene Type shells → sheet → checks → table entries → Scene Type steps → flows → `FlowReferences::check`.

- **Tracker** (≤50): `counter {key, name, hint?, min, max, initial, levels? [{upTo?, label}] ≤20, last without upTo}` or `clock {key, name, hint?, segments 1..20}`.
- **Tag** (≤50) `{key, label, hint?}` · **Fact slot** (≤100) `{key, label, type: text|npc|thread, hint?}`.
- **Scene Type** (≤100) `{key, name, purpose, tips?, oracles (shortcuts), setup, play, closing}` (three step lists).
- **Flow** (≤20) `{key, name, description?, introduction?, default?, defaultView: focus|journal, oracles, trackers, phases 1..20}`; at most one default.
- **Phase** `{key, name, act?, description?, mode: once|loop, selection, sessionOpening?, sessionClosing?, phaseOpening?, phaseClosing?, sceneOpening?, sceneClosing?, worldTurn?}`; selection `sequence{sceneTypes}` | `player{sceneTypes}` | `oracle{table}`.
- **Step list** ≤50, unique keys, `end` reserved, `next` names a later step or `end`. Common `{key, kind, title, prompt?, tip?, mandatory?, next?, effects?}`; a `condition` is never mandatory; sheet effects only in `character` steps and `check` outcomes.
- **Step kinds:** `prompt{inspiration?}`, `oracle{oracle, likelihood?, branches?}`, `table{table, branches?[{entry}], otherwise?}`, `roll{dice, bands?}`, `choice{options, skip?}`, `condition{tracker | count{of, tags?}, bands}`, `pick{of, tags?, mode, count?, branches?{found, none}}`, `character{}`, `check{check, difficulty?, outcomes?}`.
- **Effects** (≤10): `tracker{tracker, op, value}`, `nextScene{sceneType}`, `switchSceneType{sceneType}`, `endPhase`, `sceneTitle{title}`, `createNpc{name?, agenda?, tags?}`, `createThread{name?, tags?}`, `closeThread`, `fillFact{slot, text?}`, `sheet{field, op, value}`. Values: integer, `{tracker}`, `{formula}`, `{dice}` (sheet only).
- **Flow coherence** (`FlowReferences`, per-flow `Placeholders`): oracle-selection table entries have a `sceneType`; trackers reached by a flow are in `flow.trackers`; reachable Scene Types' `oracles` ⊆ `flow.oracles`.

## Decisions

| Question | Decision |
|---|---|
| Save rule (user, 2026-10-16) | **Per-item validation.** Each saved item passes the publisher's own rules and its references exist in the draft, otherwise 422 with a path. Flow-wide coherence (`FlowReferences`, per-flow placeholders) is reported in the Validation tab and blocks only publishing |
| Checks, sheet, formulas (user, 2026-10-16) | **Edited.** A `check` step picks one of the draft's checks, a difficulty (integer, `{tracker}` or `{formula}`) and outcome bands; a `sheet` effect picks a number/track field; a formula is a text input checked by the publisher |
| Oracle extensions (user, 2026-10-16) | **Edited here.** The table entry editor gains a Scene Type picker and the shared effects editor; the likelihood editor gains a counter-tracker picker for `chaos.tracker`. The oracle commands then validate those fields like any other reference instead of preserving them |
| Item invariant (Claude) | Every command leaves the draft valid by the publisher's pipeline **without** the flow coherence checks (`FlowReferences`, per-flow placeholders): the "item rules". One pipeline, split, not duplicated: `ReleaseVersion2` runs the item rules then coherence; the editor runs the item rules. Since the referrers of a changed item are validated too, a change that breaks them (a tracker turned into a clock while a `condition` reads it as a counter, a check band removed while an outcome uses it) is refused with their paths. Publish still runs everything |
| Legacy drafts (Claude) | Drafts only come from valid releases, blanks and section-valid oracle edits, so they satisfy the item rules. If one does not, a command is refused with the path of the item that breaks, never a 500 |
| Rename and delete (Claude, as ADR 0029) | Renaming or deleting a tracker, tag, fact slot or Scene Type whose key the draft references is refused (422) with the places listed. Flow keys are not referenced inside a draft and rename freely. Phase and step keys are internal to the saved flow or Scene Type |
| References (Claude) | `OracleReferences` generalises to a defensive draft reference scan for oracle, table entry, tracker, tag, fact slot and Scene Type keys, with readable paths (`flows[0].phases[1].sceneOpening[2].effects[0]`). Tracker refs: `flow.trackers`, `tracker` effects, `condition.tracker`, `{tracker}` values and difficulties, `chaos.tracker`, placeholders. Scene Type refs: selections, `nextScene`/`switchSceneType`, table entry `sceneType`. Tag refs: `createNpc`/`createThread`/`count`/`pick` tags. Fact slot refs: `fillFact`, placeholders |
| Granularity (Claude) | One command per item, all-or-nothing, with `expectedVersion` (409 on mismatch): save (create or replace with its original key for a rename; position kept, new items appended) and delete, for trackers, tags, fact slots, Scene Types (with their three step lists) and flows (with phases, hooks and steps). Saving a flow with `default: true` clears the flag on the others |
| Schema 1 (Claude) | A v1 draft has no narrative sections: the read model says so and every narrative command is refused (422) |
| Read model (Claude) | `GET /narrative`: `version`, `schemaVersion`, `trackers`, `tags`, `factSlots`, `sceneTypes`, `flows` as stored, each with `usedBy`, plus a `catalog` for pickers: oracle tables with entry keys, likelihood oracles with levels, checks with bands and difficulty range, sheet number/track fields |
| API (Claude) | Under `/api/studio/game-systems/{key}`: `GET /narrative`; `POST /narrative/{section}`, `PUT` and `DELETE /narrative/{section}/{item}` with `section` ∈ `trackers`, `tags`, `fact-slots`, `scene-types`, `flows`. Body `{expectedVersion, item}` (free-form object validated by the publisher, like oracle definitions); DELETE takes `expectedVersion` as a query parameter. Each command returns the refreshed read model. Owner only (404 for others). 404 unknown item, 409 stale version or key taken, 422 invalid (path) or in use (paths) or schema 1. Generic section paths keep the generated client small |
| UI (Claude) | GameSystem page tabs **Trackers & tags** (trackers with levels, tags, fact slots: list, create/edit dialogs, delete disabled with "Used by"), **Scene Types** and **Flows** (lists with counts and "Used by", create, delete; rows open editor routes `/studio/game-systems/$key/scene-types/$sceneType` and `…/flows/$flow`). The Sections tab keeps the counts of sheet and checks. Validation tab unchanged; the Flows tab shows the draft's validation issue count with a link |
| Step editor (Claude) | Outline, no graph: a step list is an ordered list of collapsible step cards (key, kind, title, prompt, tip, mandatory/suggested, `next` picker of later steps or `end`, effects, kind fields and branches/bands/options/outcomes, each with `next` and effects). Add, delete, move up/down; a move that leaves a `next` pointing backward is flagged on the card before saving. Unknown fields round-trip. Server 422 paths map to the card and field |
| Slice 2 shapes (Claude) | Domain `NarrativeSection` enum (values are the document keys `trackers`, `tags`, `factSlots`, `sceneTypes`, `flows`; HTTP slugs map in slice 3) and two generic commands `GameSystem::saveNarrativeItem(section, ?originalKey, item, now)` / `deleteNarrativeItem(section, key, now)`. Errors `NarrativeItemNotFound`, `NarrativeKeyTaken` (key unique within its section) and `NarrativeItemInUse` (paths), alongside the oracle errors, which stay. A schema 1 draft is refused with `InvalidReleaseContent` at `schemaVersion`. An item is stored as given once the new document passes `ReleaseContent::checkItemRules`; a missing section is an empty list. References inside the item itself do not block its rename or delete and are not listed in `usedBy`; for a rename the item rules then check the saved item. `default: true` removes `default` from the other flows |
| Flow editor (Claude) | Flow fields, oracles and trackers multi-selects, then an outline of phases (add, delete, move): act, key, name, description, mode, selection (rule and its Scene Types or table), and the seven hooks plus world turn as collapsible step lists |

## Design

### Backend (slices 1–3)

- `Studio/Domain/Release/Version2`: split `ReleaseVersion2::validate` into the item rules and the coherence checks (one code path; publish runs both).
- `Studio/Domain/GameSystem`: `DraftReferences` (generalised from `OracleReferences`), narrative item commands on `GameSystem` (copy-on-write document), errors (`NarrativeItemNotFound`, `NarrativeKeyTaken`, `NarrativeItemInUse` with paths; or the oracle errors generalised). Oracle commands switch from preserving entry `sceneType`/`effects`/`chaos.tracker` to validating them by the item rules.
- `Studio/Application`: `SaveNarrativeItem`, `DeleteNarrativeItem` (section enum, `expectedVersion`), `GetGameSystemNarrative` read model with `usedBy` and `catalog`; Behat.
- `Studio/Infrastructure/Http`: `GameSystemNarrativeController`; error mapping as `GameSystemOracleController`; API tests; `make api` (generated files in their own commit).

### Frontend (slices 4–9)

- `frontend/src/studio/narrative/`: hooks (`useGameSystemNarrative`, save/delete mutations writing the returned read model into the cache), typed drafts that keep unknown fields, Trackers & tags tab, effects editor and value inputs, step list editor and kind forms, Scene Types tab and editor, Flows tab and flow editor with the phase outline. The oracle editors gain the entry Scene Type and effects fields and the chaos tracker picker.

## Constraints

- ADR 0028: drafts are owner-only (404 otherwise); ADR 0010: Play reads only published releases, nothing here touches Play.
- No node-graph library (ADR 0004); shared UI from `frontend/src/shared/ui`, add a component only when a slice needs it.
- The publisher is the single source of validation rules: extracted, never duplicated in the editor. Client-side checks only guide (e.g. backward `next`).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice (reviewer refuses ~1,850). Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-flow-editor-1-domain` | `feat(studio): studio-flow-editor 1/9 item rules and draft references` | T1, T2 | ~1,400 |
| 2 | `feat/studio-flow-editor-2-commands` | `feat(studio): studio-flow-editor 2/9 narrative item commands and read model` | T3, T4 | ~1,600 |
| 3 | `feat/studio-flow-editor-3-api` | `feat(studio): studio-flow-editor 3/9 narrative API` | T5 | ~1,600 incl. ~800 generated |
| 4 | `feat/studio-flow-editor-4-trackers-tab` | `feat(studio): studio-flow-editor 4/9 trackers, tags and fact slots tab` | T6 | ~1,600 |
| 5 | `feat/studio-flow-editor-5-step-editor` | `feat(studio): studio-flow-editor 5/9 step list and effects editor` | T7 | ~1,700 |
| 6 | `feat/studio-flow-editor-6-step-kinds` | `feat(studio): studio-flow-editor 6/9 branching step kinds` | T8 | ~1,500 |
| 7 | `feat/studio-flow-editor-7-scene-types` | `feat(studio): studio-flow-editor 7/9 Scene Types tab and editor` | T9 | ~1,400 |
| 8 | `feat/studio-flow-editor-8-flows` | `feat(studio): studio-flow-editor 8/9 Flows tab and flow editor` | T10 | ~1,700 |
| 9 | `feat/studio-flow-editor-9-oracle-fields-e2e` | `feat(studio): studio-flow-editor 9/9 oracle entry fields, chaos tracker and e2e` | T11, T12 | ~1,100 |

Shippable: slices 1–3 add backend unused by the UI (the oracle commands accept edited entry fields only through the API); slice 4 makes the Trackers & tags tab usable; slices 5–6 add editors not yet linked (stories only); slice 7 makes Scene Types editable; slice 8 flows; slice 9 completes the oracle fields and adds the e2e.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0030 (Studio flow editor: per-item commands with expected version, item rules vs flow coherence, refuse rename/delete while referenced, generic narrative section API, outline editor without a graph, oracle extensions now edited — amends ADR 0029 "preserved extensions") and ADR index row; glossary (Item rules, Flow coherence) | delegated writer (slice 1) | [x] | 59663cc |
| T2 | 1 | Domain: split `ReleaseVersion2::validate` into item rules and coherence (publish unchanged, regression tests); `DraftReferences` for oracle, entry, tracker, tag, fact slot and Scene Type keys (defensive, readable paths; `OracleReferences` folded in or delegating); unit tests for every reference kind | delegated writer (slice 1) | [x] | a42f2a6, f5a2791 |
| T3 | 2 | Domain: `GameSystem` narrative commands (save/delete tracker, tag, fact slot, Scene Type, flow; rename; position kept; default flow flag; schema 1 refused; item rules after every command; rename/delete refused while referenced); oracle commands validate entry `sceneType`/`effects` and `chaos.tracker` by the item rules; errors; unit tests | delegated writer (slice 2) | [x] | |
| T4 | 2 | Application: `SaveNarrativeItem`/`DeleteNarrativeItem` handlers with `expectedVersion` (409), `GetGameSystemNarrative` read model (`usedBy`, `catalog`); unit tests; Behat | | [ ] | |
| T5 | 3 | HTTP: `GameSystemNarrativeController` (`GET /narrative`, `POST /narrative/{section}`, `PUT`/`DELETE /narrative/{section}/{item}`); every error mapped (400/401/403/404/409/415/422) with API tests incl. another owner's draft, a stale `expectedVersion`, an unknown section, an in-use delete and a schema 1 draft; `make api` (generated files in their own commit) | | [ ] | |
| T6 | 4 | Trackers & tags tab: hooks, typed drafts, trackers (counter with hint and levels, clock), tags, fact slots: list with "Used by", create/edit dialogs, delete disabled with the reason; 409 reload; Vitest; stories | | [ ] | |
| T7 | 5 | Step list editor: step cards (common fields, mandatory/suggested, `next` picker, add/delete/move, backward-`next` flag), effects editor (10 kinds) and value inputs (integer, `{tracker}`, `{formula}`, `{dice}`), kinds `prompt`, `character`, `roll` and `condition` with bands; unknown fields round-trip; 422 path mapping; Vitest; stories | | [ ] | |
| T8 | 6 | Step kinds `oracle` (branches), `table` (entry branches, otherwise), `choice` (options, skip), `pick` (branches), `check` (difficulty, outcomes); Vitest; stories | | [ ] | |
| T9 | 7 | Scene Types tab (list, counts, "Used by", create, delete) and editor route (name, purpose, tips, oracle shortcuts, setup/play/closing step lists, rename, 409/422); Vitest; stories | | [ ] | |
| T10 | 8 | Flows tab (list, default flag, validation issue count, create, delete) and flow editor route (fields, oracles/trackers selects, phase outline: act, mode, selection, hooks, world turn; rename; 409/422); Vitest; stories | | [ ] | |
| T11 | 9 | Oracle editors: table entry Scene Type picker and effects editor; likelihood `chaos.tracker` picker (counters only); Vitest; stories | | [ ] | |
| T12 | 9 | e2e: a GAME_MANAGER copies `night-job`, edits a Scene Type step and a flow phase, publishes; wrap-up: roadmap 18 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-16: Exploration done (schema 2 contract and validators, cross-section references and flow coherence, oracle editor wiring, presets `mythic-style` and `night-job`, GameSystem page tabs). Copying a preset into a draft already works (`GameSystem::createFromRelease`). Three decisions taken with the user (save rule, checks/sheet/formulas edited, oracle extensions edited here). Issue #151; slice plan of 9.
- 2026-10-16: T1 done: ADR 0030 (Studio flow editor, amends ADR 0029 "preserved extensions"), ADR index row, glossary entries Item rules and Flow coherence.
- 2026-10-16: T2 done: `ReleaseContent::checkItemRules()` runs the publisher without flow coherence (`ReleaseVersion2::validate($release, $flowCoherence)`; `FlowReferences::releasePlaceholders()` is an item rule, `FlowReferences::check()` the coherence); `OracleReferences` folded into `DraftReferences` (oracle, entry, tracker, Scene Type, tag, fact slot paths). RED observed for both test classes (method/class missing), GREEN after; publish suite unchanged and green.
- 2026-10-16: Slice 1 review fixes: R3-001 (schema 1 item rules test: valid passes, invalid gives the `fromArray` message) and R3-002 (formulas the parser refuses read nothing in `DraftReferences` and never throw). Tests only: the behaviour already held, so no RED.
- 2026-10-16: T3 done: `NarrativeSection`, errors `NarrativeItemNotFound`/`NarrativeKeyTaken`/`NarrativeItemInUse`, `GameSystem::saveNarrativeItem`/`deleteNarrativeItem` (copy-on-write, item rules before assigning; `DraftReferences::narrativePathsTo` is the shared "outside the item" rule), oracle commands run the item rules too. RED: `NarrativeSection` missing for the new test class; the three new oracle tests fail without the item-rules call. The breaks-a-referrer test turns the chaos tracker into a clock (a `condition` accepts clocks).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-4eb0aa712653dea7` | 59663cc, a42f2a6, f5a2791 | approved, acknowledged; suggestions R3-001 (schema 1 test for `ReleaseContent::checkItemRules`: valid passes, invalid gives the same message as `fromArray`) and R3-002 (prove `Formula::fromString` failure modes, oversized or deeply nested, surface as `InvalidFormula` in `DraftReferences`), fixed in slice 2 | #152 |

## Next step

Slice 2 (slice 1 review fixes, T3, T4) on `feat/studio-flow-editor-2-commands`.
