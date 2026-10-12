# Feature: preset-mythic-flow

- **Locator:** `odd/tasks/preset-mythic-flow.md` · Engram topic `odd/preset-mythic-flow/tasks`
- **Issue:** #88 · **Branch:** `feat/preset-mythic-flow-1-random-events` · **Status:** slice 1 in progress
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
| 3 | `fix/preset-mythic-flow-3-playtest` | `fix(play): preset-mythic-flow 3/3 playtest` | T6, T7, T8 | ~600 (depends on findings) |

What keeps each slice safe on `main`:
- **Slice 1** adds the optional `randomEvent` rule. No preset declares it, so answers read the same.
- **Slice 2** replaces the Mythic-style preset; old Mythic campaigns are deleted from the dev database.
- **Slice 3** fixes playtest findings and closes the feature.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. ADR 0019 (random events from fate-question doubles), vision open question resolved, glossary "Random event", ADR index | delegated writer (slice 1) | [x] | |
| T2 | 1 | Contract and domain: `randomEvent` in `v2.schema.json` and the Version2 validator (table exists, chaos present, sides 100), agreement fixtures (valid, invalid unknown table / no chaos / sides ≠ 100 / extra field); `LikelihoodOracle` rule and `LikelihoodAnswer::randomEvent()` with unit tests (each double, digit vs chaos, 100, no rule); ACL translator and snapshot; `LikelihoodContent` and the oracle step result; API answer views, API tests; `make api`; contract doc | delegated writer (slice 1) | [x] | |
| T3 | 1 | UI: "Random event" badge and "Roll <table>" button on the likelihood journal entry and the oracle step card, rolling through the existing table-roll path; Vitest tests; fixtures updated | delegated writer (slice 1) | [ ] | |
| T4 | 2 | Preset `mythic-style` v1 per Design; `PresetsTest` and `FlowRunExamplesTest` style coverage of the preset (validates, passes the ACL, a scene opening runs each focus branch); flow example 3 doc notes the doubles rule | delegated writer (slice 2) | [ ] | |
| T5 | 2 | Dev data reset (parent, not committed); e2e `play-guided.spec.ts` updated for the new flow (premise creates a Thread and an NPC; a scene check); `make e2e` | delegated writer (slice 2) | [ ] | |
| T6 | 3 | Playtest: Claude plays a full session in Chrome (premise, ≥ 5 adventure scenes, at least one altered and one interrupted scene, a fate-question double if it comes up, list updates, end session); findings logged below | parent (browser) | [ ] | |
| T7 | 3 | Fix the blocking findings of T6 that fit the slice; the rest become backlog tasks | delegated writer (slice 3) | [ ] | |
| T8 | 3 | Wrap-up: roadmap feature 9 done, M2 exit note, feature doc closed | delegated writer (slice 3) | [ ] | |

## Playtest findings

None yet (T6).

## Progress

- 2026-10-12: feature started. Explored the preset, the example fixture, the fate-question code and the dev data. Doubles decision (declarative rule), playtest owner (Claude) and data reset decided with the user. Doc and slice plan written; issue #88.
- 2026-10-12: T1 done (docs only, no RED/GREEN applicable; structural readback). Route: delegated writer.
- 2026-10-12: T2 done. RED: `LikelihoodOracleRandomEventTest` failed (89 errors, 8 failures) before the domain code, GREEN after (396 Randomness tests); Studio schema, validator, fixtures, ACL translator, journal content and API tests written with the code, no separate RED run; `make api` run. Route: delegated writer. Added `backend/contracts/gamesystem-release/v2.schema.json` and `frontend/src/shared/api/openapi.json` (regenerated by `make api`) to the edit surface; the oracle step card shows no answer (the step completes and the answer is a journal entry), so the offer lives on the journal entry only.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: brief one writer for T1–T3.
