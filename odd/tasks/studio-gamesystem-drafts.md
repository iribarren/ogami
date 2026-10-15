# Feature: studio-gamesystem-drafts

- **Locator:** `odd/tasks/studio-gamesystem-drafts.md` · Engram topic `odd/studio-gamesystem-drafts/tasks`
- **Issue:** #131 · **Branch:** `feat/studio-gamesystem-drafts-1-model` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-real-system` (PRs #123–#130)

## Objective

Roadmap feature 14 (milestone M4, first Studio feature). A GAME_MANAGER creates GameSystem drafts in Studio (blank or copied from a published release), edits their metadata, validates them, publishes a draft as a new immutable GameSystem release through the existing release publisher, and browses the version history. The Studio shell gets a UX at Play quality ([vision](../../docs/vision.md), [ADR 0013](../../docs/adr/0013-studio-is-a-core-context.md)).

Out of scope: section editors (oracles: feature 15, flows: 18, sheet: 16, checks: 17), the library (18b), JSON import/export of drafts, schema conversion v1 → v2, campaign upgrade to a newer release, collecting every validation error at once (the validator stays fail-fast).

## Problem and why

- Every release today comes from a hand-written JSON file published by `app:gamesystem:publish` (`make presets`). Studio has no HTTP API and a placeholder page.
- The glossary already names a GameSystem a "mutable draft" and ADR 0010 says editing changes only the draft, but no draft exists in code. Every later Studio editor (features 15–18b) edits a draft, so this feature lays the model, the API and the shell they build on.

## Decisions

| Question | Decision |
|---|---|
| Who sees a draft (user, 2026-10-15) | **Only its creator.** A GameSystem belongs to the GAME_MANAGER who created it; other users get 404 on it (never 403, so a key's owner is not revealed). Its releases stay public to Play as today |
| Starting points (user, 2026-10-15) | A **blank** draft (schema version 2, empty sections) or a **copy of a published release** (any key, latest or a chosen version) under a new key and name. No JSON import/export in this feature |
| Presets (user, 2026-10-15) | **Presets stay file-managed.** A draft key must not be used by another GameSystem or by any existing release (so preset keys are refused); a game manager copies a preset under a new key instead. `app:gamesystem:publish` refuses a key owned by a Studio GameSystem (slice 3), so `make presets` never writes into a Studio line |
| Aggregate (Claude) | Studio aggregate `GameSystem` (the draft): id, key, owner (Identity user id), the draft **document** (a release document: `schemaVersion`, `gameSystem`, `theme`, sections), created/updated timestamps, optimistic lock version (concurrent save → 409, as Play's Campaign). Table `studio_gamesystem`, unique key |
| Key (Claude) | Fixed at creation: it names the release line. Same format as the release contract's `gameSystem.key` |
| Draft content (Claude) | Stored as given; a draft may be invalid while work is in progress. Metadata edits are checked field by field in the domain (the contract's limits) and are all-or-nothing. Sections are copied verbatim from a source release and are only changed by later editors |
| Metadata (Claude) | `name`, `description`, `credits {notice, license?, url?}` and `theme` (one of the contract's themes or none). A copied schema version 1 release keeps version 1; `credits` and `theme` are refused there (422), since v1 has neither |
| Validation (Claude) | `Validate` runs the publisher's own check (`ReleaseContent::fromArray` and the v2 authoring warnings) on the draft document: `{valid, error: {message} \| null, warnings: []}`. The validator stays fail-fast, so it reports the first error with its path |
| Publish (Claude) | Publishing builds the release from the draft document and goes through the existing publisher (`PublishGameSystemRelease` handler: next version per key, unique (key, version), content hash). Refused when invalid (422 with the error), when the draft equals the latest release (409 "no changes"), and on a version race (409). Optional **release notes** (≤2,000 chars) are stored on the release row, outside the Published Language and its hash; console releases have none |
| Version history (Claude) | Releases of the GameSystem, newest first: version, schema version, published at, notes, short content hash. A release can be viewed read-only (section counts) and **restored into the draft** (replaces the draft document; the key and owner stay) |
| Unpublished changes (Claude) | A GameSystem view carries `hasUnpublishedChanges`: true when it has no release, when the draft is invalid, or when the draft's content hash differs from the latest release's |
| Discard (Claude) | A never-published draft can be deleted; one with releases cannot (409), since campaigns may pin them |
| API (Claude) | `/api/studio/game-systems` (list mine, create), `/{key}` (get, delete), `/{key}/metadata` (PUT), `/{key}/validation` (GET), `/{key}/releases` (GET history, POST publish), `/{key}/releases/{version}` (GET), `/{key}/restore` (POST `{version}`). `security.yaml`: `^/api/studio` requires `ROLE_GAME_MANAGER` (roles have no hierarchy; OWNER does not get Studio, ADR 0006) |
| Studio shell (Claude) | `/studio` becomes a layout route: Studio home lists my GameSystems (cards: name, key, latest version, unpublished-changes badge) with "New GameSystem" (blank or copy). A GameSystem page (wide layout like Play's campaign page): header with name, key and status, tabs Overview (metadata form), Validation, Releases; the later editors' sections are listed with their content counts and "editor coming". Publish dialog: validation result, warnings, the version it will create, notes. Same tokens, `shared/ui` components and panel patterns as Play; Storybook stories for the new components |

## Design

### Backend (slices 1–3)

- `Studio/Domain/GameSystem/`: `GameSystem` aggregate, `GameSystemId`, `GameSystemKey` (or reuse of the contract key rule), metadata value object, `GameSystemRepository` port, domain errors (`GameSystemNotFound`, `GameSystemKeyTaken`, `GameSystemModifiedConcurrently`, `InvalidGameSystemMetadata`, `GameSystemHasReleases`).
- Application: commands `CreateGameSystem` (blank or from a release key/version), `EditGameSystemMetadata`, `DiscardGameSystem`, `PublishGameSystemDraft`, `RestoreGameSystemRelease`; queries `ListMyGameSystems`, `GetGameSystem`, `ValidateGameSystemDraft`, `ListGameSystemReleases`, `GetGameSystemRelease`. Every command and query takes the acting user's id; another owner's GameSystem is "not found".
- Publish reuses the release publisher (the existing handler or its extracted service), never a second release writer.
- Persistence: Doctrine XML mapping, hand-written migration, Doctrine `version` column; release notes as a nullable column on `studio_gamesystem_release`.
- Behat: `features/studio/` scenarios for create, copy, edit, validate, publish, history, restore, discard on the in-memory fakes.

### Frontend (slices 4–5)

- `frontend/src/routes/studio.tsx` becomes a layout route with `studio.index.tsx` and `studio.game-systems.$key.tsx`; `frontend/src/studio/` holds hooks (`useGameSystems.ts`, typed client, TanStack Query keys `['studio', …]`), pages and components.
- Play's patterns: page header (`font-heading text-page-title`), wide layout `staticData`, panel cards, `explainedRefusals` error handling, Vitest per component, stories next to components.

## Constraints

- Studio Domain is framework-free; Play never reads drafts, only releases through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [context map](../../docs/domain/context-map.md)). The release contract (schema, hash) does not change.
- `make api` after endpoint changes; commit `openapi.json` and `schema.d.ts`.
- Every slice safe on `main` alone: slices 1–3 add backend nobody calls from the UI; slice 4 replaces the Studio placeholder with a working list and draft page; publish UI lands in slice 5.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-gamesystem-drafts-1-model` | `feat(studio): studio-gamesystem-drafts 1/5 draft model` | T1, T2, T3 | ~1,500 |
| 2 | `feat/studio-gamesystem-drafts-2-draft-api` | `feat(studio): studio-gamesystem-drafts 2/5 draft API` | T4 | ~1,500 incl. ~450 generated |
| 3 | `feat/studio-gamesystem-drafts-3-publish-api` | `feat(studio): studio-gamesystem-drafts 3/5 publish and history API` | T5, T6 | ~1,500 incl. ~400 generated |
| 4 | `feat/studio-gamesystem-drafts-4-studio-shell` | `feat(studio): studio-gamesystem-drafts 4/5 studio shell and drafts UI` | T7, T8 | ~1,600 |
| 5 | `feat/studio-gamesystem-drafts-5-publish-ui` | `feat(studio): studio-gamesystem-drafts 5/5 publish and history UI` | T9, T10, T11 | ~1,400 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0028 (Studio GameSystem drafts: owner-only, starting points, file-managed presets, key rule, validation and publish from a draft, release notes, restore) and ADR index row; glossary (GameSystem draft, release notes, restore); context map Studio section; roadmap feature 14 in progress | delegated writer (slice 1) | [x] | |
| T2 | 1 | Domain and persistence: `GameSystem` aggregate (create blank / from a release document, edit metadata, restore document), value objects, errors, repository port, Doctrine mapping and migration, in-memory repository for tests; unit tests and an integration round trip of the whole aggregate (incl. the 409 on a concurrent save) | | [ ] | |
| T3 | 1 | Application: `CreateGameSystem` (blank or copy of a release, key taken by a GameSystem or any release → refused), `EditGameSystemMetadata`, `DiscardGameSystem` (refused with releases), `ListMyGameSystems`, `GetGameSystem` (with `hasUnpublishedChanges`), `ValidateGameSystemDraft`; owner scoping; handler unit tests; Behat scenarios | | [ ] | |
| T4 | 2 | HTTP: Studio controller(s) for list, create, get, metadata, discard, validation; `^/api/studio` → `ROLE_GAME_MANAGER`; every domain error mapped (400/401/403/404/409/415/422) with API tests incl. another owner's key (404) and a SOLO_PLAYER (403); `make api` | | [ ] | |
| T5 | 3 | Publish from a draft through the release publisher: `PublishGameSystemDraft` (invalid 422, no changes 409, version race 409), release notes column (migration, nullable), console publish refuses a Studio-owned key; history queries `ListGameSystemReleases`, `GetGameSystemRelease`; `RestoreGameSystemRelease`; unit and Behat | | [ ] | |
| T6 | 3 | HTTP for publish, history, release view and restore; API tests; `make api` | | [ ] | |
| T7 | 4 | Studio shell: `/studio` layout route, Studio home listing my GameSystems (cards, empty state), "New GameSystem" dialog (blank or copy of a published release: key, name, source key and version); hooks; Vitest; stories | | [ ] | |
| T8 | 4 | GameSystem page: header and status, tabs Overview (metadata form with credits and theme; v1 hides them), Validation (error with path, warnings), section summary with counts; discard for a never-published draft; Vitest; stories | | [ ] | |
| T9 | 5 | Publish dialog: validation result, warnings, next version, notes; refusals explained (invalid, no changes, race) | | [ ] | |
| T10 | 5 | Releases tab: history list, read-only release view, restore into the draft with confirmation; Vitest; stories | | [ ] | |
| T11 | 5 | e2e: a GAME_MANAGER copies a preset, edits metadata, publishes, and a SOLO_PLAYER can start a campaign on it; wrap-up: roadmap 14 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-15: Exploration done (release publisher and validator, release table, Studio read side and Play ACL, Play HTTP/persistence/frontend patterns, role guards, Studio Behat context). Three decisions taken with the user (owner-only drafts, blank or copy, presets stay file-managed). Issue #131.
- 2026-10-15: T1 docs written (ADR 0028, index, glossary, context map, roadmap). Doc-only: no tests, structural readback only.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T1–T3 by one delegated writer on `feat/studio-gamesystem-drafts-1-model`.
