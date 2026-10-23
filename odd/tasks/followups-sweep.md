# Feature: followups-sweep

- **Locator:** `odd/tasks/followups-sweep.md` · Engram topic `odd/followups-sweep/tasks`
- **Issue:** #252 · **Branch:** `fix/followups-sweep-1-sweep` · **Status:** in progress: slice 1
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7d

## Objective

Fix every item of issue #252: small findings earlier features logged and never picked up (F1–F13), plus the missing tests it lists (M1–M8). Tick each item in the issue as it lands.

Out of scope: anything that needs a contract or API design decision (B7g). No item needs one: F10 reuses the existing 422, F5 reuses the existing unreadable-release mapping.

## Problem and why

Each finding was logged by its origin feature (named in the issue) and left open when that feature closed. Every item was confirmed on `main` `4f30296` except M7 (already covered).

## Decisions

| Item | Decision |
|---|---|
| F1 login throttle | `Email::normalize(string): string` (trim + lower case) used by `Email::fromString` and `NormalizedLoginRateLimiter`; malformed input is still throttled |
| F2 `/me` retry | Retry once after ~300 ms, only for non-401 failures (a 401 means signed out and still fails fast) |
| F3 journal append | Cancel in-flight journal reads before the append; skip the append when the cached list already holds the entry id |
| F4 `LinksForm` | Match links by kind and id at all three places |
| F5 release port | New `Studio\Application\PublishedReleaseUnreadable` thrown by `GetPublishedReleaseHandler` on `InvalidReleaseContent`; Play's adapter translates it to `InvalidGameSystemRelease` (existing controller mapping, no HTTP change). The Studio exception carries the found release's version |
| F6 `chaosTracker` | Treat an omitted value as `null` |
| F7a dice literal | Dice count, sides and selector count report the text as written when the integer saturates |
| F7b tokenizer | Match the character on a short slice from the offset, not the whole input |
| F7c whitespace | Inline alert "Type a dice expression" on a whitespace-only submit |
| F8 sandbox track | Typing a current value keeps the shared sample's max |
| F9 409 refetch | A 409 on recording an oracle-table result refetches the journal (pattern of `useFacts.ts`) |
| F10 `forEntry` | The question must be in the current session and scene; otherwise `NotARandomEventQuestion` (422, existing mapping; OpenAPI description text only) |
| F11 guide label | Rename mythic-style's Scene Type to "Open scene" (user's choice); `make presets` republishes it as v2, campaigns on v1 keep the old text |
| F12a attachments | `MAX_ATTACHMENTS = 20` in `attachments.ts`; at 20 the unchecked boxes are disabled with an "Up to 20" hint |
| F12b Scene Type offer | `NoteAttachments` renders `SceneTypeOffer` for an attached oracle-table result (new props optional, `FocusPanel` unaffected) |
| F13 `markInitial` | `toUpperCase()` instead of the runtime locale |
| M2 `CheckForm` key | Direct `CheckStepForm` test rerendering from step A to step B |
| M3 Night Job wording | Assert N2–N5 wording from the preset; the frontend CSS-class assertion (`CampaignListPage.test.tsx:366`) stays out of scope |
| M7 dice 400/422 | Already covered by `CampaignPage.test.tsx:488-502` (shared `explainedRefusals`); ticked, no test |
| M8 oracle order | One both-faults row (unknown level and bad chaos factor) in `LikelihoodOracleAskTest.php` |

## Constraints

- No new HTTP status, error code or release-format change.
- Context boundaries: Play never imports Studio `Domain/` (F5 goes through Studio `Application/`).
- Planning heuristic only: the slice ceiling is ~1,700 changed lines.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/followups-sweep-1-sweep` | `fix: followups-sweep 1/1 small fixes and missing tests` | T1–T6 | ~900 (backend ~350 incl. OpenAPI text; Play journal and panels ~350; Studio and shared ~200). If it passes ~1,500, T5–T6 move to a slice 2 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent, inline (doc only) | [x] | |
| T2 | 1 | Backend Identity and dice: F1, F7a, F7b | writer (delegated) | [x] | |
| T3 | 1 | Backend Play: F5 release port, F10 `forEntry` scene rule, F11 preset rename; tests M3, M4, M8 | writer (delegated) | [x] | |
| T4 | 1 | Frontend auth and journal data: F2, F3, F9; test M1 | writer (delegated) | [x] | |
| T5 | 1 | Frontend Play panels: F4, F6, F7c, F12a, F12b; tick M7 | writer (delegated) | [x] | |
| T6 | 1 | Frontend Studio and shared: F8, F13; tests M2, M5, M6 | writer (delegated) | [x] | |
| T7 | 1 | Slice 1 review fixes (review-a675d2d0d1693922) | writer (delegated) | [x] | |

## Applicable checks

- Test first for every fix (all are deterministic unit, API or component behaviour); M-items pin existing behaviour (no RED expected).
- `make verify` once by the writer; CI runs the full suites.

## Progress

- 2026-10-23: feature doc created (6 tasks, one slice); issue #252.
- T2: F1 `Email::normalize` shared by `fromString` and the login limiter; F7a `Token::written()/saturated()` so count, sides and selector count report the written digits; F7b `characterAt` validates only the character's own bytes. RED observed for all three (4 failing cases + undefined `normalize`), GREEN after.
- T3 (without F10): F5 `PublishedReleaseUnreadable` (Studio) thrown by `GetPublishedReleaseHandler`, translated to `InvalidGameSystemRelease` by Play's adapter (no new factory: `::of` fits; the Studio exception carries key, version, path, reason); F11 mythic-style Scene Type is "Open scene" (RED on `MythicStylePresetFlowTest`, then GREEN); M3/M4/M8 pin existing behaviour (no RED), M3 asserts the wording the Play snapshot exposes (Scene Type tips, step title and tip; the Flow introduction is not in the snapshot). F5 tests are new-class tests. **F10 blocked:** the rule lives in `backend/src/Play/Application/CampaignJournal.php`, outside the allowed edit surfaces.
- T4: F2 `/me` retries once after 300 ms (RED: 500-then-200 test timed out; 401 stays a single request); F3 `cancelQueries` plus id check in `useRecordEntry` (RED: stale refetch dropped the append, a refetch holding the entry duplicated it; new `useJournal.test.tsx`); F9 `refetchOnConflict` for the oracle-table record (RED: no second journal read); M1 409 refetch of the Facts panel (pins existing behaviour, no RED).
- T5: F4 links compared by kind and id (RED: same-id NPC and Thread; option key fixed too); F6 omitted `chaosTracker` read as null (RED: no Chaos factor input); F7c inline "Type a dice expression" alert (RED); F12a `MAX_ATTACHMENTS` with disabled boxes and hint (RED); F12b `SceneTypeOffer` moved to its own file and rendered by `NoteAttachments` (RED: no switch inside the note). M7 already covered by `CampaignPage.test.tsx:488-502`, no test added.
- T6: F8 sandbox keeps the shared track max (RED: max typed in the Sheet preview reset to 0); F13 `toUpperCase()` (RED is a `toLocaleUpperCase` spy standing in for a locale, not a real locale run); M2 new `CheckStepForm.test.tsx` (proved: fails with the `key` removed); M5 refused navigation after a rename in `LikelihoodOracleEditor.test.tsx` and M6 `trackerPips` `{min: 10, max: 14}` pin existing behaviour (no RED).
- T3 / F10: `assertRandomEventQuestion` requires the question in the current session and scene (`NotARandomEventQuestion::notInCurrentScene`, 422; OpenAPI description updated). RED in `RecordOracleTableResultHandlerTest` (earlier-scene question was accepted), GREEN after; one API test for the 422. Edit surface extended by the user for `CampaignJournal.php` and friends.
- T7: review fixes. `useRecordEntry` re-invalidates the journal when `cancelQueries` dropped a read in flight (RED: a refetch carrying another entry was lost; test helper keeps every pending read). Hint replaces the stale roll error in `DiceRoller` (RED: two alerts). `Parser` bare constant goes through `value()` (same written-digit message; refactor, existing tests green), `Tokenizer::utf8SequenceLength`, `sameLink` doc comment moved, `markInitial` spy restored in `finally` (refactors, no RED).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: review, push and PR.
