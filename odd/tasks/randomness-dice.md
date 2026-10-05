# Feature: randomness-dice

- **Locator:** `odd/tasks/randomness-dice.md` · Engram topic `odd/randomness-dice/tasks`
- **Issue:** #12 · **Branch:** `feat/randomness-dice` (branch point `f2d9ae5` on `main`)
- **Delivery strategy:** `ask-on-risk` → **single-pr** (user choice, size exception) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `identity-auth`, delivered in PRs #7 and #11 (closes #6)

## Objective

Roll real dice notation: the Randomness shared kernel parses and evaluates dice expressions with a per-die breakdown, Play exposes it over `POST /api/rolls`, and a reusable dice-roller component lets the solo player roll from the Play screen.

## Scope

- **In:** `DiceExpression` grammar (NdM, `d%`, constant modifiers, keep/drop highest/lowest, `+ - * /`, parentheses, unary minus); evaluator with injected `RandomNumberGenerator`; `Roll` with per-die breakdown (kept/dropped); `RollDice` query + `POST /api/rolls`; OpenAPI + typed client; `DiceRoller` component in Play with tests and a story.
- **Out:** formulas referencing sheet fields; persisting rolls or logging them to a journal (feature 5); exploding/rerolling dice; oracles (feature 3).

## Constraints

- Randomness `Domain/` stays framework-free (PHPat `testDomainIsFrameworkFree`); only `App\` imports.
- Builds on the existing walking skeleton (`DiceExpression`, `Roll`, `InvalidDiceExpression`, `RandomNumberGenerator`, `ScriptedRandomNumberGenerator`).
- Glossary terms: `DiceExpression`, `Roll`.
- Endpoint returns data, so it is a query (`QueryBus::ask` + view), like `GetUser`.
- Error body keeps the existing `ErrorResponse` shape `{"error": string}`.

## Grammar and rules

```text
expression := term (("+" | "-") term)*
term       := unary (("*" | "/") unary)*
unary      := "-" unary | primary
primary    := integer | dice | "(" expression ")"
dice       := [count] "d" (sides | "%") [selector]
selector   := ("kh" | "kl" | "dh" | "dl" | "k") count     "k" = "kh"
```

- Case-insensitive; whitespace ignored; at most 100 characters.
- `count` 1–100 (default 1); `sides` 2–1000; `d%` = `d100`; at most 100 dice in the whole expression.
- Keep/drop count 1…number of dice for keep, 0…number of dice − 1 for drop.
- Division is integer, rounding down (floor); division by zero is an invalid expression at evaluation.
- Integer literals 0–1,000,000.

## Forecast

~1,100 authored lines: T1 ~500, T2 ~250, T3 ~350. Single PR by user choice.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Domain: tokenizer/parser to an expression tree, evaluator, `Roll` with per-die breakdown, limits; unit tests + Behat scenarios | delegated writer (multi-file) | [x] | `e2703be` |
| T1b | Fix review follow-up: UTF-8-safe tokenizer error messages (oversized-literal message deferred) | inline (one-file fix + tests) | [x] | `1cef8e2` |
| T2 | `RollDice` query + handler + view; `POST /api/rolls` (auth required) with 200/400/422; OpenAPI; `make api`; integration test | delegated writer (multi-file) | [x] | `159b6db` |
| T3 | Play `DiceRoller` component (input, roll, total, per-die breakdown with dropped dice, error state), mounted on Play home; Vitest, Storybook story, e2e smoke | delegated writer (multi-file) | [x] | `29302af` |

## Acceptance criteria

- `4d6kh3`, `2d20kl1`, `1d100`, `d%`, `2d6+1d4-2`, `(1d6+2)*3`, `10/3` parse and evaluate correctly with a scripted generator.
- Every die rolled appears in the breakdown, with dropped dice flagged; the total uses kept dice only.
- Invalid or out-of-limit expressions fail with a clear `InvalidDiceExpression` message.
- `POST /api/rolls` → 200 with `{expression, total, groups}`; 422 `{"error"}` for invalid expressions; 400 for a malformed body; 401 when signed out.
- A solo player rolls from the Play screen and sees the total and each die.

## Checks

`make qa`, `make test`, `make e2e`.

## Progress / Evidence

- **T1** (`e2703be`): delegated writer. Unit RED 114 tests / 67 errors + 30 failures → GREEN `OK (114 tests, 2625 assertions)` (parent re-ran). `make backend-qa` exit 0; `make backend-test` OK (181 tests; Behat 12 scenarios). Expression tree under `Randomness/Domain/Expression/`; per-die class is `RolledDie` (`Die` is reserved in PHP); ties keep the earlier-rolled die; results ≥ 2^63 rejected. +1360 / −172 (incl. feature doc). Review assess: medium, `slice_budget_reached` → consent granted → **approved** (native review, reliability lens; acknowledged, authority burned). Reviewed boundary: `e2703be`.

- **T2** (`159b6db`): delegated writer. RED 17 tests / 9 errors + 8 failures → GREEN `OK (17 tests, 60 assertions)`. `RollDice` query → `RollView`; `RollController` returns 200 / 400 (bad JSON, missing or non-string `expression`) / 415 (non-JSON body, like login) / 422 (`InvalidDiceExpression`) / 401 (access_control). Integration test swaps the RNG with `disableReboot()` + `getContainer()->set()`. Parent moved `ErrorResponse` from Identity to `Shared/Infrastructure/Http` so the Randomness kernel does not depend on Identity. `make backend-qa` exit 0; `make backend-test` exit 0 (198 tests); `make api-check` up to date. Spec lives at `frontend/src/shared/api/openapi.json`. Review assess: high (moved `ErrorResponse` import touches Identity Security handlers) → consent granted → **approved** (4 lenses; acknowledged, authority burned). Reviewed boundary: `159b6db`. All four lenses flagged the same non-blocking UTF-8 → 500 path (T1b).

- **T1b**: inline. RED 3 failures (`2d6+é`, invalid byte `\xC3`, API `2d6+é` → 500) → GREEN `OK (59 tests, 173 assertions)`. `Tokenizer::characterAt()` reports the whole UTF-8 character, or `\xNN` for an invalid byte. `make backend-qa` exit 0; `make backend-test` exit 0. Committed `1cef8e2`; review assess: medium, `under_budget` (34 lines) → pending in the T3 slice.

- **T3** (`29302af`): delegated writer. RED 7/7 failing → GREEN 7 passed. `useRollDice` (typed `RollError` with the server message on 400/422) + `DiceRoller` (props `initialExpression`, `presets`, `onRolled`; presets `d20`, `2d6`, `4d6kh3`, `d%`; `aria-live` result, dropped dice struck through + sr-only "(dropped)", `role="alert"` errors) mounted in a "Dice" section on Play home. Shared `src/test/fakeApi.ts` + `renderWithApi.tsx` for tests and stories. Story `DiceRoller.stories.tsx`; e2e `dice.spec.ts` rolls `2d6+1`. `make frontend-qa` exit 0; `make frontend-test` 57 passed (parent re-ran); `make storybook-build` exit 0; `make e2e` 7 passed. Review assess (`159b6db..29302af`, T1b + T3): medium, `slice_budget_reached` → consent granted → **approved** (reliability lens; acknowledged, authority burned). Reviewed boundary: `29302af`.
- **Closure:** `make qa` exit 0; `make test` exit 0 (PHPUnit 201 tests, Behat, Vitest).

## Follow-ups (non-blocking review findings)

- T1 `R3-utf8-error-byte` (WARNING), T2 `R1-roll-error-invalid-utf8-500` / `R2-unescaped-domain-message-pending-followup` / `R3-utf8-error-500` / `R4-invalid-utf8-error-500` (WARNING): same root cause; **fixed in T1b**.
- T1 `R3-saturated-integer-message` (SUGGESTION): `Token.php:20-26` oversized literals report the saturated value (PHP_INT_MAX) instead of the text written. Deferred: still rejected with a correct limit message.
- T3 `R3-characterat-whole-subject-utf8` (SUGGESTION): `Tokenizer::characterAt()` uses `/u` on the whole subject, so any invalid byte elsewhere falls back to `\xNN` for the reported character. Message stays valid UTF-8; deferred.
- T3 `R3-roll-400-branch-untested` (SUGGESTION): `useRollDice` 400 branch has no frontend test. Deferred.
- T3 `R3-whitespace-submit-silent` (SUGGESTION): submitting only whitespace does nothing, with no feedback. Deferred.
- T2 `R2-task-table-status-drift` (SUGGESTION): task table lagged the evidence; fixed in this doc update.

## Reviews

- `f2d9ae5..e2703be` (doc + T1): medium, approved.
- `e2703be..159b6db` (T2): high, approved (4 lenses).
- `159b6db..29302af` (T1b + T3): medium, approved.

## Next step

All tasks done. Push `feat/randomness-dice` and open one PR (`Closes #12`, label `enhancement`, size exception) — user decision.
