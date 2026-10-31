# Feature: play-places

- **Locator:** `odd/tasks/play-places.md` · Engram topic `odd/play-places/tasks`
- **Issue:** [#330](https://github.com/iribarren/ogami/issues/330) · **Branch:** `feat/play-places-2-places-api` (slice 2) · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-campaign-transfer` (PRs #327–#329)

## Objective

Roadmap backlog B14, before B15 `preset-west-marches` ([example 5](../../docs/domain/flow-examples.md#5-west-marches)). Settle the vision open question "How are places modeled (a map, regions, sites)?" and record it as an ADR, then build it in every layer: contract, anti-corruption layer, Play, Studio and the release upgrade planner. Add a `select` sheet field type (options declared by the release) for a class or background, in every layer too.

Out of scope: a drawn map; adjacency or routes between places; a distance or region attribute on a Place; formulas or effects reading a `select` value.

## Problem and why

- Example 5 keeps the town and the sites as free Campaign facts: nothing can pick a site, tag it by region, or create one from a rumor or site table.
- A class or background on a D&D 5e sheet has a closed set of options; today it is free `text`.
- Exploration also found that `closeThread` only closes the `{picked}` Thread, which is cleared at every scene boundary (`FlowRun.php:1167,1237,1521`), so example 5's sessionClosing `closeThread` on the expedition Thread (slot `expedition`, picked in sessionOpening) does nothing.

## Decisions

| Question | Decision |
|---|---|
| Places model (user, 2026-10-31) | A **Places list** beside NPCs and Threads (not facts with a `place` slot, not regions with distance) |
| closeThread gap (user, 2026-10-31) | In scope: `closeThread` gains an optional `fact` target |
| Place shape (Claude) | Mirrors `Thread`: id (`PlaceId`), name (required, ≤ 200), release Tags and free Tags (`ItemRules`), weight, status `open`/`closed` (`ItemStatus`: only open places are picked and counted; closed = cleared or lost), plus optional `notes` (≤ 2,000 chars, blank → null). Limit `MAX_PLACES` = 500, as NPCs and Threads. No delete, as NPCs and Threads |
| Distance and regions (Claude) | No attribute on a Place. Encounter tables by distance use tracker bands (`away` in example 5, already supported). A region is a release Tag on places (`near`, `deep`…) |
| Contract (Claude) | Additive within schema version 2 (no version bump): fact slot type `place` (picks or creates a Place, as `npc`/`thread`); fact links of kind `place`; effect `createPlace {name?, tags?}` like `createThread` (the created Place becomes `{picked}`; a Place never joins the Scene cast); `pick` and condition `count` gain `of: place`; `tag.appliesTo` gains `place` (maxItems 4). `closeThread` gains optional `fact` (a slot key of type `thread`): it closes the Thread filling that slot (nothing if the slot is empty); without `fact` it closes `{picked}` as today. Studio refuses `fact` naming an unknown or non-`thread` slot |
| Transfer (Claude) | Places come along with their ids, as Threads ([ADR 0046](../../docs/adr/0046-campaign-transfer.md)) |
| Upgrade planner (Claude) | Places get the same dropped-Tags handling as Threads; facts in a `place` slot that disappears are unslotted as today. The existing gap "slot type changes, key kept" is not fixed here (noted in the ADR) |
| `select` field (Claude) | `{type: select, key, label, options: 1–50 of {key, label}, default?: option key}`. Value: one option key or null (unset); initial value `default` or null. Not readable by formulas, not changed by effects, not in point budgets. Upgrade: a chosen option the new release no longer declares resets to the new `default` or null (`reset`); a type change resets as today |
| Docs (Claude) | ADR 0047 "Places" (slice 1, decisions above for places and `closeThread`); ADR 0048 "Select sheet fields" (slice 5); glossary row "Place"; `docs/vision.md` open-question row → answered; example 5 note and roadmap B15 prompt point to ADR 0047 |

## Design

### Slice 1: Places list (Play backend)

- Domain `backend/src/Play/Domain/Campaign/Lists/`: `Place`, `PlaceId`, `UnknownPlace`, `PlaceAlreadyExists` (mirroring `Thread*`); `Campaign` holds `places` with add/edit/close/reopen, `MAX_PLACES`, duplicate-id check in `reconstitute()`.
- Application: `AddPlace`, `EditPlace`, `ClosePlace`, `ReopenPlace` (+ handlers), `PlaceView`, `PlaceIdGenerator`; `CampaignView` gains `places`.
- Infrastructure: `CampaignPlacesType` (JSONB, registered in `doctrine.yaml`), mapping column `places`, migration `ADD places JSONB DEFAULT '[]' NOT NULL` (legacy rows read as no places), `UuidPlaceIdGenerator`.
- Tags with `appliesTo: place` don't exist yet: a Place takes free Tags only until slice 3 (release Tags are refused by `ItemRules::assertPlaceTags` with `TagNotApplicable::forPlaces`).

### Slice 2: Places API (Play backend)

- Slice 1 review fixes.
- `CampaignPlacesController` (`/api/campaigns/{id}/places` POST, edit, close, reopen, same routes and codes as Threads), `PlaceRequest`/`PlaceResponse`, `CampaignResponse.places`; API tests; Behat `places.feature`; `make api` (regenerated client in its own commit).

### Slice 3: contract, ACL and flow (backend)

- Schema and `ReleaseVersion2` / `Steps` / `Effects` / `Catalog` / `DraftReferences` for `place` slots, `createPlace`, `pick`/`count` of `place`, `appliesTo: place`, `closeThread.fact`; contract doc; fixtures; `ReleaseSchemaAgreementTest`.
- ACL `GameSystemReleaseTranslator`: `FactSlotType::Place`, `ItemKind::Place`, `CreatePlaceEffect`, `CloseThreadEffect` with optional slot.
- Play: `FactFill`/`FactLinkKind` place, `FlowRun`/`FlowRunContext` createPlace, pick and count of places, closeThread by slot; `Campaign::transfer` carries places; `ReleaseUpgradePlanner` dropped Tags for places.

### Slice 4: Places UI (Play and Studio frontend)

- Play: `ListPanel` kind `place` (Places panel beside NPCs and Threads, `CampaignPage`, `playPanelStorage`), `FactsPanel` place slots and links, `PickForm`, journal pick content.
- Studio: fact slot type `place`, `appliesTo` place, `createPlace` effect, `closeThread` fact select, pick and count of places.
- e2e or Chrome pass: a flow that creates and picks a Place.

### Slice 5: `select` sheet field (every layer)

- Contract schema `selectField`, `SheetFields.php`, contract doc table, fixtures; ACL `sheetField()` arm, `SelectField`; `CharacterValues` checked/initial; `ReleaseUpgradePlanner` option removed → reset; `SheetFieldView`/`SheetFieldResponse` options; frontend `SheetFields` select control, `sheetValues`; Studio `SheetFieldDialog` options editor (reuse `ConditionOptionsEditor`), preview; ADR 0048; closing.

## Constraints

- Each slice safe on `main` alone: slice 1's list and slice 2's endpoints are unused until slice 4's panel; slice 3's contract parts are unused by any preset until B15.
- Pre-review checklist: malformed or unknown input fails closed (422, never a 500 or a silent default); every domain error mapped (404/409/422) with an API test, including the 409 on a concurrent save; commands all-or-nothing; persistence round trips compare the whole aggregate; legacy rows (campaigns stored before the `places` column) read as no places and are tested.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-places-1-places-list` | `feat(play): play-places 1/5 places list` | T1, T2 | +1,740/−18 (HTTP moved to slice 2 to stay under the ceiling) |
| 2 | `feat/play-places-2-places-api` | `feat(play): play-places 2/5 places api` | T4, T3 | ~1,400 (controller, request and response ~350, API tests ~550, Behat ~150, generated ~350) |
| 3 | `feat/play-places-3-flow` | `feat(play): play-places 3/5 places in flows` | T15, T5–T7 | ~1,600 |
| 4 | `feat/play-places-4-ui` | `feat(play): play-places 4/5 places ui` | T8–T10 | ~1,400 |
| 5 | `feat/play-places-5-select-field` | `feat(play): play-places 5/5 select sheet field` | T11–T14 | ~1,600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0047 "Places" (+ ADR index); glossary row "Place"; `docs/vision.md` row answered; example 5 note and roadmap B15 prompt point to ADR 0047 | delegated writer (slice 1) | [x] | `cbaae86c` |
| T2 | 1 | Domain `Place` list on `Campaign` and application commands (add, edit, close, reopen), `CampaignView.places`, with unit tests | delegated writer (slice 1) | [x] | `cbc32634` |
| T3 | 2 | HTTP only (persistence already landed in T2): `/api/campaigns/{id}/places` endpoints, `CampaignResponse.places`, API tests (201, 404, 409 concurrent, 422), Behat `places.feature`, `make api` | delegated writer (slice 2) | [x] | |
| T4 | 2 | Slice 1 review fixes: `CampaignPlacesType` limit reads `MAX_PLACES`; repository contract asserts the reloaded Places after close and edit (the doc naming was fixed by the parent) | delegated writer (slice 2) | [x] | |
| T15 | 3 | Slice 2 review fixes | | [ ] | |
| T5 | 3 | Contract: `place` slots and links, `createPlace`, `pick`/`count` of `place`, `appliesTo: place`, `closeThread.fact`; Studio validation, contract doc, fixtures | | [ ] | |
| T6 | 3 | ACL and Play flow: createPlace, place slots, pick and count of places, closeThread by slot | | [ ] | |
| T7 | 3 | Transfer carries places; upgrade planner dropped Tags for places | | [ ] | |
| T8 | 4 | Slice 3 review fixes | | [ ] | |
| T9 | 4 | Play UI: Places panel, facts place slots and links, pick form, journal | | [ ] | |
| T10 | 4 | Studio UI: place slot type, `appliesTo` place, `createPlace`, `closeThread` fact, pick/count of places | | [ ] | |
| T11 | 5 | Slice 4 review fixes | | [ ] | |
| T12 | 5 | `select` field: contract, Studio validation, ACL, Play values, upgrade planner, API views; ADR 0048 | | [ ] | |
| T13 | 5 | `select` field UI: Play sheet control, Studio sheet builder options and preview | | [ ] | |
| T14 | 5 | Closing: Reviews, roadmap `Done:` line under B14 | | [ ] | |

## Progress

- 2026-10-31: Exploration (read-only explorer): NPCs and Threads are JSONB lists on the Campaign with closed enums `FactSlotType` (text/npc/thread) and `ItemKind` (npc/thread/character) matched exhaustively in `Campaign`, `FlowRun`, `FlowRunContext`, `ReleaseUpgradePlanner` and `GameSystemReleaseTranslator`; `tag.appliesTo` maxItems 3; sheet field types are number, text, list, track, derived, conditions, inventory (conditions' `options` is the model for `select`); latest ADR 0046; schema version 2. User decisions: Places list; `closeThread` by fact slot in scope.
- 2026-10-31 T1: ADR 0047, ADR index, glossary "Place", vision question answered, example 5 note; roadmap B15 left unchanged (its prompt is a code block, no link renders). Docs only, no test.
- 2026-10-31 T2: `Place`, `PlaceId` and errors; `Campaign` places (MAX_PLACES, add/edit/close/reopen, reconstitute check); application commands, `PlaceView`, `CampaignView.places`. `ItemRules::assertPlaceTags` and `TagNotApplicable::forPlaces` refuse release Tags without touching `ItemKind`. RED observed for `CampaignPlacesTest` (21 failures, classes missing); `PlaceHandlersTest` and the `GetCampaignHandlerTest` places case were written first but only run once the code existed. Persistence moved into this commit (not T3): Doctrine hydrates `Campaign` without its constructor, so an unmapped `places` property made every DB-backed test fail (500, uninitialized typed property) at the T2 commit alone. It adds `CampaignPlacesType` (JSONB, notes), the `places` column mapping with its `[]` default, migration `Version20261031120000`, `UuidPlaceIdGenerator`, the whole-aggregate round trip in `CampaignRepositoryContract`, and legacy-row and malformed-list tests. The persistence tests were written alongside the code, not observed RED first. `tests/Unit/Play`, `tests/Integration/Play/Persistence` and `tests/Unit/Play/Infrastructure` pass.
- 2026-10-31: Plan becomes 5 slices: T3 (HTTP) moved to slice 2 to keep slice 1 under the size ceiling; T15 added for slice 2's review fixes.
- 2026-10-31 T4: `CampaignPlacesType` limit check and message read `Campaign::MAX_PLACES`; `aCampaignWithPlacesIsKeptWhole` asserts the reloaded Places equal the saved aggregate's after close and edit. The over-limit persistence case already exists (501 Places); both limits are 500 so it cannot tell them apart (a test cannot without changing a constant). Persistence suite run before commit.
- 2026-10-31 T3: `CampaignPlacesController`, `PlaceRequest`, `PlaceResponse`, `CampaignResponse.places` (required). RED observed: `CampaignPlacesApiTest` ran with 38 failures and 1 error (no route, no controller) before the code; GREEN after: `CampaignPlacesApiTest` and `PlayRequestSchemasTest` 59 tests pass. Behat `places.feature` (4 scenarios) was written with its steps and run only once both existed (not observed RED). A release Tag on a Place is a 422 (`TagNotApplicable`), tested in `CampaignPlacesApiTest`; `CampaignTagScopeApiTest` untouched (its cases need a Tag that applies to the kind). Generated client (`frontend/src/shared/api/openapi.json`, `schema.d.ts`) and frontend fixtures in the next commits. `make verify` first run failed on 3 `CampaignApiTest` cases (full-body key lists lacked `places`) and `tsc -b` on two `campaigns/fixtures.ts` objects; both fixed in a follow-up commit.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-7219a3078966f655` | `cbaae86c`, `cbc32634` | Approved. Findings fixed in slice 2 (T4): `CampaignPlacesType` reads `MAX_THREADS` instead of `MAX_PLACES` (warning); the repository contract should assert the reloaded Places after close and edit; this doc named `assertInRelease` instead of `assertPlaceTags` (fixed by the parent) | [#331](https://github.com/iribarren/ogami/pull/331) |

## Next step

Slice 2: writer builds T4, T3 on `feat/play-places-2-places-api`.
