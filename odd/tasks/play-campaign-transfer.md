# Feature: play-campaign-transfer

- **Locator:** `odd/tasks/play-campaign-transfer.md` · Engram topic `odd/play-campaign-transfer/tasks`
- **Issue:** [#326](https://github.com/iribarren/ogami/issues/326) · **Branch:** `feat/play-campaign-transfer-2-ui` (slice 1 merged, PR #327) · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-cpr-campaign` (PRs #323–#324)

## Objective

Roadmap backlog B13. In Play, start a new campaign from an existing one with another Flow of the same GameSystem (e.g. the `night-job` heist one-shot that grows into the "Making a Name" campaign), carrying over Campaign facts, NPCs and Threads, and optionally Characters. The source campaign stays unchanged.

Out of scope: moving between GameSystems; refitting a campaign on an older release (the existing release upgrade does that first); a stored link from the new campaign back to its source.

## Problem and why

- A campaign's Flow is fixed ([ADR 0017](../../docs/adr/0017-narrativeflow-model.md) §7): only guidance pause and resume. A one-shot whose story should continue as a campaign has to be retyped by hand.
- `night-job` (release v2) now has a one-shot `heist` and a `campaign` flow sharing one sheet, Tags, fact slots and trackers, so it is the first GameSystem where this matters.

## Decisions

| Question | Decision |
|---|---|
| Tracker values (user) | Reset: the new campaign starts every tracker at its release `initial` value, as a new campaign does. Tracker values are per-arc state (alarm, objective, gig) |
| Source on an older release (user) | Refused with 409 until the source is upgraded through the existing release upgrade ([ADR 0035](../../docs/adr/0035-campaign-release-upgrade.md)). The new campaign pins the same, latest release, so nothing is refitted: Characters, Tags, slot facts and NPC Tags stay valid as they are |
| Characters (user) | The player picks which Characters come along (a checkbox each, all checked by default in the UI). An NPC promoted to a Character that stays behind keeps its data but loses `promotedTo`; a carried Character keeps `promotedFrom` (NPCs always come along) |
| NPCs and Threads (user) | All of them, open and closed, with their ids, weights, agendas, Tags and statuses |
| Origin link (user) | None: no stored `transferredFrom`, no migration. Journal entries, sessions and scenes stay with the source, which is still listed |
| Facts (Claude) | All facts with their ids, slots, links and subjects. Fact links only reference NPCs, Threads and facts (`FactLinkKind`), which all come along. A target flow's `fillFact` on an already filled slot overwrites it, as in any campaign (night-job's heist and campaign flows fill different slots) |
| Ids (Claude) | NPC, Thread, Fact and Character ids are kept: they are unique per campaign, and keeping them keeps links and promotions valid with no remapping. The new campaign gets a new `CampaignId` from `CampaignIdGenerator` |
| Target flow (Claude) | Any Flow of the release, the source's own Flow included (a sequel heist with the same crew), or free play (`flowKey` null), as `POST /api/campaigns`. The release default is not applied server-side (as `CreateCampaign`) |
| Name (Claude) | Required, player-given, same rules as a new campaign. The UI prefills "<source name>: <flow name>" |
| Domain shape (Claude) | `Campaign::transfer(...)` on the source returns a new `Campaign` built through the same path as `Campaign::create` (trackers initial, `FlowRun::start()` with a Flow, no sessions), then copies the carried lists; the source is not modified. Invalid input fails before anything is built |
| API (Claude) | `POST /api/campaigns/{id}/transfer` with `{name, flowKey (nullable), characterIds: string[]}` → 201, `Location` of the new campaign, the same body as `POST /api/campaigns`. Errors: 404 source missing or another player's; 409 source not on the latest release, generated id taken; 422 blank name, unknown flow, unknown or duplicate character id, malformed body. Error codes follow the existing naming in `CampaignController` and `campaignError` |
| Docs (Claude) | New ADR 0046 "Campaign transfer"; glossary row "Campaign transfer"; ADR 0017 §7 and `docs/vision.md` open-question row point to it |

## Design

### Backend (slice 1)

- Domain (`backend/src/Play/Domain/Campaign/`): `Campaign::transfer(CampaignId $id, string $name, <latest release>, ?string $flowKey, list<CharacterId> $characterIds, \DateTimeImmutable $createdAt): Campaign`. New domain exceptions as needed (e.g. `TransferFromOutdatedRelease`, unknown or duplicate Character reuse the existing ones when they fit). The result passes `assertFactsConsistent()` (promotions, links, subjects).
- Application (`backend/src/Play/Application/`): `TransferCampaign` command and handler, modelled on `CreateCampaign` / `CreateCampaignHandler`: load the source owned by the player (404 otherwise), load the latest release of its GameSystem, compare versions (409), resolve the Flow (`UnknownFlow`), generate the id, add the new campaign.
- HTTP: an action in `CampaignController` (or a sibling controller) with a documentation-only request DTO (`TransferCampaignRequest`, nullable fields without a PHP default), `PlayRequestSchemasTest` updated, `make api` regenerates `openapi.json` and `schema.d.ts`.

### Frontend (slice 2)

- `CampaignPage` header: a "Continue in a new campaign" action next to `ReleaseUpgradeAction`, opening a dialog modelled on `ReleaseUpgradeDialog`: name (prefilled), Flow select (the release's flows plus "Play freely", the release default preselected), one checkbox per Character (all checked). An outdated source shows "Upgrade the release first" instead of the form. On success it navigates to the new campaign.
- e2e: play the heist's crew step, transfer to "Making a Name" with the Character, pick that Character in street zero's `edgerunner` step.

## Constraints

- The source campaign is never modified (no version bump); a test proves it.
- Every slice safe on `main` alone: slice 1's endpoint is unused until slice 2's dialog.
- Pre-review checklist: malformed input fails closed (422, never a 500 or a silent default); every domain error is mapped and has an API test; legacy campaigns (stored before this feature, e.g. with promoted NPCs) transfer correctly.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-campaign-transfer-1-api` | `feat(play): play-campaign-transfer 1/2 transfer api` | T1, T2, T3 | ~1,500 (domain and application ~300, HTTP ~150, tests ~550, generated ~300, docs ~200) |
| 2 | `feat/play-campaign-transfer-2-ui` | `feat(play): play-campaign-transfer 2/2 transfer dialog` | T4, T5, T6, T7, T8 | ~900 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0046 "Campaign transfer" (+ ADR index); glossary row; ADR 0017 §7 and `docs/vision.md` row point to it | delegated writer (slice 1) | [x] | `ead65c20` |
| T2 | 1 | Domain `Campaign::transfer` and application `TransferCampaign` handler, with unit tests (carried lists and ids, tracker reset, FlowRun start or free play, Character subset and promotion clearing, outdated release, unknown flow, unknown or duplicate Character, source unchanged) | delegated writer (slice 1) | [x] | `13525e30` |
| T3 | 1 | `POST /api/campaigns/{id}/transfer`: request DTO, controller action, error mapping, `PlayRequestSchemasTest`, API tests (201 + Location, 404 other player's, 409 outdated, 422 cases, malformed body), Behat scenario in `backend/features/play/`, `make api` | delegated writer (slice 1) | [x] | `1823ba5c`, `08bea9b7` |
| T4 | 2 | Slice 1 review fixes: test a fact whose subject is an NPC promoted to a left-out Character | delegated writer (slice 2) | [x] | |
| T5 | 2 | "Continue in a new campaign" dialog on `CampaignPage`, hook, vitest | delegated writer (slice 2) | [x] | |
| T6 | 2 | e2e heist → "Making a Name" with a carried Character | delegated writer (slice 2) | [x] | |
| T7 | 2 | Chrome pass; fixes | parent Chrome pass + delegated writer (slice 2) | [ ] | |
| T8 | 2 | Closing: Reviews, roadmap `Done:` line under B13 | parent (slice 2) | [ ] | |

## Progress

- 2026-10-29: Exploration (Campaign aggregate, `CreateCampaign`, release upgrade planner, persistence, UI, tests): a Campaign holds NPCs, Threads, facts, Characters and the FlowRun as JSONB in one row; the journal is a separate aggregate. No migration needed. User decisions: trackers reset, upgrade first, Characters picked one by one, all NPCs and Threads, no origin link.
- 2026-10-29 T1: ADR 0046, ADR index, glossary "Campaign transfer", ADR 0017 §7 and `docs/vision.md` row updated; docs only, structural readback.
- 2026-10-29 T2: RED `CampaignTransferTest` (16 tests, `Campaign::transfer()` undefined) then GREEN (16/16); `TransferCampaignHandlerTest` (8) and the repository contract test `aTransferredCampaignIsKeptWhole` (in-memory and Doctrine) passed at once, as the handler is a thin composition of the tested domain method; PHPStan clean.
- 2026-10-29 T3: RED `PlayRequestSchemasTest` + `CampaignTransferApiTest` (17 failures: no route, no schema) then GREEN (37 tests); Behat `campaign-transfer.feature` (3 scenarios) failed once on an unmapped step exception (`TransferFromOutdatedRelease` not caught by the context's `attempt()`), fixed, 3/3; PHPStan clean. Errors have no new `code`: the frontend `campaignError` already surfaces the API message for 404/409/422, so `useCampaigns.ts` is unchanged.
- 2026-10-29 T4: `CampaignTransferTest::aFactWhoseSubjectIsAnNpcPromotedToALeftOutCharacterIsCarriedWithItsSubject` passed at once (17/17, no RED: it pins existing behaviour, no domain bug); the fact keeps its subject, the NPC loses `promotedTo`, the consistency check holds.
- 2026-10-29 T5: `CampaignTransfer.test.tsx` (12 tests) written after the dialog; RED shown by removing the `CampaignPage` action (12/12 failed), GREEN with it (12/12). `PLAY_FREELY` and `MAX_NAME_LENGTH` moved to `useCampaigns.ts` to share them with `NewCampaignForm`. The dialog reads Flows and Characters from the campaign itself (source is on the latest release), and the outdated check reuses `useGameSystems` as `ReleaseUpgradeAction` does.
- 2026-10-29 T6: `play-campaign-transfer.spec.ts` failed twice while written (street zero's step picks a Character with a radio and "Choose", and the cast is shown in Focus mode, not the Party group), then passed (1/1, `make e2e ARGS=play-campaign-transfer`).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-b8398ff26eaaca74` | `ead65c20`..`08bea9b7` (+1,637/−3) | approved; one warning: a fact subject naming a left-out Character would fail the consistency check. Not reachable (subjects are NPC or Thread, `Fact.php:39,67`, NPCs always carry); test added in T4 | [#327](https://github.com/iribarren/ogami/pull/327) |

## Next step

Slice 2: T4–T6 on `feat/play-campaign-transfer-2-ui` (writer), then T7 Chrome pass and T8 closing (parent).
