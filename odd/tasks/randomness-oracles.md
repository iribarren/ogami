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
- Answer: `exceptional_yes | yes | no | exceptional_no`, with roll, sides, effective target, level and chaos factor used (neutral when omitted, null without chaos).
- Labels trimmed, 1–500 chars; `null` fields count as absent; chaos `min`/`max` within ±1000 and `0 ≤ shiftPerPoint ≤ sides`; an unknown level is reported before a bad chaos factor, and both before any roll.

## Forecast

~1,700 authored lines: T1 ~450, T2 ~300, T3 ~450, T4 ~500. Single PR by user choice.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Domain: `OracleTable`, entries, table set (validation, nesting, cycles), resolution with steps; unit tests + Behat scenarios | delegated writer (multi-file) | [x] | `0472c3f` |
| T2 | Domain: `LikelihoodOracle` (levels, chaos shift, exceptional bands) and answer; unit tests + Behat scenarios | delegated writer (multi-file) | [x] | `11d7840` |
| T3 | Queries + handlers + views; `POST /api/oracle-table-results`, `POST /api/likelihood-answers` (auth) with 200/400/422; OpenAPI; `make api`; integration tests | delegated writer (multi-file) | [x] | `2ea975a` |
| T3b | Fix review follow-up: optional definition fields are optional (not required `T \| null`) in the generated types; explicit nulls accepted as absent; `tables` must be a JSON list and `oracle` a JSON object (400); integration tests | delegated writer (multi-file) | [x] | `f8c3e72` |
| T4 | Play `OraclePanel` (likelihood: level, chaos factor, ask, answer; tables: roll a table, show steps; error states) with sample definitions on Play home; Vitest, story, e2e smoke | delegated writer (multi-file) | [x] | `dbd43f7` |

## Acceptance criteria

- With a scripted generator, a ranged and a weighted table resolve to the expected entry; a nested entry resolves its sub-table and returns both steps.
- Overlapping ranges, mixed entries, unknown nested keys, cycles and out-of-limit definitions fail with a clear domain error; an uncovered roll fails at resolution.
- Likelihood answers match the rules for yes/no, both exceptional bands, chaos shift and clamping, and the neutral default.
- Both endpoints → 200 with the result; 422 `{"error"}` for invalid definitions; 400 for a malformed body; 401 when signed out.
- A solo player asks a likelihood question and rolls on a table from the Play screen and sees the answer, roll and nested steps.

## Checks

`make qa`, `make test`, `make e2e`, `make storybook-build`.

## Progress / Evidence

- **T1**: delegated writer (multi-file trigger). Classes in `Randomness/Domain/Oracle/`: `OracleTableSet::fromArray()` / `resolve(key, rng): OracleTableResult` → `steps()` of `OracleTableStep` (tableKey, tableName, dice, total, text, nestedTableKey); all failures `InvalidOracleTable` (DomainException → 422). RED 86 tests / 35 errors + 51 failures → GREEN `OK (86 tests, 200 assertions)` (parent re-ran). Behat +7 scenarios. `make backend-qa` exit 0; `make backend-test` exit 0 (287 tests; Behat 19 scenarios). ~1,500 lines vs ~450 forecast, mostly exhaustive validation tests. Commit `0472c3f`. Review assess: medium, `slice_budget_reached` → consent granted → **approved** (reliability lens; acknowledged, authority burned). Reviewed boundary: `0472c3f`.

- **T2**: delegated writer (multi-file trigger). `LikelihoodOracle::fromArray()` / `ask(levelKey, ?chaosFactor, rng): LikelihoodAnswer` (answer `YesNoAnswer` enum, roll, sides, effectiveTarget, levelKey, levelLabel, chaosFactor); failures `InvalidLikelihoodOracle` (DomainException → 422); key rule shared via `OracleTable::isValidKey()`. RED 159 tests / 39 errors + 37 failures (T1's 86 green) → GREEN `OK (178 tests, 389 assertions)` (parent re-ran). Behat +10 scenarios (written after the domain, no RED). `make backend-qa` exit 0; `make backend-test` exit 0 (379 tests; Behat 29 scenarios). ~1,140 lines. Commit `11d7840`. Review assess: medium, `slice_budget_reached` → consent granted → **approved** (reliability lens; acknowledged, authority burned). Reviewed boundary: `11d7840`.

- **T3**: delegated writer (multi-file trigger). Queries `ResolveOracleTable` / `AskLikelihoodOracle` → views; controllers `OracleTableController` (`POST /api/oracle-table-results`, operationId `resolveOracleTable`) and `LikelihoodOracleController` (`POST /api/likelihood-answers`, `askLikelihoodOracle`), tag `Oracles`. Responses `OracleTableResultResponse {table, steps[]}`, `LikelihoodAnswerResponse {answer, roll, sides, effectiveTarget, likelihood, likelihoodLabel, chaosFactor}`. 200 / 400 (bad JSON, wrong top-level types; `chaosFactor: null` = absent) / 415 / 422 (domain errors) / 401. RED 43 failures (no route) → GREEN `OK (43 tests, 192 assertions)`. `make backend-qa` exit 0; `make backend-test` exit 0 (422 tests); `make api-check` up to date (parent re-ran integration tests and api-check). ~1,295 authored lines + generated API files. Commit `2ea975a`. Review assess: medium, `slice_budget_reached` → consent granted → **approved** (reliability lens; acknowledged, authority burned). Reviewed boundary: `2ea975a`.

- **T3b**: delegated writer (multi-file trigger). Cause: Nelmio emitted `default: null` for `= null` constructor params and openapi-typescript 7 treats a default as required. Optional doc-DTO properties are nullable with no PHP default (and `chaos` ref gets `nullable: true`) → `field?: T | null`. `tables` must be a JSON list and `oracle` a JSON object (400); empty `{}` still reaches the domain 422. Explicit-null tests already green (domain treats null as absent). RED 62 tests / 3 failures → GREEN `OK (62 tests, 274 assertions)`. `make backend-qa` exit 0; `make backend-test` exit 0 (427 tests); `make api-check` up to date (parent re-ran integration tests and api-check). Commit `f8c3e72`. Review assess: medium, `under_budget` → pending in the T4 slice.

- **T4**: delegated writer (multi-file trigger). `src/play/oracles/`: `useOracles.ts` (`useAskLikelihoodOracle`, `useResolveOracleTable`, typed `OracleError` with server message on 400/422), `OraclePanel` (props `likelihoodOracle`, `tables`, `onAnswered?`, `onResolved?`; native styled `<select>` since no shadcn select yet; chaos input only when defined; nested steps indented with sr-only "Nested roll:"; button "Roll on table" to avoid clashing with the dice e2e "Roll"), `sampleOracles.ts` (generic d100 likelihood + Weather/Storm kind/NPC mood/Travel event), mounted in an "Oracles" section on Play home. Story with 5 variants; e2e `oracles.spec.ts`. RED 12 failed → GREEN 70 passed (parent re-ran `make frontend-test`). `make frontend-qa` exit 0; `make storybook-build` exit 0; `make e2e` 8 passed. ~770 lines. Commit `dbd43f7`. Review assess (`2ea975a..dbd43f7`, T3b + T4): medium, `slice_budget_reached` → consent granted → **approved** (reliability lens; acknowledged, authority burned). Reviewed boundary: `dbd43f7`.
- **Closure:** `make qa` exit 0; `make test` exit 0 (PHPUnit 427 tests, Behat, Vitest 70); `make e2e` 8 passed (parent ran all three).

## Follow-ups (non-blocking review findings)

- T1 `R3-uncovered-ranges-fail-only-at-roll-time` (SUGGESTION): ranged tables with gaps or ranges outside the dice's reachable totals are accepted and fail only on some rolls. Matches the spec (uncovered roll fails at resolution); coverage validation needs dice min/max. Deferred.
- T2 `R3-validation-order-unproved` (SUGGESTION): no test proves an unknown level is reported before a bad chaos factor, or that invalid asks fail before rolling. Deferred.
- T3 `R3-explicit-null-optional-fields-unproved` (WARNING): generated types make optional definition fields required `T | null`, so the typed client would send explicit nulls, which no test covers. **Fixed in T3b**.
- T3 `R3-tables-object-not-list-unproved` (SUGGESTION): a JSON object as `tables`, or a list as `oracle`, passes the 400 guard. **Fixed in T3b**.

## Reviews

- `f9c4dba..0472c3f` (doc + T1): medium, approved.
- `0472c3f..11d7840` (T2): medium, approved.
- `11d7840..2ea975a` (T3): medium, approved.
- `2ea975a..dbd43f7` (T3b + T4): medium, approved.

## Next step

All tasks done and committed; not pushed. Delivery (push, PR with `Closes #14` and `type:feature`, merge commit) is the user's decision.
