# Feature: studio-copy-release-picker

- **Locator:** `odd/tasks/studio-copy-release-picker.md` · Engram topic `odd/studio-copy-release-picker/tasks`
- **Issue:** [#414](https://github.com/iribarren/ogami/issues/414) · **Branch:** `feat/studio-copy-release-picker-1-picker` · **Status:** slice 1 implemented, awaiting review
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary

## Objective

Let a game manager pick the release to copy in Studio's **New GameSystem → Copy a release** from a list of published GameSystems, instead of typing a key they cannot discover.

Out of scope: a "Copy" action elsewhere in Studio (a preset catalog page), key suggestions, and any change to the copy command itself.

## Problem and why

- File-managed presets (`backend/presets/`) have no Studio owner, so Studio's home page never lists them (owner-only drafts, [ADR 0028](../../docs/adr/0028-studio-gamesystem-drafts.md)).
- Copying a published release under a new key already works (ADR 0028 starting points), but the dialog asks for the **Source GameSystem key** and **Source version** as free text (`frontend/src/studio/NewGameSystemDialog.tsx:120-148`). Nothing in Studio shows which keys exist.
- Result: a manager who wants a preset as an example cannot find it without reading the repository.

## Evidence

- Studio already has the query: `ListPublishedGameSystems` → `ListPublishedGameSystemsHandler` (`backend/src/Studio/Application/`), latest release of each key via `GameSystemReleaseRepository::latestOfEachKey()`, ordered by name ignoring case, then key. Play reaches it only through its ACL (`StudioPublishedGameSystemReleases`). No Studio HTTP endpoint exposes it.
- `PublishedGameSystemSummary` carries `gameSystemKey`, `name`, `description`, `version` (latest), `publishedAt`, `release` (null when the stored content no longer parses).
- Release versions per key are contiguous from 1 (`ReleasePublisher::publish` takes latest + 1; releases are never deleted), so the latest version is enough to offer every version.
- `security.yaml` already guards `^/api/studio(/|$)` with `ROLE_GAME_MANAGER`.
- Six Playwright specs fill **Source GameSystem key** by label (`frontend/e2e/studio-{check-editor,flow-editor,oracle-editor,publish,sheet-builder,shop-editor}.spec.ts`), plus `StudioHomePage.test.tsx` and the dialog story.

## Decisions

| # | Decision | By |
|---|---|---|
| D1 | New Studio endpoint `GET /api/studio/published-game-systems`, operationId `listPublishedGameSystemsForCopy`, controller in `Studio/Infrastructure/Http`, reusing the existing `ListPublishedGameSystems` query. No new query, no domain change | Parent |
| D2 | Response item: `key`, `name`, `description` (nullable), `latestVersion`, `publishedAt` (ISO 8601). Order kept from the query (name ignoring case, then key) | Parent |
| D3 | Every GameSystem with a release is listed (presets, other managers' and the manager's own): releases are already public to Play, so the list reveals no draft and no owner. Drafts and library packs have no release and never appear | Parent |
| D4 | A summary whose latest release no longer parses (`release === null`) is left out: it cannot be copied | Parent |
| D5 | Dialog: the source key input becomes a `NativeSelect` labelled **Source GameSystem**, options `<name> (<key>)` with the key as value, plus an empty "Choose a GameSystem" option; the version input becomes a `NativeSelect` labelled **Source version** with **Latest (v<N>)** (sends no version) then `v<N>`…`v1` | Parent |
| D6 | Picking a source fills **Name** with `<name> (copy)` only while Name is empty. The key is never prefilled | Parent |
| D7 | States: loading shows a disabled select with "Loading…"; an error shows the studio error text and keeps Create disabled; an empty list says "No GameSystem has a published release yet." and keeps Create disabled | Parent |
| D8 | No ADR: ADR 0028 already allows copying any published release; this adds a read endpoint only | Parent |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/1 | T1, T2 | ~900–1,200 lines: backend ~250, `openapi.json` + `schema.d.ts` ~150, frontend + tests ~450, e2e ~60, manual + doc ~100 | `feat/studio-copy-release-picker-1-picker` |

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Studio endpoint listing published GameSystems for copy (D1–D4), API tests, `make api` | delegated writer (Sonnet): 2+ non-trivial files | [x] | |
| T2 | 1 | Copy a release dialog picks from the list (D5–D7); Vitest, story, e2e specs and the Studio tour chapter updated | delegated writer (Sonnet) | [x] | |

## Acceptance criteria

- `GET /api/studio/published-game-systems` returns every GameSystem with a parseable latest release, presets included; 401 without a session, 403 for a user who is not a game manager.
- The Copy a release form lists those GameSystems; picking one and a version (or Latest) creates the draft copy as before.
- Drafts, library packs and unreadable releases never appear.
- Create stays disabled until a source is picked, and while the list is loading, failed or empty.

## Checks

- Backend: API test class for the endpoint (200 with presets and order, unreadable left out, 401, 403); PHPStan + PHPat via `make verify`.
- Frontend: Vitest for the dialog (list, version options, name prefill, empty/error/loading, request body); Prettier, ESLint, tsc via `make verify`.
- E2E: specs updated to `selectOption`; run by CI.

## Progress

- 2026-11-26: Feature doc created; one slice, two tasks.
- 2026-11-26: T1 done: `GET /api/studio/published-game-systems` with API tests (200 order/fields, never-published and other managers', unreadable left out, empty, 401, 403); `make api` regenerated.
- 2026-11-26: T2 done: Copy a release picks the source and version from native selects fed by the new endpoint; Vitest, story, six e2e specs and the Studio tour updated.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

Review slice 1, then PR.
