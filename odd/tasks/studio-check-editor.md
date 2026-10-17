# Feature: studio-check-editor

- **Locator:** `odd/tasks/studio-check-editor.md` · Engram topic `odd/studio-check-editor/tasks`
- **Issue:** #177 · **Branch:** `feat/studio-check-editor-6-sandbox-e2e` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-sheet-builder` (PRs #167–#176)

## Objective

Roadmap feature 17 (milestone M4). In a schema 2 GameSystem draft, a GAME_MANAGER authors checks: dice notation, a modifier formula, an optional difficulty and outcome bands, with live formula diagnostics. A test-roll sandbox rolls a saved check against a sample sheet (sample field and tracker values, difficulty, situational modifier) and shows every band's bound, the total and the band reached. The check shape is the contract's as it stands (ADR 0024); the publisher's rules are the editor's rules.

Out of scope: any change to the check contract (exploding dice, pools, success counting, opposed rolls, re-rolls stay ADR 0024 gaps); a TypeScript dice parser or roller; check steps (edited by the flow editor, 18); the library (18b); migrating Campaigns when a check changes (ADR 0010, 0014).

## Problem and why

- A draft's `checks` can only come from a copied or restored release (ADR 0028). The Sections tab only counts them; the flow editor only picks them for check steps.
- Renaming or deleting a check that a check step uses fails only through the item rules, with a single path; the sheet builder and oracle editor refuse in-use keys listing every path.
- A GM cannot see what a check does before publishing: how bands fall for a given sheet and difficulty, or what a roll gives. Play's `Check::perform` is Play Domain, which Studio cannot import (PHPat); only Randomness and Formula are shared kernel.
- `FormulaInput` (studio-sheet-builder) is scope-free, but `formulaDiagnostics` is derived-field specific and always refuses `{check:…}`.

## Model summary (today, schema 2)

`checks` ≤50, keys unique ([contract §Checks](../../docs/contracts/gamesystem-release.md), ADR 0024). Check `{key, label 1–100, hint? ≤500, dice ≤100 (Randomness notation), modifier? (check-scope formula 1–200), difficulty? {label 1–60, default, min?, max?} (integers ±1,000, min ≤ default ≤ max), bands 1–10 of {key, label 1–100, upTo?: int | {tracker} | {formula}}}`. Every band but the last has `upTo`; literal bounds strictly increase. Total = dice + modifier + situational (−100..100); the outcome is the first band whose bound ≥ total, the last band catches the rest. Check scope = sheet scope (`{key}` number/track/derived, `{key.max}` tracks, `{tracker:key}`); a band formula may read `{check:difficulty}` only when its check declares a difficulty; the modifier never reads `{check:…}`. Studio validation: `backend/src/Studio/Domain/Release/Version2/Checks.php` (+ `Bands.php`, `Steps.php:307,324` for check steps). Play: `GameSystemReleaseTranslator::checks()` and `Play/Domain/GameSystem/Check/Check.php::perform` (difficulty → situational → modifier → bounds → roll → overflow guard → band). References to a check: only check steps, `step.check` and `step.outcomes[].band`, in Scene Type `setup/play/closing` and flow phase/hook steps.

## Decisions

| Question | Decision |
|---|---|
| Test roll (user, 2026-10-17) | **Server roll**, as the oracle editor ([ADR 0029](../../docs/adr/0029-studio-oracle-editor.md)): on the saved draft, never recorded. Studio composes the shared-kernel `DiceExpression` and `Formula` itself (ADR 0024: "the same Play-independent pieces"); the editor saves unsaved edits first |
| Dice validation (user, 2026-10-17) | **On save.** The server's 422 on `checks[i].dice` is shown on the dice input. No TS dice parser |
| Contract (Claude) | Unchanged. Every release keeps its canonical form and hash |
| Studio API (Claude) | `GameSystemCheckController` in the ADR 0030 style: `GET /checks` read model (version, schemaVersion, checks, reference catalog: number/track/derived field keys with labels and track max, trackers with initial values, check-step usage paths per check); `POST /checks`, `PUT /checks/{check}` (rename when the key changes; the body is the whole check), `DELETE …?expectedVersion=N`, `POST /checks/{check}/move {expectedVersion, index}` (index in the whole list, out of range a 422 on `index`). Body `{expectedVersion, check}`; every command returns the read model; 400/404/409/415/422 as ADR 0030; in use answers as `OracleInUseResponse` with the paths |
| References (Claude) | `DraftReferences` gains check keys (`step.check`) and band keys per check (`step.outcomes[].band` of steps naming that check), in Scene Type and flow steps. Renaming or deleting a referenced check is a 422 listing every path. A check save that drops a referenced band key (a band rename is a drop plus an add, no cascade) is a 422 listing the outcome paths. Other breaking edits (dropping the difficulty a step sets) go through the item rules with their path |
| Test-roll request (Claude) | `POST /checks/{check}/test-rolls {sheet: {fieldKey: int}, trackers: {trackerKey: int}, difficulty?: int\|null, situational?: int\|null}`. Only number and track fields take a sample; derived values are computed by the server from the saved sheet. A missing number takes its `default`, else 0; a missing track 0; a missing tracker its initial value. Unknown keys, non-integers, values outside a field's `min`/`max` or a track's `max`, a difficulty outside the check's range or on a check without one, a situational outside −100..100: 422 with the path. Evaluation failures (division by zero, overflow) are a 422 and nothing is rolled, as Play |
| Test-roll response (Claude) | `{dice {expression, total, detail as Randomness gives it}, modifier, situational, difficulty\|null, total, bands [{key, label, bound\|null}], band}` (`band` = key reached). Formula bounds that are not increasing for these samples are returned as they are; the UI warns |
| Studio test-roll domain (Claude) | `Studio/Domain/Check/` (or next to the release model) evaluates a check for a test roll in Play's order with an injected `RandomNumberGenerator`; unit tests mirror Play's `Check::perform` cases so the two agree. No shared-kernel move |
| Checks tab (Claude) | `frontend/src/studio/checks/`, a `Checks` tab on `GameSystemPage`: list with up/down move and delete (confirm, in-use paths); editor for key, label, hint, dice, modifier, difficulty (optional block) and bands (add, remove, reorder; `upTo` kind none/number/tracker/formula; the last band has none). Path-prefixed 422s onto inputs (`checkError.ts`), 409 reload. Modifier and band formulas use `FormulaInput` with a check-scope diagnostics sibling of `formulaDiagnostics` (`{check:difficulty}` only in band formulas of a check with a difficulty; no cycle check) |
| Sandbox (Claude) | Beside the editor of a saved check: sample values for number and track fields and trackers, shared with the Sheet tab's preview by lifting `useSheetSamples` to `GameSystemPage`; difficulty (default preset) and situational inputs; band bounds computed live client-side with the TS formula kernel (warning when not increasing); Roll calls the server and keeps the last 5 results (`useRollHistory` pattern) |

## Design

### Backend (slices 1–3)

- ADR 0032 (Studio check editor and test-roll sandbox); ADR index row; glossary entry if a term is new (Test roll exists: reuse it).
- `DraftReferences::checkPathsTo` and band-key paths; `GameSystem::saveCheck`, `deleteCheck`, `moveCheck` with `CheckKeyTaken`, `CheckNotFound`, `CheckInUse`; each command runs the item rules on the whole draft and is all-or-nothing; v1 drafts refused as for the sheet.
- `SaveCheck`, `DeleteCheck`, `MoveCheck` and handlers; `GetGameSystemChecks` → read model, read defensively.
- Controller, request/response DTOs, API tests for every status of every endpoint (each refusal asserts the draft unchanged), `make api`.
- Test roll: domain evaluation, sample-values validation, `TestRollCheck` query, endpoint, API tests.

### Frontend (slices 4–6)

- `useGameSystemChecks`, `checkDraft.ts` (form state and request building that omits empty optionals), `checkError.ts`, Checks tab list, editor.
- Bands editor and check-scope `FormulaInput` diagnostics.
- Sandbox, lifted samples, roll history; Playwright e2e.

## Constraints

- Releases keep their canonical form and hash; no contract or Play change.
- The publisher's item rules decide what a check may be; the test roll agrees with Play's `Check::perform` (same order, same refusals).
- Domain stays framework-free; Studio imports only shared-kernel Randomness and Formula, never Play.
- Frontend uses only the generated client; no new library (ADR 0004).
- Studio is owner-only per draft (ADR 0028).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice (reviewer refuses ~1,850). Each slice's first commit carries the previous slice's review fixes. A slice with API changes commits the regenerated `openapi.json` / `schema.d.ts` on their own.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-check-editor-1-check-commands` | `feat(studio): studio-check-editor 1/6 check commands and read model` | T1, T2, T3 | ~1,400 |
| 2 | `feat/studio-check-editor-2-check-api` | `feat(studio): studio-check-editor 2/6 check API` | T4 | ~1,600 incl. ~700 generated |
| 3 | `feat/studio-check-editor-3-test-roll` | `feat(studio): studio-check-editor 3/6 check test roll` | T5 | ~1,500 incl. ~300 generated |
| 4 | `feat/studio-check-editor-4-checks-tab` | `feat(studio): studio-check-editor 4/6 checks tab and editor` | T6 | ~1,600 |
| 5 | `feat/studio-check-editor-5-bands-formulas` | `feat(studio): studio-check-editor 5/6 bands editor and check formulas` | T7 | ~1,500 |
| 6 | `feat/studio-check-editor-6-sandbox-e2e` | `feat(studio): studio-check-editor 6/6 test-roll sandbox and end to end` | T8, T9 | ~1,600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0032 (Studio check editor and test-roll sandbox) and ADR index row; glossary only if a new term appears | delegated writer (slice 1) | [x] | c0a9852 |
| T2 | 1 | Domain: `DraftReferences` check-key and band-key paths; `GameSystem::saveCheck`, `deleteCheck`, `moveCheck` (rename/delete of a referenced check and dropping a referenced band refused with every path) with `CheckKeyTaken`, `CheckNotFound`, `CheckInUse` | delegated writer (slice 1) | [x] | 00484b1 |
| T3 | 1 | Application: `SaveCheck`, `DeleteCheck`, `MoveCheck` and handlers; `GetGameSystemChecks` query and read model (version, schemaVersion, checks, reference catalog, usage paths per check) | delegated writer (slice 1) | [x] | 87731d9 |
| T4 | 2 | HTTP: `GameSystemCheckController` (`GET /checks`, create, replace/rename, delete, move), DTOs, error mapping, API tests for every 400/404/409/415/422, `make api` | delegated writer (slice 2) | [x] | ab9cb85, d253a9d |
| T5 | 3 | Test roll: Studio domain evaluation in Play's order with an injected RNG (unit tests mirroring `Check::perform`), sample-values validation, `TestRollCheck` query, `POST /checks/{check}/test-rolls`, API tests, `make api` | delegated writer (slice 3) | [x] | 0636f30, 417a21a, a4f8c13 |
| T6 | 4 | Checks tab: `useGameSystemChecks`, `checkDraft.ts`, `checkError.ts`, list with move/delete, editor for key/label/hint/dice/modifier/difficulty, 409 handling, tests | delegated writer (slice 4) | [x] | e4272f0 |
| T7 | 5 | Bands editor (add/remove/reorder, `upTo` kinds) and check-scope formula diagnostics in `FormulaInput` for modifier and band formulas; ChecksTab stories (moved from slice 4); `SaveCheckRequest.check.difficulty` typed as optional in the OpenAPI schema (drop the cast in `useSaveCheck`); tests, stories | delegated writer (slice 5) | [x] | 42cea62, 257c46a |
| T8 | 6 | Sandbox: lift `useSheetSamples` to `GameSystemPage`; sample inputs, difficulty, situational, live band bounds with a non-increasing warning, Roll with the last 5 results; tests, stories | delegated writer (slice 6) | [x] | |
| T9 | 6 | Playwright: author a check with bands and roll it in the sandbox; feature doc close | delegated writer (slice 6) | [ ] | |

## Progress

- 2026-10-17: Feature started. Explored the check contract, Play's `Check::perform`, Studio's sheet and oracle editor patterns and `FormulaInput`. User chose a server test roll and dice validation on save. Issue #177.
- 2026-10-17: T1 done. ADR 0032 and its index row added; glossary Test roll entry extended to checks.
- 2026-10-17: T2 done. `DraftReferences::checkPathsTo` and `bandPathsTo`; `GameSystem::saveCheck`, `deleteCheck`, `moveCheck` with `CheckKeyTaken`, `CheckNotFound`, `CheckInUse`. RED observed (undefined methods, 25 + 9 errors), then GREEN (267 Domain/GameSystem tests).
- 2026-10-17: T3 done. `SaveCheck`, `DeleteCheck`, `MoveCheck` with handlers; `GetGameSystemChecks` returns `GameSystemChecksView` (checks as stored, `CheckFieldView` and `CheckTrackerView` catalog, usages per check key), read defensively. No HTTP layer yet.
- 2026-10-17: Slice 1 review fix. `GameSystem::saveCheck` runs the rename and band in-use refusals only for a string key, so a replacement without one fails on `checks[i].key` through the item rules. RED observed (`CheckInUse`), then GREEN (26 check tests).
- 2026-10-17: T4 done. `GameSystemCheckController` (read, create 201, replace/rename, delete, move) with `SaveCheckRequest`, `MoveCheckRequest`, `GameSystemChecksResponse` and the check schemas; `CheckInUse` answered as `OracleInUseResponse` (422), `CheckKeyTaken` 409 as for oracles and the sheet. 54 API tests covering every status, including a concurrent save. The API contract is regenerated in the next commit.
- 2026-10-17: Slice 2 review fix. The v1-draft API test pins 422 for every command (the version 1 refusal runs before the check lookup, so even an unknown key is not a 404) and the schemaVersion path in the message. Test-only; the pinned behaviour was already green.
- 2026-10-17: T5 part 1 (domain and application). `Studio/Domain/Check/` (`CheckTestRoll`, `SampleSheet`, `OutcomeBand`, `CheckDifficultyRange`, `CheckFormulaValues`, `CheckTestRollResult`, `InvalidTestRoll`, `CheckTestRollFailed`), `GameSystem::check()`, `TestRollCheck` query and handler. RED observed for the domain and handler unit tests (classes missing), then GREEN (76 domain, 16 handler, 3 `GameSystem::check()` tests). Decisions: a missing number reads Play's `NumberField::initial()` (default, else 0 clamped into min..max); a track without a fixed max takes 0..999 and its sample as max; tracker samples are range-checked (counter min..max, clock 0..segments); derived values are computed lazily with a cycle guard (same results as Play's dependency order); a null `sheet` or `trackers` is absent. The endpoint follows in the next commit.
- 2026-10-17: T5 done (part 2). `POST /checks/{check}/test-rolls` on `GameSystemCheckController` with `CheckTestRollRequest`, `CheckTestRollResponse` (dice as `RollResponse`) and `CheckTestRollBandResponse`; `InvalidTestRoll`, `CheckTestRollFailed` and `InvalidReleaseContent` are 422, unknown draft or check 404. 38 API tests cover 401, 403, 200 (scripted RNG), 400, 404, 415 and every 422 path, and assert the draft is unchanged. The API tests were written after the controller, so there is no RED run for them. ADR 0032 states the sample defaults and ranges. The OpenAPI spec and types are regenerated in the next commit.
- 2026-10-17: Slice 3 review fixes. `SampleSheet` reads a null `sheet`/`trackers` as absent and a non-empty list as an object keyed by its indexes, so `{"sheet": [5]}` is a 422 on `sheet.0` (it was `sheet`); `{}` and `[]` stay accepted. The derived-cycle marker is cleared in a `finally`. RED observed for the null and list unit tests (3 failures, 1 error); the `finally` has no observable RED (only `FormulaEvaluationFailed` escapes the evaluation, which the catch already handled), so its test pins the behaviour. API tests for null, empty lists and the `sheet.0`/`trackers.0` paths were written after the code (green on first run).
- 2026-10-17: T6 done. `frontend/src/studio/checks/`: `useGameSystemChecks` (read, create, replace/rename, delete, move; each write sets the read model in the cache), `checkDraft.ts` (bands kept as stored, a new check starts with one `outcome` band, empty optionals omitted, strict integers), `checkError.ts` (`checks[i].…` paths onto inputs, the rest a form message), `ChecksTab` (list with move and delete, in-use paths, inline editor, read-only bands) and a Checks tab on `GameSystemPage`. A 409 on save or move shows the message and refetches; the editor restarts from the refetched check (closes if it is gone), so its next save carries the new version. RED observed for `checkDraft` and `checkError` tests (modules missing), then GREEN (37 tests); `ChecksTab.test.tsx` was written after the code (18 tests, green after fixing a stale-editor-base bug they exposed). Stories moved to slice 5 to stay within the size budget; the generated `SaveCheckRequest.check.difficulty` is typed required, so the hook casts it until slice 5.
- 2026-10-17: Slice 4 review fixes. A move or delete made from the list while the editor is open advances the editor's base to the returned read model without remounting (only when the version went up by exactly 1 and the edited check is still there; deleting the edited check closes it), so the person's own change no longer makes their next save a 409 that discards their edits. The conflict banner says "Couldn't load the latest version" with a Try again button when the reload failed. RED observed (3 of the 6 new tests failed on the old code: own move, own delete, failed-reload banner; the closes-on-delete and skipped-version tests pin behaviour that was already right), then GREEN (23 tests).
- 2026-10-17: T7 done. `BandsEditor` (add, remove, reorder, `upTo` none/number/tracker/formula; 1-10 bands; the last band shows it has no bound and a bound it kept is dropped on save, stated in the form), `checkDraft.ts` band drafts (a bound of an unknown shape or a tracker the draft lacks is kept and sent back as stored; a band with other properties makes the check read-only), `checkError.ts` band paths, `checkFormulaDiagnostics.ts` (check-scope sibling of `formulaDiagnostics`: `{check:difficulty}` only in band formulas of a check with a difficulty, no cycle check, sample value: numbers and tracks 0, track max, tracker initial, difficulty default, no value when a derived field or a missing initial is read) feeding `FormulaInput` for the modifier and band formulas, `ChecksTab` stories. `referenceProblem` and `positionOf` exported from `formulaDiagnostics.ts` for reuse (sheet behaviour unchanged). Tests for `checkDraft`, `checkError` and the diagnostics were written after the code (green on first or second run); `OpenAPI` difficulty fix in its own commit.
- 2026-10-17: Slice 5 review fixes. Band messages (the form's own and the publisher's) are state by band position that follows the bands: a move carries them with their band, a removal drops the removed band's and shifts the later ones, an added band starts with none (`followBands`, `BandsEditor` passes the new order). Local and publisher messages merge per input (`mergeBandErrors`), so a refusal of a band's key no longer hides the form's message about its label. RED observed (3 of the 4 new component tests failed on the old code; the add test pins behaviour that was already right), then GREEN (122 checks tests).
- 2026-10-18: T8 done. The sample values (field drafts with their type, Tracker samples, pruning of gone or retyped fields) moved to `useSampleValues` in `GameSystemView`; `useSheetSamples(values, sheet, layoutDraft)` keeps the template and readings per tab, so the Sheet tab behaves as before (its tests are unchanged apart from the setup of the preview test and stories). `CheckSandbox` sits beside a saved check: number, track and Tracker samples shared with the Sheet preview, a difficulty preset to its default, a situational modifier, and band bounds computed live by `checkSandbox.ts` with the TS formula kernel (derived values through `computeDerived` when the sheet is read; a dash where nothing can be computed; a warning when the bounds do not increase). A roll first saves unsaved edits through the editor's own save (409 reloads the editor, 422 shows on the form) and sends nothing if the save fails; then `POST …/test-rolls` with only the samples typed (a malformed or out-of-range input disables the roll and says why); 404 and 422 messages show in the sandbox; `useRollHistory` (still in `oracles/`) keeps the last 5. A save from the editor now goes on from the returned checks without remounting, so the sandbox keeps its inputs and results. Tests for `checkSandbox` and the sandbox component were written after the code (green on first run); the sandbox does not exist before this slice, so there is no RED run.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-3d1bc1b721f46afc` | c0a9852, 00484b1, 87731d9 | Approved and acknowledged. R3 suggestion: replacing a referenced check with a body whose key is missing or not a string is refused as `CheckInUse` (rename) instead of `InvalidReleaseContent` on the key path (`GameSystem.php:342`); fixed in slice 2 | [#178](https://github.com/iribarren/ogami/pull/178) |
| 2 | `review-a13fa73365bf49ab` | 2f3de19, ab9cb85 (generated d253a9d: review skipped) | Approved and acknowledged. R3 suggestion: the v1-draft API test accepts 404 or 422 for every command (`GameSystemCheckApiTest.php:145`); pin the documented status per command; fixed in slice 3 | [#179](https://github.com/iribarren/ogami/pull/179) |
| 3 | `review-04bbd05d07b30cd6` (0636f30), `review-6efc262584c6aeaf` (417a21a) | 0bed2c0, 0636f30, 417a21a (generated a4f8c13: review skipped) | Approved and acknowledged, reviewed per commit (user accepted ~2,480 lines in one PR). Suggestions: `SampleSheet` refuses a null `sheet`/`trackers` though the decision treats null as absent (the controller maps `?? []`); the derived-cycle marker is not cleared on failure (`SampleSheet.php:127`); `{"sheet": []}` is accepted while `{"sheet": {"0": 1}}` is refused (nested list/object gap of `Request::toArray()`); fixed in slice 4 | [#180](https://github.com/iribarren/ogami/pull/180) |
| 4 | `review-261ee814ae7fef83` | 84e9b50, e4272f0 | Approved and acknowledged. R3 warning: the open editor keeps the version it was opened at, so the person's own move or delete in the list, then a save, is a guaranteed 409 whose reload discards their unsaved edits (`ChecksTab.tsx:75-80`); R3 suggestion: the conflict banner says "The latest version was loaded." even when the refetch failed (`ChecksTab.tsx:98-101`); fixed in slice 5 | [#181](https://github.com/iribarren/ogami/pull/181) |
| 5 | `review-77185a070ee69647` | 18332f6, 42cea62, 257c46a | Approved and acknowledged. R3 warning: band errors are looked up by index and not cleared or remapped when a band is moved, removed or added (`BandsEditor.tsx:90`); R3 suggestion: local and publisher band errors are merged shallowly, so a publisher entry hides the local key, label and upTo messages (`ChecksTab.tsx:476-480`); fixed in slice 6 | [#182](https://github.com/iribarren/ogami/pull/182) |

## Next step

Slice 6 (slice 5 review fixes, T8, T9) on `feat/studio-check-editor-6-sandbox-e2e`: review, PR (`Closes #177`), merge. Then the last slice's review outcome and PR link in one doc-only commit.
