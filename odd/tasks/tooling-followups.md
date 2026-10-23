# Feature: tooling-followups

- **Locator:** `odd/tasks/tooling-followups.md` · Engram topic `odd/tooling-followups/tasks`
- **Issue:** #254 · **Branch:** `chore/tooling-followups-1-tests` · **Status:** slice 1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7f

## Objective

Do the tooling and test-suite items of issue #254: one e2e password default, seeding that repairs drifted e2e credentials, faster Studio editor Vitest tests, an overlap audit of handler unit, Behat and API tests (proposal approved before deleting), and CI Docker layer caching only if it measurably shortens CI. Tick each item in the issue as it lands.

## Problem and why

- The e2e password default `e2e-password-123` lives in `Makefile:167`, `compose.yaml:98` and `frontend/e2e/sign-in.ts:7`; changing one silently desynchronises the others.
- `make e2e-seed` runs `app:user:create … --if-missing`, which leaves an existing e2e user untouched (`CreateUserConsoleCommand.php:69-73`). A user whose password, roles or enabled status drifted (e.g. after a manual e2e run or an Admin action) breaks Playwright until the database is reset.
- Vitest is the CI critical path. Measured on `main` `3a94385` (2026-10-23): full local run 3m28, 2,359 tests in 120 files, summed file time 1,041s, of which Studio 794s. The slowest files are `StepListEditor` 128s, `ChecksTab` 86s, `FlowEditorPage` 67s, `SceneTypeEditorPage` 64s, `SheetLayoutPanel` 57s, `TableEditor` 51s, `SheetFieldDialog` 42s, `CheckSandbox` 38s, `NarrativeTab` 32s, `OraclesTab` 26s, `GameSystemPage` 25s. CI's Vitest step took 204s (it took 126s on 2026-10-21 with 2,089 tests).
- CI run 39972782375 (`main`): Backend job 321s (image build and `up` 102s, QA 139s, tests 75s); Frontend job 347s (build and `up` 19s, QA 96s, Vitest 204s, builds 23s); Full stack job 235s (build and `up` 101s, Playwright 125s). Wall time is the slowest job, the Frontend one, which builds only the small node image.
- Test overlap audit (read-only, 2026-10-23). Behat builds handlers with in-memory repositories (165 scenarios, about 1s), unit tests take about 7s and the API/integration layer about 76–99s (WebTestCase plus PostgreSQL, about 45ms per test). Deleting duplicate unit or Behat tests saves under 2s, so the gain is less upkeep, not speed. The overlaps found:
  - Publish-release rules: unit `PublishGameSystemReleaseHandlerTest.php:46,60,87,99,119` repeat `features/studio/publish_gamesystem_release.feature:9,14,22,28,34`.
  - Create-user rules: unit `CreateUserHandlerTest.php:63,105,118` repeat `features/identity/user_account.feature:10,21,16`.
  - Dice: `RollDiceHandlerTest.php:26,65` repeat `DiceExpressionRollTest.php:83,173`.
  - Campaign ownership: 25 handler unit tests copy "another player's campaign is not found" (e.g. `PauseGuidanceHandlerTest.php:45`), while the rule lives once in `src/Play/Application/OwnedCampaigns.php:30,34`, which has no test of its own.
  - API tests re-checking domain variations: `CampaignApiTest.php:311`, `CampaignTrackersApiTest.php:112`, `CampaignFlowRunCommandsApiTest.php:500,513,534`. `:487` stays for its 409.
  - Gap: no HTTP test asserts `LastActiveOwner` → 409 (`src/Admin/Infrastructure/Http/AdminController.php:82`). Only the bus test `LastActiveOwnerThroughCommandBusTest` covers the rule.

## Decisions

| Item | Decision |
|---|---|
| Layer ownership | Unit tests own rules and edge cases; Behat owns the main scenario of each user-visible rule; API tests own HTTP concerns only (status mapping, body shape, auth and roles, parsing, rollback) plus one happy path per endpoint; bus tests own real Doctrine, locking and mail behaviour |
| Deletions (approved by the user 2026-10-23) | (a) the unit duplicates of Behat and of the dice domain tests listed above; (b) a new `OwnedCampaignsTest` (owner found, another player's campaign not found, unknown campaign not found), then the copy-pasted not-found unit tests that only re-check `OwnedCampaigns`. Keep any whose handler does something of its own before or after the lookup; (c) the five API tests re-checking domain variations. Not approved: the tracker clamp cases. Every API test asserting 404/409/422/503 stays |
| Admin 409 gap | Add one `AdminUsersApiTest` case for `LastActiveOwner` → 409. `LastActiveOwnerThroughCommandBusTest` stays. Dropped after review (user, 2026-10-23): over HTTP the 409 needs a race, and faking it by swapping `UserLoader` in the container was too hacky. The rule stays covered by the bus test and Behat; the HTTP mapping has no test |
| e2e password | The `Makefile` holds the only default. `compose.yaml` passes `E2E_PASSWORD` through from the environment with no default. `frontend/e2e/sign-in.ts` fails fast with a clear message when the variable is unset or empty. The README points at the Makefile instead of repeating the value |
| Drifted credentials | New `app:user:create --reset-existing` option: when the email exists, set its password and roles to the given ones and enable the user, through an Identity application command. It fails when combined with `--if-missing`. `--if-missing` keeps its documented meaning. `make e2e-seed` uses `--reset-existing` |
| Studio Vitest | Profile the slowest Studio files first and fix the actual cause. Replace key-by-key `user.type` with `user.clear` + `user.paste` (or a shared helper) wherever the test does not assert per-keystroke behaviour. Keep `user.type` for keys that matter (`{Enter}`, `{Escape}`, autocomplete, per-key validation). No test loses an assertion. Target: summed Studio file time at least 40% lower |
| CI layer caching | After slice 1 merges, compare the job times on its CI run. Wall time is the Frontend job's (Vitest), and caching only shortens image builds, so it helps only if the Backend or Full-stack job becomes the slowest. Slice 2 builds it on a PR and keeps it only if a warm-cache run shortens wall time. Otherwise it closes with the measurement recorded and the issue item ticked as "not worth it" |

## Constraints

- No production behaviour change outside the console command and its Identity application command.
- Context boundaries: the new Identity application command lives in `Identity/Application`; Admin is not involved.
- Planning heuristic only: the slice ceiling is ~1,700 changed lines.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `chore/tooling-followups-1-tests` | `chore: tooling-followups 1/2 e2e seeding, faster Studio tests, test overlap` | T1–T5 | ~1,300: e2e password and seeding ~250, Studio Vitest ~600, overlap deletions about -450 plus ~120 added |
| 2 | `chore/tooling-followups-2-ci-cache` | `chore(ci): tooling-followups 2/2 Docker layer caching` | T6 | ~60, or no merge if it does not shorten CI |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent, inline (doc only) | [x] | `be0f4f8` |
| T2 | 1 | One e2e password default; `--reset-existing` seeding (Identity command, console option, `make e2e-seed`, README) | writer (delegated) | [x] | `9a3a2f9` |
| T3 | 1 | Faster Studio editor Vitest tests | writer (delegated) | [x] | `58d5e73` |
| T4 | 1 | Overlap deletions (a)–(c), `OwnedCampaignsTest`, Admin `LastActiveOwner` 409 API test | writer (delegated) | [x] | `80fd615` |
| T5 | 1 | Slice 1 review fixes, if any | writer (delegated) | [x] | |
| T6 | 2 | CI Docker layer caching, measured; merged only if it shortens wall time | writer (delegated) | [ ] | |

## Applicable checks

- T2: test first. A console integration test for `--reset-existing` (changed password signs in, roles replaced, disabled user enabled, combined with `--if-missing` fails) and a handler unit test. For the e2e password there is no runnable RED (Playwright config); check that `make e2e` passes once locally.
- T3: no RED (speed only). Evidence is the before and after per-file durations of the touched files (Vitest JSON reporter) and a green full Vitest run.
- T4: `OwnedCampaignsTest` and the Admin 409 test pin existing behaviour (no RED expected). Count the tests before and after.
- `make verify` once by the writer; CI runs the full suites.

## Progress

- 2026-10-23: feature doc created (6 tasks, two slices); issue #254; overlap proposal (a)–(c) approved by the user.
- 2026-10-23 T2: RED 7 handler unit errors (class missing) and 6 console errors (`--reset-existing` unknown), then GREEN 7 + 15 tests. `make e2e`: 26 of 28 passed on the first run; the 2 Studio failures were timeouts or flakes (`studio-flow-editor` passed on rerun, 3 times; it also passes on `main`). Sign-in with the seeded password works.
- 2026-10-23 T3: no RED (speed only). Root cause is not key-by-key typing: each input event re-renders the whole editor (React dev render about 40–50 ms per change, 150–600 ms for an initial render), so cost scales with events, not keystrokes. `user.type` of multi-character text became one paste event (`typeText`, `replaceText` in `frontend/src/test/typeText.ts`). Sequential run of the 4 slowest files: 71.4 s to 65.7 s (-8%); parallel Studio run 721 s to 705 s (run-to-run noise about 10–20%, so not a reliable measure). The 40% target is not reachable from the tests: it needs a production render change (memoised cards) or tooling. Green: 1,287 Studio tests.
- 2026-10-23 T4: no RED (pinning tests and deletions). Deleted 32 tests (7 duplicates of another layer, 25 copies of the ownership check), added `OwnedCampaignsTest` (4 cases) and an Admin `LastActiveOwner` 409 case (2 datasets); PHPUnit on `tests/Unit`, `Admin` and `Play`: 5,095 passing. The 409 is unreachable over HTTP without a race (the acting owner is always a second active owner), so the test plays the race with a decorated repository.
- 2026-10-23 T5: no RED (pinning tests). Added `CampaignJournalTest` (owner gets the campaign, another player's is not found) and a `ResetUserCredentials` handler case (last active owner reset to SOLO_PLAYER succeeds); docblocks say so.
- 2026-10-23: the Admin `LastActiveOwner` 409 API test dropped (user's call: the faked race was too hacky); `AdminUsersApiTest` is back to its `main` version.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-2be05bb2e09e0f68` | `be0f4f8`…`80fd615` (base `main` `3a94385`, 1,425 lines) | medium; approved and acknowledged. Findings: R3 journal ownership coverage (warning) and R3 reset bypasses the owner guard (suggestion), both fixed in T5 | [#258](https://github.com/iribarren/ogami/pull/258) |

## Next step

Slice 1: user merges #258. Then T6 (slice 2): compare the job times of #258's CI run and decide on Docker layer caching.
