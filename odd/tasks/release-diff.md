# Feature: release-diff

- **Locator:** `odd/tasks/release-diff.md` · Engram topic `odd/release-diff/tasks`
- **Issue:** #268 · **Branch:** `feat/release-diff-1-changes-panel` · **Status:** in progress: slice 1
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7j (follow-up of `release-api-followups`, #255)

## Objective

In Studio, the release detail shows what changed against the previous release: per section, the items added, removed and changed, and whether the kept items changed order. Read-only, no new persistence.

## Problem and why

- A manager reading release N sees its outline ([ADR 0038](../../docs/adr/0038-studio-release-view.md)) but not what it changed. Comparing two outlines by eye misses content edits entirely.
- `GetGameSystemReleaseHandler` (`backend/src/Studio/Application/GetGameSystemReleaseHandler.php`) loads one release and returns `GameSystemReleaseView` (summary, name, `SectionCounts::of`, `ReleaseOutline::of`). Both release rows store their full content (`GameSystemRelease::content()`), so the diff can be computed from version N and N-1 with no new persistence.
- `ReleaseOutline` (`backend/src/Studio/Application/ReleaseOutline.php`) already knows each section's path and display-name field for schema v1 and v2. The diff reuses that knowledge.
- Frontend: `ReleaseView` in `frontend/src/studio/ReleasesTab.tsx:186` renders `ReleaseOutline.tsx`. Section labels come from `sectionLabels.ts`.
- Studio never reads Play, so no campaign counts.

## Decisions (approved by the user 2026-10-24)

| Item | Decision |
|---|---|
| Changed item | Name and key, plus the top-level properties of the stored item that differ (e.g. `dice, bands`). A renamed item shows `old name → new name`. |
| Layout | A separate "Changes since version N-1" panel above the outline, listing only sections with changes; one line counts the unchanged sections. The outline stays as it is. |
| Reorder | Items are not marked changed for moving. A section whose kept items changed relative order shows "Order changed". |
| Previous release | Always version N-1 (versions are consecutive). Version 1 (or a missing N-1) has no comparison: the panel says it is the first release. |
| Schema change | When N-1 has another schema version, the sections differ; no item comparison, only a note naming both schema versions. |

## Contract (ADR 0039)

The release detail (`GET /api/studio/game-systems/{key}/releases/{version}`) gains a required, nullable `changes`:

- `null` when there is no previous release.
- Otherwise `{previousVersion, previousSchemaVersion, sections}`. `sections` is `null` when `previousSchemaVersion` differs from the release's `schemaVersion`; otherwise it is keyed by every section name of `sectionCounts`/`outline`, each `{added, removed, changed, reordered}`:
  - `added`: outline items (`{key, name}`) in this release's content order whose key the previous release lacks.
  - `removed`: outline items of the previous release, in its content order, whose key this release lacks.
  - `changed`: `{key, name, previousName, fields}` in this release's content order for keys in both whose stored items differ. `name`/`previousName` are the display names (null when none). `fields` lists the top-level property names whose values differ, a property present in only one of them included, sorted alphabetically (JSONB does not keep property order).
  - `reordered`: true when the keys present in both appear in a different relative order.
- Items are matched by the outline's key (an item without a string key has key `''`, as in the outline). Stored content is not trusted: when a key repeats in a section, its first occurrence counts. Values are compared canonically (map keys sorted recursively, list order kept), so a JSONB key reorder is not a change.
- Only the outline's sections are compared. GameSystem metadata and properties outside the sections are out.
- Additive: every existing field keeps its name and meaning.

## Constraints

- Additive REST change only. No new persistence, no migration.
- Studio never reads Play.
- Planning heuristic: slice ceiling ~1,700 changed lines, including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/release-diff-1-changes-panel` | `feat(studio): release-diff 1/1 changes since the previous release` | T1–T4 | ~1,500: feature doc and ADR ~200, `ReleaseDiff` and its unit tests ~450, handler/view/response and API tests ~200, generated OpenAPI and TS ~200, Changes panel with tests, fixtures and story ~450 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; ADR 0039 (plus the README index) | parent, inline (feature doc); writer (delegated: ADR) | [x] | |
| T2 | 1 | `ReleaseDiff` (Application, next to `ReleaseOutline`); the release detail gains `changes`; unit and API tests; generated OpenAPI and TS in their own commit | writer (delegated) | [x] | |
| T3 | 1 | `ReleaseChanges` panel in `ReleaseView`, above the outline; tests, fixtures, story | writer (delegated) | [x] | |
| T4 | 1 | Review fixes, if any; closing doc commit | writer (delegated) | [ ] | |

## Acceptance criteria

- Version 1 has `changes: null`, and the panel says it is the first release.
- A schema change between N-1 and N gives `sections: null`, and the panel names both schema versions.
- For schema v1 and v2 alike, `added`, `removed`, `changed` and `reordered` match the stored contents, with unit tests per case: add, remove, change of one property, rename (`previousName`), property only in one item, JSONB key order not a change, pure reorder, duplicate keys, items without keys.
- The API test reads `changes` of version 2 after publishing two drafts.
- The Studio release view shows the Changes panel above the outline: only sections with changes, `+`/`−`/`~` items with accessible labels, the changed properties, "Order changed", and a count of unchanged sections.
- ADR 0039 is written and indexed.

## Checks

`make verify` once per slice. CI runs the full suites.

## Progress

- 2026-10-24: Explored the release detail (backend handler, `ReleaseOutline`, `ReleaseView`). The user chose how changes show (changed properties, separate panel, order note). Issue #268 and this feature doc written.
- 2026-10-24: T1 done: ADR 0039 written and indexed; the feature doc committed with it.
- 2026-10-24: T2 done: `ReleaseDiff` (shares `ReleaseOutline::entries`), `changes` in the release detail and its response classes, unit and API tests; OpenAPI and TS regenerated in the next commit.
- 2026-10-24: T3 done: `ReleaseChanges` panel above the outline, fixtures, tests and a story.

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|

## Next step

Slice 1: T1–T3 by one writer, then native review, push and PR.
