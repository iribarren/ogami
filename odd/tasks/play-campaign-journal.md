# Feature: play-campaign-journal

- **Locator:** `odd/tasks/play-campaign-journal.md` · Engram topic `odd/play-campaign-journal/tasks`
- **Issue:** #18 · **Tracker branch:** `feat/play-campaign-journal` (branch point `ff23856` on `main`)
- **Delivery strategy:** `ask-on-risk` → chain strategy **feature-branch-chain** (user choice). Slice PRs merge into the tracker branch; one final PR from the tracker to `main` (merge commit)
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `gamesystem-release-contract`, delivered in PR #17 (closes #16)

## Objective

Let a solo player play free-form in a campaign. A campaign is created from a published GameSystem release and stays pinned to that release. Play is organized in sessions and scenes. The journal holds notes and the results of rolls and oracle questions. The Play screen shows the journal, a dice roller and the oracles from the campaign's release. ADR 0014 records "campaigns stay pinned to their release" as the default upgrade policy.

## Scope

- **In:** ADR 0014; glossary, context map and roadmap updates; Studio query listing the latest published release of each GameSystem; Play port method for that list; Play Domain `Campaign` (pinned release, sessions, scenes) and `JournalEntry` (note, roll, oracle-table and likelihood content); Play Application commands and queries; Doctrine persistence and migration; REST endpoints restricted to `SOLO_PLAYER` and to the campaign owner; OpenAPI and typed client; SPA campaign list and creation on `/play`; Play screen `/play/campaigns/$campaignId`; Behat `play` suite; e2e.
- **Out:** upgrading a campaign to a newer release (`play-release-upgrade`); notes with hand-picked result attachments (new backlog item `play-journal-attachments`); editing or deleting entries, sessions, scenes or campaigns; campaign chaos factor state (the request carries it); characters, threads, NPCs, FlowRun; journal pagination and search.

## Constraints

- Dependency rule and PHPat: Play Domain is framework-free and may use `Randomness\Domain`. Play reaches Studio only through `App\Studio\Application\*`.
- Play reads releases only through its anti-corruption layer (ADR 0010); it never reads Studio tables.
- Randomness results are produced on the server with the injected `RandomNumberGenerator`, from the pinned release snapshot. The client never sends oracle definitions to campaign endpoints.
- ADR 0007: structured content that varies by kind (journal entry content) is JSONB; identity, ownership, ordering and timestamps are relational columns.
- Commands and queries go through the Messenger buses. Controllers use the generated OpenAPI contract; the SPA uses only the generated typed client (ADR 0005).
- Glossary terms: `Campaign`, `Session`, `Scene`, `JournalEntry`, `GameSystem release`, `GameSystemSnapshot`, `Oracle`.

## Rules

### Campaign

- `Campaign`: id (UUID v7), owner user id, name (trimmed, 1–100 chars), pinned release (`gameSystemKey`, `releaseVersion`, `gameSystemName`), `createdAt`.
- Creating a campaign pins the **latest** release of the chosen GameSystem key at that moment. An unknown key fails with Play's `GameSystemReleaseNotFound`.
- The pinned release never changes in this feature (ADR 0014). Releases are immutable, so the pin stores the (key, version) reference, not a copy of the content. Play re-reads the pinned snapshot through the ACL.
- Only the owner can see or change a campaign. For anyone else it does not exist (not found, no existence leak).
- A player lists their own campaigns, newest first.

### Sessions and scenes

- `startSession(at)`: adds session number `n + 1` (first is 1) with `startedAt`. The new session becomes the current session. It has no scene yet.
- `startScene(title, at)`: requires a current session (else `NoCurrentSession`). Adds scene number `m + 1` within the current session (first is 1), title trimmed 1–100 chars, `startedAt`. It becomes the current scene.
- The current session is the latest session. The current scene is the latest scene of the current session (none right after a session starts).
- At most 500 sessions per campaign and 200 scenes per session (`CampaignLimitReached`).

### Journal entries

- `JournalEntry`: id (UUID v7), campaign id, session number, scene number, `recordedAt`, content. Entries are immutable.
- Every entry is recorded in the campaign's current scene; without one it fails with `NoCurrentScene`.
- The journal of a campaign lists entries in `recordedAt` order, then id order, each with its session and scene numbers.
- Content kinds:
  - `note`: `text` trimmed, 1–10,000 chars.
  - `roll`: the dice expression rolled on the server; stores `expression`, `total`, and groups with each die (same shape as the Randomness roll view).
  - `oracle-table`: the oracle key and name from the pinned release, and the resolution steps (`tableKey`, `tableName`, `dice`, `total`, `text`, `nestedTableKey`).
  - `likelihood`: the oracle key and name, optional `question` (trimmed, ≤ 500 chars), and the answer (`answer`, `roll`, `sides`, `effectiveTarget`, `likelihood`, `likelihoodLabel`, `chaosFactor`).
- Rolls and oracle asks are recorded automatically (user choice): each one runs on the server against the pinned release and appends an entry in the same command. An invalid expression, unknown oracle key, unknown likelihood level or out-of-range chaos factor records nothing and fails with the Randomness or Play error.

### Release catalog

- Studio `ListPublishedGameSystems` → the latest release of each GameSystem key: `gameSystemKey`, `name`, `description`, `version`, `publishedAt`, ordered by name.
- Play port `PublishedGameSystemReleases::latest(): list<GameSystemSummary>` through the same Studio-backed adapter.

### HTTP API (all under `/api`, `SOLO_PLAYER` only, 403 otherwise)

| Method | Path | Does |
|---|---|---|
| GET | `/play/game-systems` | Release catalog for campaign creation |
| GET | `/campaigns` | My campaigns |
| POST | `/campaigns` | Create `{name, gameSystemKey}` → 201 campaign |
| GET | `/campaigns/{id}` | Campaign: pinned release, sessions with scenes, current session and scene, oracles from the release (tables: key, name; likelihood: key, name, levels, chaos range) |
| POST | `/campaigns/{id}/sessions` | Start a session |
| POST | `/campaigns/{id}/scenes` | Start a scene `{title}` |
| GET | `/campaigns/{id}/journal` | Journal entries |
| POST | `/campaigns/{id}/journal/notes` | `{text}` → 201 entry |
| POST | `/campaigns/{id}/journal/rolls` | `{expression}` → 201 entry |
| POST | `/campaigns/{id}/journal/oracle-tables/{key}` | → 201 entry |
| POST | `/campaigns/{id}/journal/likelihood-oracles/{key}` | `{likelihood, chaosFactor?, question?}` → 201 entry |

- Errors use `ErrorResponse`: 422 validation and Randomness errors, 404 unknown or foreign campaign and unknown oracle, 409 `NoCurrentSession` / `NoCurrentScene` / limit reached.

### Play screen

- `/play`: my campaigns (name, GameSystem name and version, created date) and a "New campaign" form (name, GameSystem select from the catalog). Creating opens the campaign.
- `/play/campaigns/$campaignId`: header with name and "GameSystem vN"; session and scene controls (start session, start scene with title); journal grouped by session and scene, each entry rendered by kind; note composer; dice roller and oracle panel built from the release's oracles. Each roll or oracle answer appears in the journal. The hardcoded sample oracles are removed.

## Forecast

~4,300 authored lines: T1 ~250, T2 ~400, T3 ~400, T4 ~500, T5 ~450, T6 ~400, T7 ~500, T8 ~450, T9 ~450, T10 ~550. Above the 400-line budget, so one slice PR per task (S1 = T1 + T2), each into the parent slice branch, the first into the tracker.

## Tasks

| ID | Task | Slice | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | ADR 0014 (campaigns pinned to their release) + README index; ADR 0010 note; context map (open question resolved, relationship row); glossary (pinned release, entry kinds); roadmap: feature 5 settled, backlog item `play-journal-attachments` after all defined features | S1 | delegated writer (multi-file) | [x] | `79850ff` |
| T2 | Play Domain `Campaign`: pinned release, sessions, scenes, limits, errors; repository port + in-memory repository; unit tests | S1 | delegated writer (multi-file) | [x] | `9cc3794` |
| T2b | Fix S1 review follow-ups: reject a blank owner id; in-memory repository stores and returns copies so `save` is observable and the save test proves persistence; test the `ownedBy` same-`createdAt` id tie-break | S2 | delegated writer (multi-file) | [x] | `6ee2b76` |
| T3 | Play Domain `JournalEntry` + content value objects (note, roll, oracle-table, likelihood) built from Randomness results, with `toArray`/`fromArray`; repository port + in-memory; unit tests | S2 | delegated writer (multi-file) | [x] | `dd4a9f1` |
| T4 | Studio `ListPublishedGameSystems`; Play port `latest()` + adapter; Play Application: `CreateCampaign`, `StartSession`, `StartScene`, `ListMyCampaigns`, `GetCampaign` (with release oracles) + views; owner check; unit tests; Behat `play` suite | S3 | delegated writer (multi-file) | [x] | `5790b53`, `e09925b` |
| T5 | Play Application journal: `RecordNote`, `RecordRoll`, `RecordOracleTableResult`, `RecordLikelihoodAnswer`, `GetJournal` + views; unit tests; Behat scenarios. Also S2/S3 follow-ups: `ContentData` nullable missing-key test; `CreateCampaign` rejects an existing id (`CampaignAlreadyExists`); `GetCampaign` with an unreadable pinned release surfaces the Play error (test); catalog sort case-insensitive | S4 | delegated writer (multi-file) | [ ] | |
| T6 | Doctrine mappings, repositories, migration (`play_campaign`, `play_session`, `play_scene`, `play_journal_entry` JSONB); integration tests | S5 | delegated writer (multi-file) | [ ] | |
| T7 | HTTP: catalog, campaigns, sessions, scenes controllers; `SOLO_PLAYER` access control; owner → 404; OpenAPI; `make api`; integration API tests | S6 | delegated writer (multi-file) | [ ] | |
| T8 | HTTP journal: list, notes, rolls, oracle tables, likelihood; error mapping; `make api`; integration API tests | S7 | delegated writer (multi-file) | [ ] | |
| T9 | SPA: campaign list + create form on `/play`, route `/play/campaigns/$campaignId` shell, query hooks; Vitest + stories | S8 | delegated writer (multi-file) | [ ] | |
| T10 | SPA Play screen: session/scene controls, journal by session and scene, note composer, dice roller and oracle panel on campaign endpoints; remove sample oracles; Vitest + stories; e2e | S9 | delegated writer (multi-file) | [ ] | |

## Acceptance criteria

- A player creates a campaign from a published GameSystem and it shows that release version; publishing a newer release does not change the campaign.
- Sessions and scenes number from 1; a scene needs a session; an entry needs a scene.
- Notes, rolls, oracle table results and likelihood answers appear in the journal in order, under their session and scene; failed rolls or asks record nothing.
- Oracles come from the pinned release; an oracle key not in that release is not found.
- Another player gets 404 for my campaign; a non-`SOLO_PLAYER` gets 403 on every Play endpoint.
- The Play screen plays a free-journal session end to end (e2e).
- ADR 0014 accepted; the context map open question is resolved.

## Checks

`make qa`, `make test` per task; `make api` after endpoint changes; `make e2e` at T10 and at closure.

## Progress / Evidence

- **Exploration:** read-only explorer. Play has the ACL only (`PublishedGameSystemReleases::get`, `GameSystemSnapshot`), no aggregates or HTTP. Randomness endpoints are stateless and take full definitions. No release listing query. API `access_control` only requires `IS_AUTHENTICATED`; roles are `ROLE_<VALUE>` with no hierarchy. No Behat `play` suite. Frontend `/play` renders `DiceRoller` + `OraclePanel` with `sampleOracles.ts`. Next ADR 0014.
- **T1**: delegated writer (multi-file trigger). ADR 0014 + README index; ADR 0010 "Settled by ADR 0014"; context map open question resolved, relationship row pins (key, version); glossary (Campaign, Pinned release, Session/Scene numbering, JournalEntry kinds); roadmap Settles link + Backlog section with `play-journal-attachments` and its starter prompt. Passive docs: no RED; structural readback.
- **T2**: delegated writer (multi-file trigger). `Play/Domain/Campaign/`: `Campaign` (create, startSession, startScene, current session/scene, isOwnedBy, `reconstitute`), `CampaignId`, `PinnedRelease`, `Session`/`Scene` immutable VOs identified by number (adding a scene replaces the last `Session`), errors extend `\DomainException` (`InvalidCampaignName`, `InvalidCampaignId`, `InvalidPinnedRelease`, `InvalidSceneTitle`, `NoCurrentSession`, `NoCurrentScene`, `CampaignLimitReached`); port `CampaignRepository` (add, save, ofId, ownedBy newest first); `tests/Support/Play/InMemoryCampaignRepository` (+contract test). RED 33 tests / 24 errors + 9 failures → GREEN `OK (59 tests)` Play unit (parent re-ran: `OK (59 tests, 148 assertions)`). `make backend-qa` exit 0, PHPStan clean (parent re-ran phpstan: exit 0); `make backend-test` exit 0 (687 tests). +1,096 lines (~485 tests), above the 400 heuristic: boundary tests and one-file VOs/errors. T6 note: no Doctrine Collections in Domain (PHPat), so map sessions via reconstitution or a JSONB type.
- **T2b**: delegated writer (same S2 worker). `InvalidCampaignOwner` on blank owner; in-memory repository stores/returns `clone`s (Campaign holds scalars + immutable Session/Scene); tests: unsaved change invisible, save persists, tie-break (passed before fix, locks behaviour). RED 64 tests / 5 failures → GREEN `OK (64 tests, 159 assertions)`. `make backend-qa` exit 0 (PHPStan OK); `make backend-test` exit 0 (692 tests).
- **T3**: delegated writer (multi-file trigger). `Play/Domain/Journal/`: `JournalEntry` (readonly; `record()` takes current session/scene numbers or throws `NoCurrentScene`; `reconstitute()`), `JournalEntryId`, `JournalEntryContent` + `NoteContent`, `RollContent` (`groups[{notation, sides, dice[{value, kept}], subtotal}]`), `OracleTableContent`, `LikelihoodContent` (plain values mirroring the Randomness views), `JournalEntryContents::fromArray` (kind dispatch, typed via internal `ContentData`, re-validates constructor rules), errors `InvalidJournalEntryContent`, `InvalidJournalEntryId`; port `JournalEntryRepository` + in-memory fake. RED 89 tests / 18 errors + 9 failures → GREEN `OK (112 tests, 248 assertions)` (parent re-ran). `make backend-qa` exit 0 (PHPStan OK); `make backend-test` exit 0 (740 tests). +1,483 lines (~621 tests): four kinds with boundary and round-trip tests.
- **T4**: delegated writer (multi-file trigger). Studio `ListPublishedGameSystems` + `PublishedGameSystemSummary` (sorted name, key), port `latestOfEachKey()` (Doctrine: one DQL with `MAX(version)` subquery; description read from canonical content). Play: ports `Clock`, `CampaignIdGenerator` (+ `SystemClock` UTC, `UuidCampaignIdGenerator` v7); `PublishedGameSystemReleases::latest()` + `ListGameSystems`; `CreateCampaign` (caller-generated id, pins latest), `StartSession`, `StartScene`, `ListMyCampaigns`, `GetCampaign` (oracles from the **pinned** version) + views; `CampaignNotFound` (Play Application) via `OwnedCampaigns::get(id, userId)`, same message for unknown/foreign/blank; `GameSystemSnapshot::oracleTableNames()`; Behat `play` suite (7 scenarios). No Randomness gap. **T6 must remove** the `services.yaml` exclude of the five campaign handlers (no `CampaignRepository` adapter yet, container would not compile). RED Studio 184 / 3 errors; Play+Studio 316 / 20 errors → GREEN `OK (316 tests, 855 assertions)` (parent re-ran; parent re-ran Behat play: 7 passed). Integration tests (Doctrine `latestOfEachKey`, Play `ListGameSystems`) written with the implementation, no RED. `make backend-qa` exit 0 (PHPStan OK); `make backend-test` exit 0 (765 tests, Behat 41 scenarios). +1,861 lines.
- **Decisions (user):** results are recorded automatically (attachments go to the backlog, after all defined features, order decided later); feature-branch-chain delivery.

## Follow-ups (non-blocking review findings)

- S1 `R3-blank-owner-accepted` (SUGGESTION): `Campaign::create` accepts a blank owner id. **Fix in T2b**.
- S1 `R3-inmemory-save-unobservable` (WARNING): in-memory repository holds the same instance, so `save` cannot be observed. **Fix in T2b**.
- S1 `R3-save-test-proves-nothing` (WARNING): the save test passes even without `save`. **Fix in T2b**.
- S1 `R3-ownedby-tiebreak-untested` (SUGGESTION): id tie-break for equal `createdAt` untested. **Fix in T2b**.

- S2 `R3-nullable-missing-key-untested` (SUGGESTION): `ContentData` nullable fields with a missing key untested (`ContentData.php:36-38`). **Fix in T5**.
- S2 `R3-rehydration-revalidates-rules` (SUGGESTION): `fromArray` re-runs constructor rules on stored data (`NoteContent.php:44-47`), so a future rule tightening could make old entries unreadable. Open; accepted for now (rules only loosen until a schema change), revisit with T6.
- S3 `R3-catalog-sort-case-sensitive` (SUGGESTION): catalog sorts names case-sensitively. **Fix in T5**.
- S3 `R3-create-duplicate-id-unguarded` (WARNING): `CreateCampaign` with an existing id is not rejected in the handler (the in-memory repo throws a `LogicException`). **Fix in T5** (domain error; T6 maps the unique violation).
- S3 `R3-unreadable-pinned-release-untested` (SUGGESTION): `GetCampaign` when the pinned release cannot be read is untested. **Fix in T5**.

## Reviews

- `ff23856..9cc3794` (S1: T1 + T2): medium, consent granted → **approved** (reliability lens; 4 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `9cc3794`. `787efa6` (doc progress): low, closed without review.
- `9cc3794..dd4a9f1` (S2: docs + T2b + T3): medium, consent granted → **approved** (reliability lens; 2 non-blocking suggestions; acknowledged, authority burned). Reviewed boundary: `dd4a9f1`.
- `dd4a9f1..e09925b` (S3: docs + T4): medium, consent granted → **approved** (reliability lens; 3 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `e09925b`. A whole-branch candidate raised by the stop hook was granted but went stale before START (T4 committed meanwhile).

## Next step

T5 on `feat/play-campaign-journal-s4-journal-app`.
