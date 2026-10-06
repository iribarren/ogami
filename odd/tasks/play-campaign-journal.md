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
| T5 | Play Application journal: `RecordNote`, `RecordRoll`, `RecordOracleTableResult`, `RecordLikelihoodAnswer`, `GetJournal` + views; unit tests; Behat scenarios. Also S2/S3 follow-ups: `ContentData` nullable missing-key test; `CreateCampaign` rejects an existing id (`CampaignAlreadyExists`); `GetCampaign` with an unreadable pinned release surfaces the Play error (test); catalog sort case-insensitive | S4 | delegated writer (multi-file) | [x] | `4209780`, `4085b8c` |
| T6 | Doctrine mappings, repositories, migration (`play_campaign`, `play_session`, `play_scene`, `play_journal_entry` JSONB); integration tests. Also: remove the `services.yaml` handler excludes; implement `JournalEntryRepository::ofId`; S4 follow-ups (record handlers with an unreadable pinned release, test; Play catalog order proven case-insensitive through the Studio-backed adapter, integration test) | S5 | delegated writer (multi-file) | [x] | `7c3f167`, `a5cd8f2` |
| T6b | Fix S5 review follow-ups: `created_at`/`recorded_at` keep microseconds (`TIMESTAMP(6)` / `datetimetz_immutable` precision) so a reloaded entry equals the recorded one; test the unique-violation → `*AlreadyExists` mapping (race path); make the "unsaved change" contract honest for Doctrine (explicit `save` semantics or contract wording + test); fix the `SystemClock` time-zone comment | S6 | delegated writer (multi-file) | [x] | `1c56e4f` |
| T7 | HTTP: catalog, campaigns, sessions, scenes controllers; `SOLO_PLAYER` access control; owner → 404; OpenAPI; `make api`; integration API tests | S6 | delegated writer (multi-file) | [x] | `9cca7b9` |
| T7b | Fix S6 follow-ups: optimistic locking on `play_campaign` (version column) so concurrent start session/scene cannot lose an update → 409; API tests for create-conflict 409 and session-limit 409; Play-scoped timestamptz mapping note; named scene-title length constant in tests | S7 | delegated writer (multi-file) | [x] | `d8dfeed` |
| T8 | HTTP journal: list, notes, rolls, oracle tables, likelihood; error mapping; `make api`; integration API tests | S7 | delegated writer (multi-file) | [x] | `47980d4` |
| T9 | SPA: campaign list + create form on `/play`, route `/play/campaigns/$campaignId` shell, query hooks; Vitest + stories. Also S7 follow-ups (separate `fix(play)` commit): request DTO optional fields (`chaosFactor`, `question`) not required in the generated TS; API test for a chaos factor sent to an oracle without chaos → 422 | S8 | delegated writer (multi-file) | [x] | `b769ac4`, `dca6c5f` |
| T10 | SPA Play screen: session/scene controls, journal by session and scene, note composer, dice roller and oracle panel on campaign endpoints; remove sample oracles; Vitest + stories; e2e. Also: move the free dice/oracle section off `/play` (update `e2e/dice.spec.ts`, `e2e/oracles.spec.ts`); `make e2e-seed` publishes the presets so CI's campaign e2e has a catalog; `roleGuards.test.tsx` handlers for the new `/play` requests; S8 follow-ups (campaign page shows either error or data, generic error test, `StoryProviders` re-renders children) | S9 | delegated writer (multi-file) | [x] | `8e78af4`, `264a25a` |

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
- **T5**: delegated writer (multi-file trigger). Follow-ups (`4209780`): `CampaignAlreadyExists` / `JournalEntryAlreadyExists` (handler checks `ofId` first; in-memory repos throw them), new `JournalEntryRepository::ofId` (**T6 must implement**), `ContentData` missing-key tests (locked existing behaviour), `GetCampaign` unreadable pinned release tests, catalog sort `mb_strtolower(name)` then key. Journal (`4085b8c`): port `JournalEntryIdGenerator` + UUID v7 adapter; `RecordNote`, `RecordRoll`, `RecordOracleTableResult`, `RecordLikelihoodAnswer` via shared `CampaignJournal` (owner check → pinned snapshot → roll → record; failures record nothing); queries `GetJournal`, `GetJournalEntry` (for the 201 read-back; `JournalEntryNotFound` → 404 in T8); Behat `journal.feature` (3 scenarios). Randomness errors: `InvalidDiceExpression`, `Oracle\InvalidLikelihoodOracle`. Six journal handlers also excluded in `services.yaml` until T6. RED 325 / 2 errors + 4 failures → GREEN 325; RED 356 / 31 errors → GREEN `OK (356 tests, 938 assertions)`; Behat play RED 3 undefined → 10 passed (parent re-ran both). `make backend-qa` exit 0 (PHPStan OK); `make backend-test` exit 0 (805 tests, Behat 44 scenarios). +1,566 lines (~820 tests).
- **T6**: delegated writer (multi-file trigger). `play_campaign` (sessions + scenes as one JSONB column via custom type `CampaignSessionsType`: immutable VOs always loaded/saved with the campaign, so no join tables) and `play_journal_entry` (content JSONB via `JournalEntryContentType`; FK to `play_campaign` added by schema listener `JournalEntryCampaignForeignKey` since the entity holds the campaign id as a value). `JournalEntry` now stores ids as strings (Doctrine 3.7 cannot use VO ids); `Scene::reconstitute()`. Repositories: lookup-first duplicate check + PK-violation mapping to `*AlreadyExists`; malformed UUID → null/empty. Migration `Version20261006060533` (indexes owner+created, campaign+recorded; Doctrine also adds the single-column FK index). 11 handler excludes removed from `services.yaml`. Contract traits run against in-memory and Doctrine; end-to-end bus test (publish → create → session/scene → note, roll, table, likelihood → journal). S4 follow-ups tested (passed first run: locked existing behaviour). RED integration 31 / 23 errors → GREEN `OK (31 tests, 280 assertions)` (parent re-ran; `schema:validate` OK, in sync). `make backend-qa` exit 0 (PHPStan OK); `make backend-test` exit 0 (841 tests, Behat 44). Dev and test DBs migrated. +1,342 / −166.
- **T6b**: delegated writer (same S6 worker). Custom DBAL type `datetimetz_immutable_microseconds` (`TIMESTAMP(6) WITH TIME ZONE`, writes `Y-m-d H:i:s.uO`) registered in `doctrine.yaml` (user-approved surface) + `mapping_types: timestamptz` so `schema:validate` stays in sync; hand-written migration `Version20261006120000` (schema tool cannot see precision; two tests check `datetime_precision = 6`). Race tests via `preFlush` listener inserting the id through DBAL (passed first run; mutation-checked). Contract narrowed: only a saved change is guaranteed (ORM 3 has no `flush($entity)`), Doctrine-only test documents flush-all. `SystemClock` comment fixed. RED 77 / 2 failures → GREEN 77. Verified alone (T7 stashed): `make qa` exit 0, `make test` exit 0 (846 tests).
- **T7**: delegated writer (multi-file trigger). `GameSystemController`, `CampaignController` (6 endpoints, 10 response + 2 request DTOs, full OpenAPI); errors 400/415/422/404/409; unreadable pinned release → 500 (data corruption). Access: `security.yaml` `^/api/(campaigns|play)(/|$)` → `ROLE_SOLO_PLAYER` (401 anonymous, 403 other roles). Current user via new `Identity\Application\AuthenticatedUser` (`id()`), implemented by `SecurityUser` (user-approved surface; keeps PHPat green). `AuthApiTest` example path switched to `/api/rolls` + asserts the new rules (user-approved). `make api` regenerated `openapi.json` + `schema.d.ts`. RED 41/41 failures → GREEN 41 (parent re-ran). `make qa` exit 0 (PHPStan OK, api-check up to date); `make test` exit 0 (887 tests, Behat 44, Vitest 70). +2,617 (1,357 generated).
- **T7b**: delegated writer (multi-file trigger). `Campaign` `private int $version` + `version()` (Rector removes an unread private property), Doctrine `version="true"`, migration `Version20261006130000`; `CampaignModifiedConcurrently` (Play Domain) from `OptimisticLockException` → 409; contract test with a second `EntityManager` on the same connection (in-memory mimics via a version map); API 409 tests (concurrency via `preFlush` version bump; create conflict and session limit passed at RED: locked existing behaviour); `Scene::MAX_TITLE_LENGTH + 1`; `doctrine.yaml` comment (timestamptz mapping is app-wide). RED 268 / 4 failures → GREEN 269. `make qa` exit 0; `make test` exit 0 (896 tests).
- **T8**: delegated writer (multi-file trigger). `JournalController` (5 endpoints; shared `ReadsJsonBodies` trait with `CampaignController`); `JournalEntryResponse` with `content` as `oneOf` + discriminator on `kind` (`NoteContentResponse`, `RollContentResponse`, `OracleTableContentResponse`, `LikelihoodContentResponse`; nested schemas prefixed `Journal…` to avoid Randomness name clashes) → TS narrows on `content.kind`; dates `DATE_ATOM` like T7 (no microseconds in the API); 201 body via `GetJournalEntry`, no `Location` (no single-entry GET). Test doubles `RescriptableRandomNumberGenerator`, `UuidSequenceJournalEntryIdGenerator` (test container cannot replace used services). RED 328 / 59 errors → GREEN `OK (328 tests, 1599 assertions)` (parent re-ran HTTP: 104 OK). `make qa` exit 0; `make test` exit 0 (955 tests, Behat 44, Vitest 70). +3,254/−299 (2,071 generated).
- **T9**: delegated writer (multi-file trigger). S7 follow-ups (`b769ac4`): PHP `= null` defaults on `RecordLikelihoodAnswerRequest` made Nelmio emit `default: null` → required TS fields; removed (now `chaosFactor?`, `question?`); `PlayRequestSchemasTest` guards all five Play request schemas; no-chaos → 422 API test (passed first run). SPA (`dca6c5f`): `routes/play.tsx` is a layout route with the `SOLO_PLAYER` guard; `play.index.tsx` (`/play`: campaign list + "New campaign" form; dice/oracles kept below with a "not saved" note), `play.campaigns.$campaignId.tsx` (shell: name, "GameSystem vN", sessions, current session/scene, back link, 404 state); hooks `useCampaigns`, `useGameSystems`, `useCampaign` (no retry on 404), `useCreateCampaign` (caches, invalidates list, navigates); `CampaignError`; stories (`PlayHomePage`, `CampaignPage`) via new `src/test/StoryProviders.tsx`; e2e `campaigns.spec.ts`. Vitest src/play RED 34 / 14 failures → GREEN 34 (parent re-ran); full Vitest 84. `make qa` exit 0; `make test` exit 0 (961 tests); `make storybook-build` exit 0; `make e2e` 9 passed (after `make presets` on dev DB). **Gap:** `make e2e-seed` does not publish presets, so CI's campaign e2e would fail → T10.
- **T10**: delegated writer (multi-file trigger). Fixes (`8e78af4`): campaign page switches on `query.status` (TanStack Query v5 keeps stale `data` next to `error`); `StoryProviders` reads children from context; `make e2e-seed` depends on `presets` (idempotent, README updated); `roleGuards.test.tsx` handlers for `/play` requests. Play screen (`264a25a`): `CampaignPage` (header, `SessionControls`, Journal + `NoteComposer`, `DiceRoller` via `useRecordRoll`, `OraclePanel` from the pinned release; tools disabled without a scene); `journal/` (`useJournal` + record hooks appending to the cache, `groupJournal`, `Journal`, `JournalEntryView` narrowing on `content.kind`, `NoteComposer`); `dice/DiceGroups` shared; new `shared/ui/textarea`, `native-select`; removed `sampleOracles.ts`, `useOracles.ts`, `useRollDice.ts` and the unsaved section on `/play`. e2e: `dice.spec.ts` + `oracles.spec.ts` replaced by `play-session.spec.ts` (free-journal session end to end, reload keeps 4 entries in order). RED commit 1: 2 failed / 6; commit 2: 26 failed / 43 → GREEN 78/78 (parent re-ran). `make qa` exit 0; `make test` exit 0 (961 PHPUnit, Behat, Vitest); `make storybook-build` exit 0; `make e2e` 8 passed; `make e2e-seed` ×2 idempotent. +1,963/−1,008 (~1,100 tests, stories, fixtures).
- **Closure:** all tasks done; T10 writer ran `make qa`, `make test`, `make e2e` on the final HEAD `264a25a` (all exit 0); parent re-ran Vitest Play/app (78 passed).
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
- S4 `R3-pinned-release-unreadable-on-record-untested` (SUGGESTION): record handlers with an unreadable pinned release untested. **Fix in T6**.
- S4 `R3-play-catalog-case-insensitive-unproved` (WARNING): Play's `latest()` order is only documented; nothing proves the Studio-backed adapter returns it case-insensitively. **Fix in T6** (integration test).
- S5 `R3-recorded-at-second-precision-drift` (WARNING): `TIMESTAMP(0)` truncates `recorded_at`/`created_at`, so a reloaded entry differs from the recorded one. **Fix in T6b**.
- S5 `R3-unique-violation-mapping-untested` (SUGGESTION): PK-violation → `CampaignAlreadyExists` path untested. **Fix in T6b**.
- S5 `R3-unsaved-change-flushed-by-other-add` (WARNING): with Doctrine, an unsaved campaign change is flushed by another repository write, so the contract's "unsaved change invisible" does not hold there. **Fix in T6b**.
- S6 `R4-concurrent-scene-session-lost-update` (WARNING): two concurrent start session/scene requests can lose an update (load → save, no version check). **Fix in T7b** (optimistic lock → 409).
- S6 `R3-create-conflict-409-untested`, `R3-session-limit-409-untested` (SUGGESTION): 409 paths untested at HTTP level. **Fix in T7b**.
- S6 `R4-create-campaign-not-retry-safe` (SUGGESTION): a retried create makes a second campaign (server-generated id). Accepted for now: the SPA disables the button while pending; idempotency keys are out of scope.
- S6 `R2-global-timestamptz-mapping-play-owned` (SUGGESTION): global `mapping_types: timestamptz` lives in shared Doctrine config but serves Play. **T7b**: comment it as app-wide.
- S6 `R2-scene-title-magic-length` (SUGGESTION): `str_repeat(…, 101)` in tests. **T7b**.
- S6 `R2-review-log-misattributed-sentence` (SUGGESTION): S3 note sat on the S5 line. Fixed in this doc.
- S7 `R3-inmemory-foreign-copy-error-drift`, `R3-inmemory-version-accessor-drift` (SUGGESTION): in-memory fake's error/version behaviour can drift from Doctrine's. Accepted: covered by the shared contract tests; revisit if they diverge.
- S7 `R3-generated-required-optional-fields` (SUGGESTION): `chaosFactor`/`question` are required (nullable) in the generated TS request type. **Fix in T9**.
- S7 `R3-no-chaos-422-unproved` (SUGGESTION): chaos factor for an oracle without chaos → 422 untested at HTTP. **Fix in T9**.
- S7 `R3-post-commit-404` (SUGGESTION): if the 201 read-back query fails after the command committed, the client gets 404 for a recorded entry. Accepted: the read-back runs in the same request right after the commit; no realistic path.
- S8 `R3-campaign-page-error-and-data-both-render` (SUGGESTION): campaign page can render an error and stale data together. **Fix in T10**.
- S8 `R3-campaign-page-generic-error-unproved` (SUGGESTION): non-404 error state untested. **Fix in T10**.
- S8 `R3-story-providers-stale-children` (SUGGESTION): `StoryProviders` memoizes the router and keeps stale children. **Fix in T10**.
- S9 `R3-journal-append-race` (SUGGESTION): a record hook appends to the cached journal while a journal refetch may be in flight, so the entry can be lost until the next refetch or appear twice. Open follow-up: cancel in-flight journal queries before appending and dedupe by id.

## Reviews

- `ff23856..9cc3794` (S1: T1 + T2): medium, consent granted → **approved** (reliability lens; 4 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `9cc3794`. `787efa6` (doc progress): low, closed without review.
- `9cc3794..dd4a9f1` (S2: docs + T2b + T3): medium, consent granted → **approved** (reliability lens; 2 non-blocking suggestions; acknowledged, authority burned). Reviewed boundary: `dd4a9f1`.
- `dd4a9f1..e09925b` (S3: docs + T4): medium, consent granted → **approved** (reliability lens; 3 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `e09925b`. A whole-branch candidate raised by the stop hook was granted but went stale before START (T4 committed meanwhile).
- `e09925b..4085b8c` (S4: docs + follow-ups + T5): medium, consent granted → **approved** (reliability lens; 2 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `4085b8c`.
- `4085b8c..a5cd8f2` (S5: docs + T6): medium, consent granted → **approved** (reliability lens; 3 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `a5cd8f2`.
- `a5cd8f2..9cca7b9` (S6: docs + T6b + T7): **high** (security.yaml), consent granted → four lenses (risk, resilience, readability, reliability) → **approved** (7 non-blocking findings; acknowledged, authority burned). Reviewed boundary: `9cca7b9`.
- `9cca7b9..47980d4` (S7) as one candidate (3,934 lines, ~2,071 generated): consent granted → START stopped `lens_context_budget_exceeded` (no authority). Split: `9cca7b9..d8dfeed` (doc + T7b, 381 lines, medium) granted → **approved** (2 suggestions; acknowledged); `d8dfeed..47980d4` (T8, 3,553 lines, medium) granted → **approved** (3 suggestions; acknowledged). Reviewed boundary: `47980d4`.
- `47980d4..dca6c5f` (S8: doc + S7 fixes + T9): medium, consent granted → **approved** (3 suggestions; acknowledged). Reviewed boundary: `dca6c5f`.
- `dca6c5f..264a25a` (S9: doc + S8 fixes + T10): medium, consent granted → **approved** (1 suggestion; acknowledged). Reviewed boundary: `264a25a`. Every work-unit commit of the feature is now covered by an approved review. A fourth whole-branch stop-hook candidate (13,971 lines, high) was granted and went stale before START (T9 committed meanwhile). A third whole-branch stop-hook candidate (`ff23856..9cca7b9`, 10,328 lines, high) was granted; START stopped with `lens_context_budget_exceeded` (terminal; no authority created) — the per-slice reviews S1–S6 cover that range. A second whole-branch stop-hook candidate was granted and went stale before START (T6 committed meanwhile).

## Next step

Delivery (user decision): push the tracker and slice branches, open the draft tracker PR (`feat/play-campaign-journal` → `main`, `Closes #18`) and slice PRs S1→tracker, S2→S1 … S9→S8; merge commits only.
