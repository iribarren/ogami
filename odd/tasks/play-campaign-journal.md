# Feature: play-campaign-journal

- **Locator:** `odd/tasks/play-campaign-journal.md` · Engram topic `odd/play-campaign-journal/tasks`
- **Issue:** pending · **Tracker branch:** `feat/play-campaign-journal` (branch point `ff23856` on `main`)
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
| T1 | ADR 0014 (campaigns pinned to their release) + README index; ADR 0010 note; context map (open question resolved, relationship row); glossary (pinned release, entry kinds); roadmap: feature 5 settled, backlog item `play-journal-attachments` after all defined features | S1 | delegated writer (multi-file) | [ ] | |
| T2 | Play Domain `Campaign`: pinned release, sessions, scenes, limits, errors; repository port + in-memory repository; unit tests | S1 | delegated writer (multi-file) | [ ] | |
| T3 | Play Domain `JournalEntry` + content value objects (note, roll, oracle-table, likelihood) built from Randomness results, with `toArray`/`fromArray`; repository port + in-memory; unit tests | S2 | delegated writer (multi-file) | [ ] | |
| T4 | Studio `ListPublishedGameSystems`; Play port `latest()` + adapter; Play Application: `CreateCampaign`, `StartSession`, `StartScene`, `ListMyCampaigns`, `GetCampaign` (with release oracles) + views; owner check; unit tests; Behat `play` suite | S3 | delegated writer (multi-file) | [ ] | |
| T5 | Play Application journal: `RecordNote`, `RecordRoll`, `RecordOracleTableResult`, `RecordLikelihoodAnswer`, `GetJournal` + views; unit tests; Behat scenarios | S4 | delegated writer (multi-file) | [ ] | |
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
- **Decisions (user):** results are recorded automatically (attachments go to the backlog, after all defined features, order decided later); feature-branch-chain delivery.

## Reviews

_None yet._

## Next step

T1 + T2 on slice branch `feat/play-campaign-journal-s1-campaign`.
