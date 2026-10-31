# Feature: play-places

- **Locator:** `odd/tasks/play-places.md` · Engram topic `odd/play-places/tasks`
- **Issue:** [#330](https://github.com/iribarren/ogami/issues/330) · **Branch:** `feat/play-places-1-places-list` (slice 1) · **Status:** in progress
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
| Docs (Claude) | ADR 0047 "Places" (slice 1, decisions above for places and `closeThread`); ADR 0048 "Select sheet fields" (slice 4); glossary row "Place"; `docs/vision.md` open-question row → answered; example 5 note and roadmap B15 prompt point to ADR 0047 |

## Design

### Slice 1: Places list (Play backend)

- Domain `backend/src/Play/Domain/Campaign/Lists/`: `Place`, `PlaceId`, `UnknownPlace`, `PlaceAlreadyExists` (mirroring `Thread*`); `Campaign` holds `places` with add/edit/close/reopen, `MAX_PLACES`, duplicate-id check in `reconstitute()`.
- Application: `AddPlace`, `EditPlace`, `ClosePlace`, `ReopenPlace` (+ handlers), `PlaceView`, `PlaceIdGenerator`; `CampaignView` gains `places`.
- Infrastructure: `CampaignPlacesType` (JSONB, registered in `doctrine.yaml`), mapping column `places`, migration `ADD places JSONB DEFAULT '[]' NOT NULL` (legacy rows read as no places), `UuidPlaceIdGenerator`, `CampaignPlacesController` (`/api/campaigns/{id}/places` POST, PUT/PATCH edit, close, reopen, same codes as Threads), `PlaceRequest`/`PlaceResponse`, `CampaignResponse.places`; `make api`.
- Tags with `appliesTo: place` don't exist yet: a Place takes free Tags only until slice 2 (release Tags are refused by `ItemRules::assertInRelease` as not applicable).

### Slice 2: contract, ACL and flow (backend)

- Schema and `ReleaseVersion2` / `Steps` / `Effects` / `Catalog` / `DraftReferences` for `place` slots, `createPlace`, `pick`/`count` of `place`, `appliesTo: place`, `closeThread.fact`; contract doc; fixtures; `ReleaseSchemaAgreementTest`.
- ACL `GameSystemReleaseTranslator`: `FactSlotType::Place`, `ItemKind::Place`, `CreatePlaceEffect`, `CloseThreadEffect` with optional slot.
- Play: `FactFill`/`FactLinkKind` place, `FlowRun`/`FlowRunContext` createPlace, pick and count of places, closeThread by slot; `Campaign::transfer` carries places; `ReleaseUpgradePlanner` dropped Tags for places.

### Slice 3: Places UI (Play and Studio frontend)

- Play: `ListPanel` kind `place` (Places panel beside NPCs and Threads, `CampaignPage`, `playPanelStorage`), `FactsPanel` place slots and links, `PickForm`, journal pick content.
- Studio: fact slot type `place`, `appliesTo` place, `createPlace` effect, `closeThread` fact select, pick and count of places.
- e2e or Chrome pass: a flow that creates and picks a Place.

### Slice 4: `select` sheet field (every layer)

- Contract schema `selectField`, `SheetFields.php`, contract doc table, fixtures; ACL `sheetField()` arm, `SelectField`; `CharacterValues` checked/initial; `ReleaseUpgradePlanner` option removed → reset; `SheetFieldView`/`SheetFieldResponse` options; frontend `SheetFields` select control, `sheetValues`; Studio `SheetFieldDialog` options editor (reuse `ConditionOptionsEditor`), preview; ADR 0048; closing.

## Constraints

- Each slice safe on `main` alone: slice 1's endpoints are unused until slice 3's panel; slice 2's contract parts are unused by any preset until B15.
- Pre-review checklist: malformed or unknown input fails closed (422, never a 500 or a silent default); every domain error mapped (404/409/422) with an API test, including the 409 on a concurrent save; commands all-or-nothing; persistence round trips compare the whole aggregate; legacy rows (campaigns stored before the `places` column) read as no places and are tested.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-places-1-places-list` | `feat(play): play-places 1/4 places list` | T1, T2, T3 | ~1,600 (docs ~150, domain and application ~400, HTTP and persistence ~250, tests ~450, generated ~350) |
| 2 | `feat/play-places-2-flow` | `feat(play): play-places 2/4 places in flows` | T4–T7 | ~1,600 |
| 3 | `feat/play-places-3-ui` | `feat(play): play-places 3/4 places ui` | T8–T10 | ~1,400 |
| 4 | `feat/play-places-4-select-field` | `feat(play): play-places 4/4 select sheet field` | T11–T14 | ~1,600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0047 "Places" (+ ADR index); glossary row "Place"; `docs/vision.md` row answered; example 5 note and roadmap B15 prompt point to ADR 0047 | delegated writer (slice 1) | [x] | |
| T2 | 1 | Domain `Place` list on `Campaign` and application commands (add, edit, close, reopen), `CampaignView.places`, with unit tests | delegated writer (slice 1) | [x] | |
| T3 | 1 | HTTP only (persistence already landed in T2): `/api/campaigns/{id}/places` endpoints, `CampaignResponse.places`, API tests (201, 404, 409 concurrent, 422), Behat `places.feature`, `make api` | delegated writer (slice 1) | [ ] | |
| T4 | 2 | Slice 1 review fixes | | [ ] | |
| T5 | 2 | Contract: `place` slots and links, `createPlace`, `pick`/`count` of `place`, `appliesTo: place`, `closeThread.fact`; Studio validation, contract doc, fixtures | | [ ] | |
| T6 | 2 | ACL and Play flow: createPlace, place slots, pick and count of places, closeThread by slot | | [ ] | |
| T7 | 2 | Transfer carries places; upgrade planner dropped Tags for places | | [ ] | |
| T8 | 3 | Slice 2 review fixes | | [ ] | |
| T9 | 3 | Play UI: Places panel, facts place slots and links, pick form, journal | | [ ] | |
| T10 | 3 | Studio UI: place slot type, `appliesTo` place, `createPlace`, `closeThread` fact, pick/count of places | | [ ] | |
| T11 | 4 | Slice 3 review fixes | | [ ] | |
| T12 | 4 | `select` field: contract, Studio validation, ACL, Play values, upgrade planner, API views; ADR 0048 | | [ ] | |
| T13 | 4 | `select` field UI: Play sheet control, Studio sheet builder options and preview | | [ ] | |
| T14 | 4 | Closing: Reviews, roadmap `Done:` line under B14 | | [ ] | |

## Progress

- 2026-10-31: Exploration (read-only explorer): NPCs and Threads are JSONB lists on the Campaign with closed enums `FactSlotType` (text/npc/thread) and `ItemKind` (npc/thread/character) matched exhaustively in `Campaign`, `FlowRun`, `FlowRunContext`, `ReleaseUpgradePlanner` and `GameSystemReleaseTranslator`; `tag.appliesTo` maxItems 3; sheet field types are number, text, list, track, derived, conditions, inventory (conditions' `options` is the model for `select`); latest ADR 0046; schema version 2. User decisions: Places list; `closeThread` by fact slot in scope.
- 2026-10-31 T1: ADR 0047, ADR index, glossary "Place", vision question answered, example 5 note; roadmap B15 left unchanged (its prompt is a code block, no link renders). Docs only, no test.
- 2026-10-31 T2: `Place`, `PlaceId` and errors; `Campaign` places (MAX_PLACES, add/edit/close/reopen, reconstitute check); application commands, `PlaceView`, `CampaignView.places`. `ItemRules::assertPlaceTags` and `TagNotApplicable::forPlaces` refuse release Tags without touching `ItemKind`. RED observed for `CampaignPlacesTest` (21 failures, classes missing); `PlaceHandlersTest` and the `GetCampaignHandlerTest` places case were written first but only run once the code existed. Persistence moved into this commit (not T3): Doctrine hydrates `Campaign` without its constructor, so an unmapped `places` property made every DB-backed test fail (500, uninitialized typed property) at the T2 commit alone. It adds `CampaignPlacesType` (JSONB, notes), the `places` column mapping with its `[]` default, migration `Version20261031120000`, `UuidPlaceIdGenerator`, the whole-aggregate round trip in `CampaignRepositoryContract`, and legacy-row and malformed-list tests. The persistence tests were written alongside the code, not observed RED first. `tests/Unit/Play`, `tests/Integration/Play/Persistence` and `tests/Unit/Play/Infrastructure` pass.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: writer builds T1–T3 on `feat/play-places-1-places-list`.
