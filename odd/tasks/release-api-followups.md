# Feature: release-api-followups

- **Locator:** `odd/tasks/release-api-followups.md` · Engram topic `odd/release-api-followups/tasks`
- **Issue:** #255 · **Branch:** `feat/release-api-followups-1-codes-outline` · **Status:** done: slice 1 (PR #266)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7g

## Objective

Decide the four design items of issue #255, write ADRs where they change a contract, build the small ones, and turn the large ones into their own roadmap items. Tick each item in the issue as it lands.

## Problem and why

- **Publish 409.** One `{error}` body covers three causes: `GameSystemDraftUnchanged` (`Studio/Application/PublishGameSystemDraftHandler.php:46`), the version race `GameSystemReleaseAlreadyExists` (`DoctrineGameSystemReleaseRepository.php:40`) and `LibraryPackRefuses` (`PublishGameSystemDraftHandler.php:40`). All are caught in `GameSystemReleaseController.php:121-122`. Restore has the same problem with `GameSystemModifiedConcurrently` and `LibraryPackRefuses` (`:180-181`). `PublishDialog.tsx:44-49` shows one fixed message for every 409 ("…equals the latest release, or another release was published meanwhile"), and that message never covers library packs. Identity already uses `{error, code}`, with the code as an OpenAPI enum (`Identity/Infrastructure/Http/AccountErrorResponse.php:12-19`, `AccountErrorCode.php`, feature `identity-account-error-codes`). Admin still matches messages (`frontend/src/admin/adminError.ts:22`).
- **Release view.** `GET …/releases/{version}` (`GameSystemReleaseResponse.php:13-38`) has the name, the schema version and section counts, but no `librarySources`, which the list row has (`GameSystemReleaseSummaryResponse.php:14-32`). `ReleaseView` (`frontend/src/studio/ReleasesTab.tsx:186-246`) shows only the counts. The release row already stores the full content (`Studio/Domain/Release/GameSystemRelease.php:39-47`). No publisher is recorded.
- **Structured character creation.** It was moved to the backlog by `preset-real-system` (`odd/tasks/preset-real-system.md:34,45`) and ADR 0027:32. A `numberField` has only a literal `default` (`backend/contracts/gamesystem-release/v2.schema.json:90-206`). percentile-horror explains 3d6×5 and a point budget in a tip (`backend/presets/percentile-horror.json:244`).
- **NPC agenda from a later answer** (`preset-pbta` P18). `createNpc` renders `name` and `agenda` once, when the effect fires (`Play/Domain/Campaign/FlowRun/FlowRun.php:1065-1085`). A `{step:x}` that is not answered yet renders as "—" (`Play/Domain/Campaign/FlowRun/Placeholders.php:10`). Studio checks only that the step is in scope, not that it comes earlier (`Studio/Domain/Release/Version2/Placeholders.php:11-34`). So a forward reference validates and then always renders "—". The "contract note" that P18 refers to was never written.

## Decisions (approved by the user 2026-10-24)

| Item | Decision |
|---|---|
| Error codes (ADR 0037) | When the SPA needs to branch on the cause of an error, the body is `{error, code}`. `code` is a string enum in OpenAPI and part of the HTTP contract. `error` stays a human-readable message. Follow Identity's pattern: a backed enum plus a response schema per area. Other endpoints adopt it when they are next touched: Admin's message matching moves to it then. |
| Publish 409 | `code` is `no_changes`, `release_exists` or `library_pack`. |
| Restore 409 | `code` is `modified_concurrently` or `library_pack`. |
| PublishDialog | A message per code: `no_changes` says nothing changed since vN, with no Reload. `release_exists` says another release was published meanwhile, with Reload. `library_pack` says a pack cannot be published. The restore UI branches the same way. |
| Release view (ADR 0038, amends 0028's "viewed read-only (section counts)") | The release detail returns every summary field (it gains `librarySources`) plus an `outline`, keyed by section name like `sectionCounts`. Each section lists `{key, name}` in content order. `name` is the item's display name, or null when the item has none. `sectionCounts` stays. Studio's `ReleaseView` lists the outline instead of only counts. Out: a campaign count per release (Studio must not read Play: the dependency points the other way) and an HTTP download (ADR 0036 keeps export console-only). |
| Release diff | Added, removed and changed keys per section against the previous release become a roadmap item (`release-diff`), not built here. |
| Structured character creation | Becomes a roadmap item (`structured-character-creation`, L), with its own ADR in that feature. Direction: a number field can have a dice default, rolled on the server through Randomness when the Character is created. A sheet-level point budget covers a set of number fields and is checked at creation. Formula maxima for per-Character tracks join it (ADR 0027:32). |
| NPC agenda from a later answer | No contract change. The contract doc describes the pattern: create the NPC in the step that has every answer it needs, and refer to earlier answers with `{step:key}`. Studio refuses a `{step:x}` placeholder in a step's texts or effects when `x` is that step itself or a step after it in the same flow, because such a placeholder can only render "—". This rule is safe because `next` must point forward (`Steps.php:81`). **Refined in T3:** Play records a step's answer before it renders that step's effects, so `{step:self}` is allowed in the step's own effect text and refused in its title, prompt and tip. Scene Types, sessions, scenes and looping phases repeat and can read a previous round's answer, so the rule covers only the `phaseOpening` and `phaseClosing` lists of a `mode: once` phase (the lists that run once, front to back); a key in another list is not checked. **Refined in T5:** `phaseOpening` then `phaseClosing` count as one ordered sequence, so a closing step may name an opening step and an opening step may not name a closing one. |

## Constraints

- Additive REST changes only: existing fields keep their names and meaning.
- The GameSystem release contract is unchanged. The new validation only refuses drafts that could never render the placeholder. Every preset in `backend/presets/` must still validate.
- Studio never reads Play.
- Planning heuristic only: the slice ceiling is ~1,700 changed lines, including generated files.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/release-api-followups-1-codes-outline` | `feat(studio): release-api-followups 1/1 publish error codes, release outline` | T1–T5 | ~1,500: docs and ADRs ~300, error codes ~350, forward-placeholder validation ~150, release outline ~450, generated OpenAPI and TS ~250 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc; ADR 0037 and ADR 0038 (plus the README index); roadmap items `release-diff` and `structured-character-creation` | parent, inline (feature doc); writer (delegated: ADRs, roadmap) | [x] | `75dd8aa` |
| T2 | 1 | `{error, code}` on the publish and restore 409s; PublishDialog and the restore UI branch on the code | writer (delegated) | [x] | `189a07a` |
| T3 | 1 | Studio refuses forward and self `{step:x}` placeholders; the contract doc gains the NPC-agenda pattern note | writer (delegated) | [x] | `e9ba02a` |
| T4 | 1 | Release detail gains `librarySources` and `outline`; `ReleaseView` lists the outline | writer (delegated) | [x] | `da17adf`, `8c7930f` |
| T5 | 1 | Review fixes, if any; closing doc commit | writer (delegated) | [x] | `1d10c81` |

## Acceptance criteria

- Each publish and restore 409 carries its documented `code`, with an API test per code, and the OpenAPI enum lists the codes.
- PublishDialog shows a distinct message per code, and offers Reload only for `release_exists`.
- Publishing a draft with a forward or self `{step:x}` placeholder fails with a validation error at the placeholder's path. Every preset still publishes.
- The release detail returns `librarySources` and an `outline` that matches the content, for schema v1 and v2 releases alike. The Studio release view lists it.
- ADR 0037 and ADR 0038 are written and indexed. The roadmap has `release-diff` and `structured-character-creation` items.

## Checks

`make verify` once per slice. CI runs the full suites.

## Progress

- 2026-10-24: Explored the four items of #255. The user approved every recommended decision. Feature doc written.
- 2026-10-24: T1 done: ADRs 0037 and 0038 indexed, 0028 amended, roadmap B7j and B7k added.
- 2026-10-24: T2 done: two enums and two response schemas (`PublishConflictCode`/`PublishConflictResponse`, `RestoreConflictCode`/`RestoreConflictResponse`) so each endpoint advertises only its own codes; PublishDialog and the restore dialog branch on `StudioError.code`; API test per code (the version race and the concurrent save reuse `duringRequest`).
- 2026-10-24: T3 done: `Placeholders::checkAnswered`, called from `FlowReferences::checkFlow`; three refusing and three accepting unit cases RED then GREEN; all five presets still validate.
- 2026-10-24: T4 done: `ReleaseOutline::of` (Application, next to `SectionCounts`) builds the outline from the stored content for schema v1 and v2; the release detail gains `librarySources` and `outline`; `ReleaseView` lists the outline and the library sources. Generated OpenAPI and TS in the next commit.
- 2026-10-24: T5 done: review suggestions fixed: `phaseOpening` and `phaseClosing` of a run-once phase are checked as one sequence (opening may not name closing); API test that the release detail's `librarySources` equals the history row's.
- 2026-10-24: Feature closed: every item of #255 is decided. The small ones are built in PR #266; the release diff (B7j) and structured character creation (B7k) are roadmap items.

## Reviews

| Slice | Lineage | Outcome | PR |
|---|---|---|---|
| 1 | `review-90bbb333f359709e` | Medium risk, approved and acknowledged. Two suggestions, both fixed in T5: R3-once-phase-cross-list (`FlowReferences.php:130-135`) and R3-library-sources-detail-untested (`GameSystemPublishApiTest.php:375`). The user skipped the review of the T5 fix (56 lines) | [#266](https://github.com/iribarren/ogami/pull/266) |

## Next step

None. Every review finding is fixed. B7j `release-diff` and B7k `structured-character-creation` are on the roadmap.
