# Feature: preset-mythic-flow

- **Locator:** `odd/tasks/preset-mythic-flow.md` · Engram topic `odd/preset-mythic-flow/tasks`
- **Issue:** #88 · **Branch:** `fix/preset-mythic-flow-3-playtest` · **Status:** slice 3 in progress → review
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `design-foundation` (PRs #84–#87)

## Objective

Complete the Mythic-style preset release (roadmap feature 9, milestone M2) on the NarrativeFlow model of [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) as amended by [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md), following example 3 of [Flow examples](../../docs/domain/flow-examples.md#3-mythic-style-session). Settle whether fate-question doubles trigger random events (vision open question). Validate by playing a real session and log UX findings as follow-up tasks. This is half of the M2 exit signal ("a full Mythic-style session end to end").

## Problem and why

`backend/presets/mythic-style.json` predates features 8 and 8b. It has no tags, no `pick`, no `createNpc` / `createThread` / `closeThread`, and its random event focus table branches only two entries. The example fixture `backend/tests/Fixtures/Studio/releases/examples/mythic-session.json` has the full flow, but with short 8-entry tables. The fate question has no doubles rule, so random events only come from interrupted scenes.

## Existing data (explored 2026-10-12)

| Data | State | Plan |
|---|---|---|
| `play_campaign` | 31 rows, almost all e2e leftovers (`The lost mine …`, `Guided …`, `Lists …`), 2 hand-made test campaigns | Delete all (user: personal tests, no need to keep them playable) |
| `play_journal_entry` | 36 rows | Delete all |
| `studio_gamesystem_release` | `example-journal` v1, `free-journal` v1 (schema 1), `mythic-style` v1 (schema 1), `mythic-style` v2 (schema 2) | Delete both `mythic-style` rows, so the new preset publishes as **`mythic-style` v1**. Keep the others |

The reset is a dev-database operation, not a migration: run it before the slice 2 e2e run and again after slice 2 merges, before the playtest (see Dev data reset). No code keeps the old Mythic campaigns or releases playable, and no new code needs legacy-row handling for them.

## Scope

- **In:**
  - Random events from fate-question doubles: an optional, declarative `randomEvent` rule on a likelihood oracle (schema version 2, additive), the answer flag, the journal entry and the Play UI that offers the event table.
  - The Mythic-style preset as `mythic-style` v1: example 3 in full (tags, picks, NPC and Thread effects, focus table branching per entry, meaning tables for the other entries), richer original tables, the doubles rule, a theme.
  - Dev data reset; e2e and preset tests updated.
  - ADR 0019 for the doubles decision; vision, glossary, contract doc, flow example 3 and roadmap updates.
  - A real session played in the browser by Claude; UX findings logged as follow-up tasks, the small blocking ones fixed in slice 3.
- **Out:**
  - Running the flow's random-event steps when a double comes up (reusable procedures stay deferred, vision open question).
  - Changing the free-journal preset or the example fixtures beyond what the schema change needs.
  - Fixing non-blocking UX findings (they become backlog tasks).

## Decisions (2026-10-12, user)

| Question | Decision |
|---|---|
| Fate-question doubles | **Yes, as a declarative rule.** A likelihood oracle may declare `randomEvent: {table}`. A roll that is a double (11, 22 … 99) whose digit is at most the chaos factor is a random event. The answer is flagged, and the journal entry (and the oracle step card) offers a one-click roll of the declared table. No flow procedure runs |
| Who plays the validation session | Claude, in Chrome, after slice 2 merges; the user reviews the findings |
| Old campaigns and releases | Deleted; the preset ships as `mythic-style` v1 |

## Design

### Random event rule

- Release (schema version 2, additive): a likelihood oracle may carry `"randomEvent": {"table": "<oracle table key>"}`. Validation: the table exists in the release; the oracle has `chaos`; `sides` is 100 (doubles need two digits). Unknown fields fail closed.
- Randomness domain: `LikelihoodOracle` parses the rule (framework-free); `LikelihoodAnswer` gains `randomEvent(): ?string`, the table key when the roll triggered one, else null. Trigger: `roll < 100`, `roll % 11 === 0`, and `roll / 11 ≤ chaos factor` (the factor used for the answer; the neutral one when none was given).
- Play: the ACL translator carries the rule into the snapshot; `LikelihoodContent` stores `randomEvent` (table key or null) and the API answer view exposes it. An oracle flow step's result carries it too. Branching on oracle answers is unchanged.
- UI: a likelihood journal entry with a random event shows a "Random event" badge and a "Roll <table name>" button that rolls the table through the existing table-roll path (a new journal entry). The oracle step card shows the same.
- Docs: ADR 0019 "Random events from fate-question doubles"; vision open question resolved; glossary "Random event"; contract doc.

### Preset `mythic-style` v1

- Start from `mythic-session.json` (the example fixture) and the current preset's richer tables. Original text only (fan-content rule, ADR 0018 decision 29); the description keeps "this is not the Mythic Game Master Emulator".
- Tags `rival`, `ally`. Tracker `chaos` 1–9, initial 5, bound to `fate`. `fate` gets `randomEvent: {table: "random-event-focus"}`.
- Premise (once, single Scene Type): premise (mandatory), first goal → `createThread`, who is involved → `createNpc`.
- Adventure (loop, single Scene Type picked automatically): the scene opening of example 3. Every focus table entry has a key; the rival, stranger, lead, loose end and ally entries branch as in the fixture; every other entry rolls the meaning tables (example 3: "other entries → meaning tables"), then "What happens instead?". Scene closing: chaos ±1 and "Update your lists".
- Tables: `scene-adjustments` (1d6 or d10), `random-event-focus` (1d100, ~12 entries with keys), `meaning-action` and `meaning-subject` (≥ 30 entries each, keep the current ones).
- Theme `parchment`, `defaultView: journal`.

### Dev data reset

```sql
DELETE FROM play_journal_entry;
DELETE FROM play_campaign;
DELETE FROM studio_gamesystem_release WHERE game_system_key = 'mythic-style';
```

Run with `docker compose exec -T database psql -U ogami -d ogami`, then `make presets` (publishes `mythic-style` v1). The test database is rebuilt by the test suite.

## Constraints

- Schema version 2 is extended **additively**; never edit `v1.schema.json`. The JSON Schema and the Version2 validator agree (`ReleaseSchemaAgreementTest`). Randomness stays a pure shared kernel (no framework, no I/O). Play reads the rule only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- `make api` after the response change; commit `openapi.json` and `schema.d.ts`.
- Glossary terms in code, UI and docs: Random event, Fate question, Likelihood oracle, Chaos factor, Oracle table.
- Every slice is safe on `main` alone: slice 1 adds an optional rule no preset uses yet.

## Slice plan

Forecasts include tests, docs and generated files (OpenAPI spec, TS types). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-mythic-flow-1-random-events` | `feat(play): preset-mythic-flow 1/3 random-events` | T1, T2, T3 | ~1,400 |
| 2 | `feat/preset-mythic-flow-2-preset` | `feat(play): preset-mythic-flow 2/3 preset` | T4, T5 | ~800 |
| 3 | `fix/preset-mythic-flow-3-playtest` | `fix(play): preset-mythic-flow 3/3 playtest` | T6, T7, T8 | ~950 (P1–P6 fixes, slice 2 review fixes, wrap-up) |

What keeps each slice safe on `main`:
- **Slice 1** adds the optional `randomEvent` rule. No preset declares it, so answers read the same.
- **Slice 2** replaces the Mythic-style preset; old Mythic campaigns are deleted from the dev database.
- **Slice 3** fixes playtest findings and closes the feature.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. ADR 0019 (random events from fate-question doubles), vision open question resolved, glossary "Random event", ADR index | delegated writer (slice 1) | [x] | 794af20 |
| T2 | 1 | Contract and domain: `randomEvent` in `v2.schema.json` and the Version2 validator (table exists, chaos present, sides 100), agreement fixtures (valid, invalid unknown table / no chaos / sides ≠ 100 / extra field); `LikelihoodOracle` rule and `LikelihoodAnswer::randomEvent()` with unit tests (each double, digit vs chaos, 100, no rule); ACL translator and snapshot; `LikelihoodContent` and the oracle step result; API answer views, API tests; `make api`; contract doc | delegated writer (slice 1) | [x] | b46c5c5 |
| T3 | 1 | UI: "Random event" badge and "Roll <table>" button on the likelihood journal entry and the oracle step card, rolling through the existing table-roll path; Vitest tests; fixtures updated | delegated writer (slice 1) | [x] | 8538e95 |
| T4 | 2 | Preset `mythic-style` v1 per Design; `PresetsTest` and `FlowRunExamplesTest` style coverage of the preset (validates, passes the ACL, a scene opening runs each focus branch); flow example 3 doc notes the doubles rule | delegated writer (slice 2) | [x] | 5fc7e22 |
| T5 | 2 | Dev data reset (parent, not committed); e2e `play-guided.spec.ts` updated for the new flow (premise creates a Thread and an NPC; a scene check); `make e2e` | delegated writer (slice 2) | [x] | 29ea370 |
| T6 | 3 | Playtest: Claude plays a full session in Chrome (premise, ≥ 5 adventure scenes, at least one altered and one interrupted scene, a fate-question double if it comes up, list updates, end session); findings logged below | parent (browser) | [x] | — |
| T7 | 3 | Fix playtest findings P1–P6 (see Playtest findings); P7–P13 stay in the backlog | delegated writer (slice 3) | [x] | |
| T8 | 3 | Wrap-up: roadmap feature 9 done, M2 exit note, feature doc closed | delegated writer (slice 3) | [x] | |

## Playtest findings

Played on 2026-10-13 in Chrome by Claude (campaigns "The Salt Road" and "The Salt Road II", `mythic-style` v1): premise, 5 adventure scenes (2 as expected, 1 altered, 2 interrupted: meaning tables, rival pick, lead pick), 16 fate questions with one double (55 at chaos 6 → random event offered and rolled), chaos moving 5→4 and 5→6→5→6, Threads and NPCs created by effects and by hand (tags), a second session.

| ID | Finding | Severity | Plan |
|---|---|---|---|
| P1 | The Fate question panel prefills the chaos factor (neutral 5) even when the oracle reads the `chaos` Tracker (`chaosTracker` in the API); Ask fails with "takes its chaos factor from tracker "chaos": send no chaos factor" until the field is cleared | Blocking | Fix (T7): with `chaosTracker` set, no chaos input; show the Tracker's current value read-only and send no chaos factor |
| P2 | Guided play can't end a session in a loop phase with one Scene Type: the scene pick auto-picks at once after each scene, and "End session" shows only at the pick. Workaround Pause → Start session → Resume leaves an empty scene in the old session | Blocking | Fix (T7), user decision 2026-10-13: auto-pick only when a session starts or a phase opens; after a scene the FlowRun waits at the scene pick ("Play this", "End session", "Move on") |
| P3 | "Move on" ends the loop phase (for Mythic the last phase, so the whole Flow) in one click, shown from the first step of a scene; it reads like "next step". A misclick completes the FlowRun for good | High | Fix (T7): name what it does ("End the Adventure phase", from the phase name) and say what follows |
| P4 | The shared "What happens instead?" tip renders "Involve  if someone was picked." on no-pick paths and "Involve Find the lost caravan if someone was picked." for a Thread (slice 2 review suggestion) | Medium | Fix (T7): preset steps so pick branches and the others get their own wording; preset flow test covers both |
| P5 | Before the first session the guide reads "Next: Next: Scene" (the pick label carries its own "Next:" and the UI adds one) | Low | Fix (T7) |
| P6 | Premise uses Scene Type "Scene", described "Play out one scene of the adventure."; the scene check tip says "Roll under" while the band is "up to" (≤) | Low | Fix (T7): preset wording |
| P7 | A roll step's outcome is not interpreted (no "as expected / altered / interrupted" line); the flow branches silently | Medium | Backlog |
| P8 | "Update your lists" is a free-text prompt with no link to the Threads and NPCs panels | Low | Backlog |
| P9 | A random event rolled from a fate-question offer lands at the end of the journal, not tied to its question, with no meaning prompt | Low | Backlog (procedures deferred) |
| P10 | "Start session" and the "Start scene" form stay visible during guided play; using them mid-scene leaves empty scenes | Medium | Backlog |
| P11 | Default scene titles count across sessions (session 2's first scene is "Scene 8") | Low | Backlog |
| P12 | The "Next:" line after the scene check names the altered branch before the 1d6 decides | Low | Backlog |
| P13 | Scene headings are the uppercased expectation, long titles wrap into shouting lines | Low | Backlog |

## Progress

- 2026-10-12: feature started. Explored the preset, the example fixture, the fate-question code and the dev data. Doubles decision (declarative rule), playtest owner (Claude) and data reset decided with the user. Doc and slice plan written; issue #88.
- 2026-10-12: T1 done (docs only, no RED/GREEN applicable; structural readback). Route: delegated writer.
- 2026-10-12: T2 done. RED: `LikelihoodOracleRandomEventTest` failed (89 errors, 8 failures) before the domain code, GREEN after (396 Randomness tests); Studio schema, validator, fixtures, ACL translator, journal content and API tests written with the code, no separate RED run; `make api` run. Route: delegated writer. Added `backend/contracts/gamesystem-release/v2.schema.json` and `frontend/src/shared/api/openapi.json` (regenerated by `make api`) to the edit surface; the oracle step card shows no answer (the step completes and the answer is a journal entry), so the offer lives on the journal entry only.
- 2026-10-12: T3 done. Vitest tests (badge on a flagged entry only; offer rolls the declared table through the oracle table request; offer only in the current scene) written with the component, no separate RED run. Route: delegated writer.

- 2026-10-13: slice 1 review suggestions fixed. RED: the "Rolled" test failed against the old offer, GREEN after; the Focus panel "So far" offer test passes against existing code (coverage only). Route: delegated writer.
- 2026-10-13: T4 done. RED: `MythicStylePresetFlowTest` (12 tests) and `PresetsTest` failed against the old preset (3 errors, 9 failures; 1 failure), GREEN after the new preset. Original `random-event-focus` (12 keyed entries, 6 branch, the rest roll the meaning tables), tags, `randomEvent` on `fate`, parchment theme. Route: delegated writer.
- 2026-10-13: T5 done. e2e `play-guided.spec.ts` extended (premise goal creates a Thread, who is involved an NPC, End scene, the first adventure scene's expectation and scene check with real randomness, outcome-independent asserts). No separate RED run (written with the run); `make e2e` 13 passed, publishing `mythic-style` v1. Route: delegated writer.
- 2026-10-13: T6 done. Playtest in Chrome (see Playtest findings): 13 findings, P1 and P2 blocking. User chose auto-pick only at session start or phase opening for P2. Route: parent (browser).
- 2026-10-13: slice 2 review WARNING fixed: the `RandomEventOffer` docblock now says the "Rolled" mark is per mount (a remount offers the roll again; no persistence added). Docblock only, no test. The two SUGGESTIONs (`{picked}` on no-pick paths, pick transitions) are folded into T7's preset change (P4) and its `MythicStylePresetFlowTest` cases. Route: delegated writer (slice 3).
- 2026-10-13: T7 P2 + P5. The FlowRun keeps `autoPickDue` (set by the session opening and the phase opening, cleared when a scene starts, persisted; legacy rows read false = waiting): after a scene the pick of the only Scene Type waits ("Play this", "End session", "Move on"), stable across resume; the next session and the next phase opening still pick by themselves. Sequence pick label is "Next scene: <name>". ADR 0020 amends ADR 0018 decision 11. RED: 4 new `MythicStylePresetFlowTest` cases (stuck at step "expected", `endSession` refused), the 2 label assertions in `FlowRunViewTest`, and 4 repository tests (shape, malformed, round trip, every state); GREEN after. The API test and the frontend pick test were written after the domain fix (no separate RED; the frontend needed no change). Existing tests that relied on the instant pick after a scene now pick by hand; a full session no longer refuses the last step of its last scene. Route: delegated writer (slice 3).
- 2026-10-13: T7 P1 + P3 + P4 + P6. P1: an oracle with `chaosTracker` shows the Tracker's current value read only ("6 (from the Chaos factor tracker)", read from the campaign query) and sends no chaos factor. P3: "Move on" reads "End the <phase> phase" (a leading "The" dropped); the view has no next phase, so no hint. P4: the preset asks "What happens instead?" in three steps: `instead-npc` ("Involve {picked}.") after a rival, stranger or ally, `instead-thread` ("Tie it to {picked}.") after a lead or loose end, `instead` (no `{picked}`) after the meaning tables or an empty pick; lead and ally got found/none branches. P6: Scene Type purpose "Play out one scene.", scene check tip "Roll at or under the chaos factor". RED: Vitest tracker case (1) and the end-phase label cases (4), `MythicStylePresetFlowTest` against the old preset (10 failures); GREEN after. Covers the slice 2 review suggestions (no-pick prompt, pick transitions). The preset publishes as `mythic-style` v2. Route: delegated writer (slice 3).
- 2026-10-13: T7 follow-up: the Behat chain scenario still expected the next scene to start after "wrap"; a separate `test(play)` commit makes it wait at the pick and adds a scenario that ends the session there and starts the next one (commit 2 alone leaves that scenario failing). Route: delegated writer (slice 3).
- 2026-10-13: T8 done. Roadmap: feature 9 done, M2 exit note (a full Mythic-style session played end to end; P7–P13 as backlog). Docs only, structural readback. Slice checks: `make qa` passed; `make test` passed (PHPUnit 2769, Behat 66 scenarios, Vitest 442); `make e2e` 13 passed, publishing `mythic-style` v2. Route: delegated writer (slice 3).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-0f44e6718386e6f2` | 794af20, b46c5c5, 8538e95 | Approved, acknowledged. WARNING: stored likelihood content without `randomEvent` is rejected (accepted: dev data wiped, no legacy rows). SUGGESTION: the Roll button can roll the same event again after a success; SUGGESTION: no test for the offer in the Focus panel "So far" list (both fixed in slice 2). Merge commit 5816e90; GitHub never marked the PR merged, closed by hand | [#89](https://github.com/iribarren/ogami/pull/89) |
| 2 | `review-1bd18c590ad623f6` | 5f079a3, 5fc7e22, 29ea370 | Approved, acknowledged. WARNING: the "Rolled" state is per mount (the docblock overstates it). SUGGESTION: the `{picked}` placeholder on no-pick paths is untested; SUGGESTION: pick transitions only partly covered (fixed in slice 3). Merge commit bf387bf | [#90](https://github.com/iribarren/ogami/pull/90) |

## Next step

Review slice 3, PR (`Closes #88`), then the last slice's doc-only commit with the review outcome and PR link.
