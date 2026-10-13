# Feature: rules-derived-values

- **Locator:** `odd/tasks/rules-derived-values.md` · Engram topic `odd/rules-derived-values/tasks`
- **Issue:** #107 · **Branch:** `feat/rules-derived-values-6-preset` · **Status:** slice 6 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-characters` (PRs #97–#106)

## Objective

Roadmap feature 11 (milestone M3). A formula language for DerivedValues over sheet fields: arithmetic, `min` / `max` and field references, evaluated live on the sheet. Formulas can read Campaign trackers ([ADR 0017](../../docs/adr/0017-narrativeflow-model.md)). A tracker reference in a band bound or a `tracker` effect value becomes a trivial formula. Decide where formula evaluation lives and record it as an ADR: [ADR 0023](../../docs/adr/0023-formula-kernel-and-derived-values.md). Extend the release schema.

Out of scope: checks and formula modifiers on dice (feature 12), sheet-field effects, rounding modes, conditionals, dice inside formulas, Studio's formula editor (M4).

## Problem and why

- The glossary and context map name DerivedValue, but no code, schema or preset has one. Sheets hold only stored values (`CharacterView::of` just orders them).
- Band bounds and effect values take a literal or `{tracker: key}`. Play's `TrackerReference` is resolved in exactly two places: `FlowRun::band()` and `FlowRunContext::applyTrackerEffect()`.
- The only parser is Randomness's `DiceExpression`, which has no identifiers or functions.
- ADR 0022 and contract line 254 said derived values open schema version 3.

## Decisions

| Question | Decision |
|---|---|
| Where evaluation lives (user, 2026-10-13) | **New `Formula` shared kernel** (`backend/src/Formula/Domain`): pure parser, AST and evaluator with an injected value resolver; references and dependency order with cycle detection. Added to PHPat `CONTEXTS` and `SHARED_KERNEL`. Follows the dice parser's arithmetic rules without sharing code |
| Schema version (user, 2026-10-13) | **Additive in schema version 2**: a `derived` field type, and `{formula}` in band `upTo` and effect `value`. Every hash is kept. Amends ADR 0022 and contract line 254 |
| Live evaluation (user, 2026-10-13) | **PHP + TypeScript**: PHP is authoritative (Character response `derived`, flow bands and effects), and a TS evaluator in `frontend/src/shared/lib/formula/` recomputes derived values as the player types. Both run one conformance file, `backend/contracts/formula/conformance.json`, which the node container mounts read-only |
| Syntax (Claude) | Keys may contain `-` and start with a digit, so references are braced: `{str}`, `{hp.max}`, `{tracker:chaos}` (same spelling as the flow placeholder). Functions `min` and `max` (2–10 arguments); `+ - * /` with floor division; unary minus; integer literals 0..1,000,000; intermediate results within ±1,000,000,000 (exact in JS); 1–200 characters |
| Derived as a field type (Claude) | `{key, label, hint?, group?, type: "derived", formula}` in `sheet.fields`, so it shares the key namespace and `group` layout. A Character stores no value for it; writing one is a 422 |
| Scope and errors (Claude) | Sheet formulas read `number` fields, `track` current and `.max`, other derived values (acyclic) and trackers; `text` / `list` refs are rejected. Flow formulas read trackers only. Static errors fail at publish and in Play's translator. Evaluation errors (division by zero, overflow): a derived value reads `null`; a flow band or effect refuses the action with 422 |

## Design

### Formula kernel (slice 1)

```
backend/src/Formula/Domain/
  Formula            fromString(string): self (InvalidFormula), evaluate(ValueResolver): int (FormulaEvaluationFailed), references(): list<Reference>, toString()
  Reference          field(key) | trackMax(key) | tracker(key)
  ValueResolver      interface: value(Reference): int
  DependencyOrder    of(array<string, Formula>): list<string>   (refs to other keys in the map; InvalidFormula on a cycle)
  Tokenizer, Parser, Node AST (Constant, Ref, BinaryOperation, Negation, Call), Operator
backend/contracts/formula/conformance.json
  { "evaluate": [{formula, values?: {"str": 14, "hp.max": 10, "tracker:chaos": 5}, result | error: "syntax" | "divisionByZero" | "overflow" | "unknownReference"}] , "references": [{formula, references}] }
```

### Contract (schema version 2, additive, slice 2)

```
Field   derived   { key, label, hint?, group?, type: "derived", formula (1–200) }
Band    upTo:     int | {tracker: key} | {formula: string}
Effect  tracker   value: int (±1000) | {tracker: key} | {formula: string}
```

- JSON Schema checks structure; the Version2 validator parses formulas and checks references, kinds, cycles and scope (sheet vs flow). Agreement fixtures for each rule. Canonical form unchanged for existing content.

### Play (slices 3–4)

- Translator (slice 3): band `upTo` and effect `value` become `int | Formula`, with `{tracker: key}` read as `{tracker:key}`. `TrackerReference` goes away.
- `FlowRun::band()` and `FlowRunContext::applyTrackerEffect()` evaluate with a tracker-only resolver; an evaluation failure is a domain error mapped to 422.
- Translator (slice 4): `DerivedField` in `SheetTemplate` (formulas re-parsed, references, kinds and cycles re-checked); until then Play keeps rejecting `derived` fields.
- `CharacterSheet` (or similar) in Play's Domain computes a Character's derived values in dependency order from its values (defaults for missing keys) and the Campaign's tracker values; `null` on evaluation failure. `CharacterValues` refuses a value for a derived key (422). `CharacterView.derived: {key: int|null}` in sheet order; `SheetFieldView` gains `formula`. `make api`.

### UI (slice 5)

- `frontend/src/shared/lib/formula/`: tokenizer, parser, evaluator, references, dependency order, mirroring the kernel; Vitest runs the conformance file.
- Character form: derived fields render read-only, recomputed live from the form state and the Campaign's tracker values ("—" with the error on failure). The Characters panel summary shows the server's `derived`.

## Constraints

- Schema version 2 extended additively; JSON Schema and Version2 validator agree. Play reads releases only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- `make api` after response changes; commit `openapi.json` and `schema.d.ts`.
- Every slice safe on `main` alone: slice 1 is a kernel nobody calls; slice 2 is validation of content no preset uses; slice 3 changes flow resolution with behaviour kept for existing releases; slice 4 adds response fields the UI ignores until slice 5.
- Fan-content rule (ADR 0018 decision 29) for preset sheet changes.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice; writer forecasts ran about 2x on play-characters, so slices are planned with margin. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/rules-derived-values-1-kernel` | `feat(formula): rules-derived-values 1/6 formula kernel` | T1, T2 | ~1,300 |
| 2 | `feat/rules-derived-values-2-contract` | `feat(studio): rules-derived-values 2/6 formula contract` | T3 | ~1,200 |
| 3 | `feat/rules-derived-values-3-play-acl` | `feat(play): rules-derived-values 3/6 formulas in play` | T4 | ~1,000 |
| 4 | `feat/rules-derived-values-4-sheet` | `feat(play): rules-derived-values 4/6 derived values on characters` | T5 | ~1,550 incl. ~400 generated |
| 5 | `feat/rules-derived-values-5-live` | `feat(play): rules-derived-values 5/6 live sheet` | T6, T7 | ~1,400 |
| 6 | `feat/rules-derived-values-6-preset` | `feat(play): rules-derived-values 6/6 preset and wrap-up` | T8, T9 | ~600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0023 (parent); ADR index; status notes on ADR 0022 and ADR 0017 ("amended by 0023"); glossary (Formula, Formula reference, DerivedValue updated); contract line 254 and the evolution note point to ADR 0023; context map lists the Formula kernel | delegated writer (slice 1) | [x] | `321418e` |
| T2 | 1 | `Formula` kernel: tokenizer, parser, AST, evaluator with `ValueResolver`, `references()`, `DependencyOrder` with cycle detection, `InvalidFormula` / `FormulaEvaluationFailed`; `backend/contracts/formula/conformance.json` run by PHPUnit; PHPat `CONTEXTS` and `SHARED_KERNEL` gain `Formula` | delegated writer (slice 1) | [x] | `a4c658b` |
| T3 | 2 | Contract: `derived` field type, `{formula}` in band `upTo` and effect `value` in `v2.schema.json` and the Version2 validator (syntax, references, kinds, cycles, flow scope = trackers only); agreement fixtures; canonical form keeps hashes; contract doc section | delegated writer (slice 2) | [x] | `4bd6567` (slice 1 fixes), `673315b` |
| T4 | 3 | Play ACL and flows: translator reads `{formula}` (re-parsed, trackers only, declared trackers); `Band.upTo` and `TrackerEffect.value` become `int\|Formula`, `{tracker}` read as a trivial formula, `TrackerReference` removed; `FlowRun::band()` and `applyTrackerEffect()` evaluate; evaluation failure → 422; tests | delegated writer (slice 3) | [x] | `28e1e35` (slice 2 fixes), `e34c24f` |
| T5 | 4 | Derived values on Characters: `DerivedField` in the snapshot (translator re-checks references, kinds and cycles); domain computation in dependency order (defaults, trackers, `null` on failure); `CharacterValues` refuses derived keys; `CharacterView.derived`, `SheetFieldView.formula`; API tests; `make api` | delegated writer (slice 4) | [x] | `533988f` (slice 3 fixes), `07d4033`, `b3bf365`, `a059c41` |
| T6 | 5 | TS evaluator in `frontend/src/shared/lib/formula/` mirroring the kernel; node container mounts `backend/contracts` read-only; Vitest runs the conformance file | delegated writer (slice 5) | [x] | `29475f6` (slice 4 fixes), `a60511d` |
| T7 | 5 | UI: derived fields read-only and live in the Character form, from form state and tracker values; Characters panel shows `derived`; Vitest | delegated writer (slice 5) | [x] | `bc1e6d5` |
| T8 | 6 | Night Job preset gains derived values: `cool` (Stats, `max(0, {nerve} - {tracker:alarm} / 2)`) and `wounded` (Condition, `{hp.max} / 2`), original wording (ADR 0018 decision 29); preset tests; e2e: edit a stat, see the derived value change | delegated writer (slice 6) | [x] | |
| T9 | 6 | Wrap-up: roadmap 11 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-13: Exploration done (Randomness parser, PHPat kernel rules, tracker refs, sheet and Character views). Three decisions taken with the user; ADR 0023 written by the parent. Issue #107.
- 2026-10-13: T1 docs done (feature doc, ADR 0023, ADR index and status notes, glossary, context map, contract note, CLAUDE.md). Structural readback only, docs task, no RED/GREEN. Route: delegated writer.
- 2026-10-13: T2 Formula kernel done. RED: 160 errors and 3 failures, `App\Formula\Domain\*` classes missing; GREEN: all Formula tests pass; `make qa` and `make test` green. Route: delegated writer.
- 2026-10-13: Slice 1 review fixes done: `Formula::fromString` trims only space, tab, CR, LF; error precedence (left operand, right operand, operator; first failing `min`/`max` argument) pinned with 14 vectors, the evaluator already behaved that way; ADR 0023 index row moved into the table. RED: 4 conformance failures (NUL / vertical tab accepted), precedence vectors green on first run; GREEN: 185 Formula tests pass. Route: delegated writer.
- 2026-10-13: T3 formula contract done. New `Version2/Formulas`; `derived` field in `SheetFields`; `{formula}` through `Effects::reference` for band `upTo` and effect `value`; `StepParts` feeds formula trackers to the flow tracker check; schema `derivedField`, `formulaRef`. RED (src and schema stashed): 43 failures and 3 errors in `ReleaseContentFormulasTest` and `ReleaseSchemaAgreementTest`; GREEN: both pass, with 1,795 Studio and Play unit tests. Play fails closed, pinned in `GameSystemReleaseTranslatorVersion2Test`. Canonical form has no type-specific hash code, existing hashes unchanged. Route: delegated writer.
- 2026-10-13: Slice 2 review fixes done: counter level `upTo` rejects `{formula}` and `{tracker}` (pinned in `ReleaseContentFormulasTest` and two agreement fixtures, already rejected, test-only); `StepParts::referencedTrackers` catches `InvalidFormula` and yields nothing (a malformed flow formula already stopped at `Formulas` first, so no RED: the test pins the single syntax error). Route: delegated writer.
- 2026-10-13: T4 formulas in Play flows done. `Band::$upTo` and `TrackerEffect::$value` are `int|Formula`; `TrackerReference` removed; translator reads `{tracker}` as `{tracker:key}` and `{formula}` (re-parsed, trackers only, exact shape); `FlowRunContext::evaluate()` with the `TrackerValues` resolver serves `FlowRun::band()` and `applyTrackerEffect()`; `FlowFormulaFailed` maps to 422 on every FlowRun endpoint, start/end session and hand scene type switch. RED: translator refused `{formula}` (8 errors in `FlowRunFormulasTest`), API test got 500 without the mapping; GREEN: unit and API tests pass. Route: delegated writer.
- 2026-10-13: Slice 3 review fixes done: the translator rejects a flow formula (band `upTo`, effect `value`, `{tracker}` form included) that reads a Tracker the release does not declare (`unknown tracker`) instead of failing later with a `LogicException`; effect overflow pinned (a billion-valued formula clamps to the Tracker range, an overflowing one is `FlowFormulaFailed` / 422, in unit and API tests); the failing-band and failing-effect rollback tests also assert the FlowRun position. RED: 4 translator cases accepted the undeclared tracker; the overflow and position tests were green on first run (behaviour already correct, pinned only). Route: delegated writer.
- 2026-10-13: T5 derived values on Characters done. `DerivedField` (extends `Field`, holds a `Formula`); `SheetTemplate` orders derived fields by `DependencyOrder` and refuses cycles; translator reads `derived` (`formula` re-parsed) and re-checks like Studio (number, track, `.max` on tracks only, derived, declared trackers, cycles) at `sheet.fields[i].formula`, with the release trackers read first; `DerivedValues::of` computes in dependency order (defaults for missing keys, track current and max, trackers, `null` on failure and for dependents); `CharacterValues::resolve` refuses a value for a derived key (422); `CharacterView.derived` (encoded as `{}` when empty), `SheetFieldView.formula`. RED (src stashed): 14 errors and 24 failures across the new and updated unit and API tests (`DerivedValuesTest`, translator derived-fields, `CharacterViewTest`, `CampaignCharactersDerivedApiTest`); GREEN: Play unit and integration suites pass (1,907 tests). The domain classes were written before their tests, so RED comes from stashing the sources. Route: delegated writer.
- 2026-10-13: T5 API contract regenerated with `make api` (`CharacterResponse.derived`, `SheetFieldResponse.formula`, field type `derived`); the Play character fixtures gain `derived: {}` and `formula: null`. Vitest passes (506); `tsc -b` fails in `frontend/src/play/characters/sheetValues.ts` (`valueOrDefault` has no `derived` case), outside this writer's edit surface, left for the parent. Route: delegated writer.
- 2026-10-13: Character form leaves derived fields out (`storedFields()` in `sheetValues.ts`, used by the Characters panel and the flow's Character step), so it never sends a value the API refuses; `tsc` was failing on the new `derived` type. RED: the new `CharactersPanel` test got a value for the derived key; GREEN: Play Vitest 289 pass, `make qa` green. Route: parent inline (one mechanical frontend fix).
- 2026-10-13: Slice 4 review fixes done: `assertFormulaTrackersDeclared` reads a band `upTo` only at `.bands[n]` nodes and an effect `value` only on `tracker` effects, so a counter level `upTo` never reaches it (pinned in `GameSystemReleaseTranslatorVersion2Test`); a `CharacterStep` test pins that a derived field stays out of the flow's Character form and payload. Both tests were green on first run (behaviour already correct, pinned only). Route: delegated writer.
- 2026-10-13: T6 TS formula evaluator done in `frontend/src/shared/lib/formula/` (`parseFormula`, `Formula.evaluate(resolve)`, `references`, `dependencyOrder`; `InvalidFormula` and `FormulaEvaluationFailed` carry the conformance `error` kinds); floor division with exact integer arithmetic and `-0` normalised. Vitest runs all 135 evaluate and 6 references vectors from `../backend/contracts/formula/conformance.json` (a missing file fails the suite); the node service mounts `./backend/contracts` read-only, which CI gets through the same compose service. RED: the suite could not import the module (implementation was written before the tests, so RED is the module moved away), then the missing mount failed the file read; GREEN: 146 Vitest tests pass. Route: delegated writer.
- 2026-10-13: T7 live derived values done. `computeDerived` (`characters/derivedValues.ts`) mirrors `DerivedValues.php` over the form drafts and the Campaign trackers (an empty or non-integer draft is `incomplete value`, a failed dependency `depends on <key>`, an unparsable formula `invalid formula`, nothing throws); `CharacterForm` takes the whole sheet and `trackers`, keeps `storedFields()` as the payload source, and `SheetFields` renders a derived field as a labelled `<output>` with hint and formula; the Characters panel summary shows the server's `derived` (`null` as a dash) through `summarize`. RED: 7 new component tests and the unit file failed (no module, no output element); GREEN: Play Vitest 301 pass, legacy sheets without derived fields covered by the unchanged existing tests. Route: delegated writer.
- 2026-10-13: Slice 5 review fixes done: `computeDerived` blames `depends on <key>` only on an `unknownReference` failure (a division by zero or overflow keeps its own reason), decides a failed dependency by the referenced field being a derived field with a `reason` result (so an unparsable derived value reads as a dependency), and the cycle return keeps the `invalid formula` results already recorded. RED: 3 new `derivedValues` tests failed (wrong reason twice, dropped result once); GREEN: 8 pass. Route: delegated writer.
- 2026-10-13: T8 Night Job derived values done. `night-job.json` gains `cool` (Stats, after `charm`) and `wounded` (Condition, after `hp`) with original hints; `PresetsTest::nightJobHasAnOriginalSheet` pins the field order and both formulas as `DerivedField`s; `NightJobPresetFlowTest` does not assert the sheet, left unchanged; no example copy of the preset exists in the fixtures. New e2e `play-derived-values.spec.ts`: Add Character on a Night Job campaign, `Cool` follows Nerve live (4, 7, then a dash with its reason when emptied), `Wounded at` is 20, save works. RED (preset stashed): `PresetsTest` failed on the field list; GREEN: 18 preset and flow tests pass, the e2e spec passes (its first run failed on the reason text next to the dash, assertion fixed). Route: delegated writer.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-d1f7272dab31b356` | `321418e`, `a4c658b` | Approved, acknowledged. Fixed in slice 2: R3 `Formula::fromString` trims with `trim()` defaults (NUL, vertical tab) while the tokenizer accepts only space, tab, CR, LF; R3 ADR 0023 index row sits outside the table; R3 suggestion: pin error precedence (left, right, operator) with mixed-error vectors | [#108](https://github.com/iribarren/ogami/pull/108) |
| 2 | `review-6a07a556ed0ef954` | `4bd6567`, `673315b` | Approved, acknowledged. Fixed in slice 3: R3 no test pins that a counter level rejects `{formula}` in `upTo`; R3 suggestion: `StepParts::referencedTrackers` re-parses formulas assuming validated content (map `InvalidFormula` or pin canonical input); R3 suggestion: slice 1 review row sat in the slice plan table (moved here) | [#109](https://github.com/iribarren/ogami/pull/109) |
| 3 | `review-7d73d024fe8eaf11` | `28e1e35`, `e34c24f` | Approved, acknowledged. Fixed in slice 4: R3 a formula effect `value` is unbounded and no test pins 422 vs 500 when its evaluation overflows (`FlowRunContext.php:227`); R3 the translator does not check that a flow formula's trackers are declared in the release, so an unknown tracker is a `LogicException` 500 at runtime (`GameSystemReleaseTranslator.php:868-872`); R3 suggestion: `FlowRunFormulasTest` asserts the failing-band rollback through a clone, assert the FlowRun position | [#110](https://github.com/iribarren/ogami/pull/110) |
| 4 | `review-8ab04f8617ca7a1b` | `533988f`, `07d4033`, `b3bf365`, `a059c41` | Approved, acknowledged. Fixed in slice 5: R3 suggestion: `assertFormulaTrackersDeclared` (`GameSystemReleaseTranslator.php:238-240`) reads `upTo` on every node, scope it to band and effect nodes or pin that a counter level `upTo` never reaches it; R3 suggestion: no `CharacterStepForm` test that derived fields stay out of the form and the payload | [#111](https://github.com/iribarren/ogami/pull/111) |
| 5 | `review-4d98bf9e899e7370` | `29475f6`, `a60511d`, `bc1e6d5` | Approved, acknowledged. Fixed in slice 6: R3 `derivedValues.ts:94-102` labels any evaluation failure that references a failed derived value as `depends on <key>`, even a division by zero or overflow (label only on `unknownReference`); R3 `derivedValues.ts:57-61` the cycle early return drops the `invalid formula` results of unparsable formulas, and a value reading an unparsable derived value reads `incomplete value` instead of `depends on <key>` | [#112](https://github.com/iribarren/ogami/pull/112) |

## Next step

Slice 6 on `feat/rules-derived-values-6-preset`: slice 5 review fixes, then T8 Night Job derived values and e2e, T9 wrap-up (roadmap 11 done, feature doc closed). Feature complete after its review and PR.
