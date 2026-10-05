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
| T1 | Domain: tokenizer/parser to an expression tree, evaluator, `Roll` with per-die breakdown, limits; unit tests + Behat scenarios | delegated writer (multi-file) | [ ] | |
| T2 | `RollDice` query + handler + view; `POST /api/rolls` (auth required) with 200/400/422; OpenAPI; `make api`; integration test | delegated writer (multi-file) | [ ] | |
| T3 | Play `DiceRoller` component (input, roll, total, per-die breakdown with dropped dice, error state), mounted on Play home; Vitest, Storybook story, e2e smoke | delegated writer (multi-file) | [ ] | |

## Acceptance criteria

- `4d6kh3`, `2d20kl1`, `1d100`, `d%`, `2d6+1d4-2`, `(1d6+2)*3`, `10/3` parse and evaluate correctly with a scripted generator.
- Every die rolled appears in the breakdown, with dropped dice flagged; the total uses kept dice only.
- Invalid or out-of-limit expressions fail with a clear `InvalidDiceExpression` message.
- `POST /api/rolls` → 200 with `{expression, total, groups}`; 422 `{"error"}` for invalid expressions; 400 for a malformed body; 401 when signed out.
- A solo player rolls from the Play screen and sees the total and each die.

## Checks

`make qa`, `make test`, `make e2e`.

## Progress / Evidence

_None yet._

## Reviews

_None yet._

## Next step

T1.
