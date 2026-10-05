# Feature: randomness-oracles

- **Locator:** `odd/tasks/randomness-oracles.md` · Engram topic `odd/randomness-oracles/tasks`
- **Issue:** #14 · **Branch:** `feat/randomness-oracles` (branch point `f9c4dba` on `main`)
- **Delivery strategy:** `ask-on-risk` → **single-pr** (user choice, size exception) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `randomness-dice`, delivered in PR #13 (closes #12)

## Objective

Ask oracles: the Randomness shared kernel resolves oracle tables (ranged or weighted, nested) and answers likelihood oracles (yes/no, likelihood levels, optional chaos factor, exceptional results). Play exposes both over HTTP, and an oracle panel lets the solo player ask them from the Play screen.

## Scope

- **In:** `OracleTable` (ranged entries rolled with a `DiceExpression`, or weighted entries), nested tables by key within a table set, resolution with cycle/unknown-key/coverage validation; `LikelihoodOracle` (levels with targets, optional linear chaos shift, exceptional bands); queries + `POST /api/oracle-table-results` and `POST /api/likelihood-answers`; OpenAPI + typed client; `OraclePanel` in Play with sample definitions, tests and a story.
- **Out:** persisting oracle definitions (feature 4 releases/presets); Mythic random events on doubles; meaning-table pairs (action + subject) as one oracle; logging results to a journal (feature 5); Studio oracle editor (feature 15).

## Constraints

- Randomness `Domain/` stays framework-free (PHPat); dice come from the existing `DiceExpression` / `RandomNumberGenerator`.
- Definitions are request data, validated in the domain; invalid definitions are 422 `{"error"}`, malformed bodies 400, signed out 401 (same shape as `POST /api/rolls`).
- Endpoints return data, so they are queries (`QueryBus::ask` + view).
- Glossary terms: `Oracle`, `OracleTable`, `Likelihood oracle`.
- No Mythic chart or text is hardcoded; Play's sample definitions are generic placeholders until feature 4 presets.

## Rules

### OracleTable

```json
{"key": "weather", "name": "Weather", "dice": "1d6",
 "entries": [{"min": 1, "max": 3, "text": "Clear"},
             {"min": 4, "max": 5, "text": "Rain"},
             {"min": 6, "max": 6, "text": "Storm", "table": "storm-kind"}]}
```

- **Ranged table:** has `dice`; every entry has `min ≤ max`; ranges must not overlap. A roll that matches no entry fails at resolution with a clear message.
- **Weighted table:** no `dice`; every entry has `weight` ≥ 1 (default 1) and no `min`/`max`; rolls `1dW` (W = total weight) and walks the cumulative weights in entry order.
- Mixing ranged and weighted entries in one table is invalid.
- An entry may name a nested `table` by key in the same table set; its `text` may then be empty. Resolution recurses and returns every step in order (root first).
- Table set: unique keys; unknown nested keys and cycles are invalid when the set is built; nesting depth ≤ 10.
- Depth counts levels below the consulted table (at most 11 tables rolled per resolution). Weighted tables roll `RandomNumberGenerator::between(1, W)` directly (W can exceed the 1000-side dice limit); their step shows `1dW`. Names/texts are trimmed; length counts characters. An entry without a nested table needs non-empty text.
- Limits: 1–50 tables per set, 1–1000 entries per table, key ≤ 64 chars (`[a-z0-9-]`), name/text ≤ 500 chars, total weight ≤ 1,000,000.

### Likelihood oracle

```json
{"sides": 100,
 "levels": [{"key": "unlikely", "label": "Unlikely", "target": 35},
            {"key": "likely", "label": "Likely", "target": 65}],
 "chaos": {"min": 1, "max": 9, "neutral": 5, "shiftPerPoint": 5},
 "exceptionalPercent": 20}
```

- `sides` 2–1000; 1–20 levels with unique keys and `0 ≤ target ≤ sides`; `exceptionalPercent` 0–50 (default 0).
- `chaos` optional: `min ≤ neutral ≤ max`, `shiftPerPoint` ≥ 0. Asking without a chaos factor uses `neutral`; a chaos factor outside `min…max`, or given when `chaos` is absent, is invalid.
- Effective target `T = clamp(target + (chaosFactor − neutral) × shiftPerPoint, 0, sides)`.
- Roll `1d<sides>` = R. **Yes** when `R ≤ T`, else **No**.
- **Exceptional yes** when `R ≤ floor(T × p / 100)`; **exceptional no** when `R > sides − floor((sides − T) × p / 100)` (p = `exceptionalPercent`).
- Answer: `exceptional_yes | yes | no | exceptional_no`, with roll, effective target, level and chaos factor used.

## Forecast

~1,700 authored lines: T1 ~450, T2 ~300, T3 ~450, T4 ~500. Single PR by user choice.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Domain: `OracleTable`, entries, table set (validation, nesting, cycles), resolution with steps; unit tests + Behat scenarios | delegated writer (multi-file) | [x] | see evidence |
| T2 | Domain: `LikelihoodOracle` (levels, chaos shift, exceptional bands) and answer; unit tests + Behat scenarios | delegated writer (multi-file) | [ ] | |
| T3 | Queries + handlers + views; `POST /api/oracle-table-results`, `POST /api/likelihood-answers` (auth) with 200/400/422; OpenAPI; `make api`; integration tests | delegated writer (multi-file) | [ ] | |
| T4 | Play `OraclePanel` (likelihood: level, chaos factor, ask, answer; tables: roll a table, show steps; error states) with sample definitions on Play home; Vitest, story, e2e smoke | delegated writer (multi-file) | [ ] | |

## Acceptance criteria

- With a scripted generator, a ranged and a weighted table resolve to the expected entry; a nested entry resolves its sub-table and returns both steps.
- Overlapping ranges, mixed entries, unknown nested keys, cycles and out-of-limit definitions fail with a clear domain error; an uncovered roll fails at resolution.
- Likelihood answers match the rules for yes/no, both exceptional bands, chaos shift and clamping, and the neutral default.
- Both endpoints → 200 with the result; 422 `{"error"}` for invalid definitions; 400 for a malformed body; 401 when signed out.
- A solo player asks a likelihood question and rolls on a table from the Play screen and sees the answer, roll and nested steps.

## Checks

`make qa`, `make test`, `make e2e`, `make storybook-build`.

## Progress / Evidence

- **T1**: delegated writer (multi-file trigger). Classes in `Randomness/Domain/Oracle/`: `OracleTableSet::fromArray()` / `resolve(key, rng): OracleTableResult` → `steps()` of `OracleTableStep` (tableKey, tableName, dice, total, text, nestedTableKey); all failures `InvalidOracleTable` (DomainException → 422). RED 86 tests / 35 errors + 51 failures → GREEN `OK (86 tests, 200 assertions)` (parent re-ran). Behat +7 scenarios. `make backend-qa` exit 0; `make backend-test` exit 0 (287 tests; Behat 19 scenarios). ~1,500 lines vs ~450 forecast, mostly exhaustive validation tests.

## Next step

Commit T1, review assess, then T2 (delegated writer).
