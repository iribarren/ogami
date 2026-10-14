# Feature: play-checks

- **Locator:** `odd/tasks/play-checks.md` · Engram topic `odd/play-checks/tasks`
- **Issue:** #114 · **Branch:** `feat/play-checks-7-preset` · **Status:** in progress (slice 7)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `rules-derived-values` (PRs #108–#113)

## Objective

Roadmap feature 12 (milestone M3). Check definitions in the release (dice expression + formula modifier + outcome bands). Run checks from the sheet and from flow steps (a `check` step kind, [ADR 0017](../../docs/adr/0017-narrativeflow-model.md)), for a Character in the Scene cast ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md)); log results to the journal. The vision question "How far do structured checks go?" is settled by [ADR 0024](../../docs/adr/0024-structured-checks-without-scripting.md).

Out of scope: exploding dice, stat-sized dice pools, success counting, opposed rolls, re-rolls (ADR 0024 known gaps); Studio's check editor (feature 17); sheet-field effects.

## Problem and why

- The glossary defines Check, but the release reserves `checks: []` (`v2.schema.json:62-66` `maxItems: 0`; `Studio/Domain/Release/ReleaseContent.php:88-90`), and Play's translator never reads it.
- No `check` step kind exists (`Play/Domain/Campaign/FlowRun/StepKind.php`, `Studio/.../Version2/Steps.php:36-45`).
- Night Job (Cyberpunk RED rules) tells the player to roll from their book; the app cannot roll 1d10 + stat against a DV.
- ADR 0022:15, ADR 0017:174 and the contract (`docs/contracts/gamesystem-release.md:255`) said checks open schema version 3.

## Decisions

| Question | Decision |
|---|---|
| Schema version (user, 2026-10-13) | **Additive in schema version 2**: fill the reserved `checks` section and add the `check` step kind. Every hash kept. Amends ADR 0022, ADR 0017 and the contract |
| Roll-time inputs (user, 2026-10-13) | **Difficulty + situational modifier**: a check may declare `difficulty {label, default, min?, max?}`, set by the player at roll time, read by band formulas as `{check:difficulty}`; the player can always add a situational modifier −100..100 (default 0) |
| Sheet checks (user, 2026-10-13) | **Any Campaign Character**, in or out of a Scene. Flow `check` steps roll for a Character in the current Scene (`Scene::characters()`), else the Session party |
| Scripting (Claude, ADR 0024) | None. `total = dice + modifier formula + situational`; first band with `upTo ≥ total`, last catches the rest. Known gaps listed in ADR 0024 |
| Difficulty reference (Claude) | Formula kernel gains reference kind `{check:key}` (PHP, TS, conformance vectors); Studio and Play allow only `difficulty`, only in band formulas of a check that declares one |
| Step branching (Claude) | `outcomes: [{band, next?, effects?}]` keyed by band key, same rules as a `roll` band; a band without an entry continues. Step `difficulty?` (int, `{tracker}`, `{formula}` with trackers) fixes the difficulty |
| Failures (Claude) | Modifier or band evaluation failure → 422, nothing rolled or logged. Difficulty sent to a check without one → 422; outside `min..max` → 422 |

## Design

### Contract (schema version 2, additive)

```
checks  ≤ 50 of Check, keys unique
Check   { key, label (1–100), hint? (≤500), dice (DiceExpression, ≤100), modifier? (formula 1–200, sheet scope),
          difficulty? { label (1–60), default, min?, max? } (ints ±1,000; min ≤ default ≤ max),
          bands 1–10 of { key, label (1–100), upTo?: int | {tracker} | {formula} } (keys unique; all but the last have upTo) }
Step    check { key, kind: "check", title, tip?, mandatory?, check, difficulty?: int | {tracker} | {formula} (trackers), outcomes?: [{band, next?, effects?}] }
```

- Sheet scope = what a derived formula reads (number, track current and `.max`, derived, trackers). Band formulas also `{check:difficulty}` when declared.
- JSON Schema checks structure; the Version2 validator checks dice notation, formulas, references, kinds, band keys, difficulty range, step `check` exists, step `difficulty` only for a check with one, `outcomes[].band` exists and is unique. Agreement fixtures for each rule. Canonical form unchanged for existing content.

### Play

- Translator: `Check` definitions in the GameSystem snapshot (`Play/Domain/GameSystem/Check/`), re-checking every rule (slice 3); `CheckStep` in flows (slice 5: until FlowRun runs it, a `check` step keeps failing closed in the translator, since `StepKind::of` would throw at runtime).
- Performing a check (Play Domain): roll the `DiceExpression` with the injected RNG, evaluate the modifier and band bounds against one Character (a public sheet resolver extracted from `DerivedValues`, plus `{check:difficulty}`), pick the band. Result: roll, modifier, situational, difficulty, total, band.
- Journal entry kind `check` (`CheckContent`): Character id and name, check key and label, roll groups, modifier, situational, difficulty, total, band key and label; flow-step snapshot when from a step.
- Sheet: `POST /api/campaigns/{id}/journal/checks` `{check, character, difficulty?, situational?}` for any Campaign Character; the Campaign response exposes the release's checks.
- Flow: `StepKind::Check`, `StepResult::check`, `CompleteFlowStepHandler` answers with `{character, difficulty?, situational?}`; Character must be in `Scene::characters()` (or the Session party outside a Scene); `outcomes` branch by band key.

### UI

- Characters panel: a "Roll a check" action per Character opens a dialog (check, difficulty when declared, situational modifier, Roll).
- Journal: a `check` entry shows Character, check, dice, modifiers, total vs difficulty and the outcome.
- Step card for `check`: Character picker (preselected when one), difficulty (fixed when the step sets it), situational modifier, Roll.

## Constraints

- Schema version 2 extended additively; JSON Schema and Version2 validator agree. Play reads releases only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)). Play composes Randomness and Formula; neither kernel imports the other.
- `make api` after response changes; commit `openapi.json` and `schema.d.ts`.
- Every slice safe on `main` alone: slice 1 is a decision and a kernel reference nobody uses; slice 2 validates content no preset uses (Play's translator keeps refusing `check` content until slice 3); slices 4–5 add endpoints the UI ignores until slice 6.
- Fan-content rule (ADR 0018 decision 29) for Night Job checks: original wording, generic mechanic only.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-checks-1-decision` | `feat(formula): play-checks 1/7 check decision and reference` | T1, T2 | ~900 |
| 2 | `feat/play-checks-2-contract` | `feat(studio): play-checks 2/7 check contract` | T3 | ~1,400 |
| 3 | `feat/play-checks-3-play-acl` | `feat(play): play-checks 3/7 checks in the snapshot` | T4 | ~1,200 |
| 4 | `feat/play-checks-4-sheet` | `feat(play): play-checks 4/7 sheet checks and journal` | T5 | ~1,600 incl. ~400 generated |
| 5 | `feat/play-checks-5-step` | `feat(play): play-checks 5/7 check step` | T6 | ~1,650 incl. ~400 generated; generated files in their own commit (as slice 4). If the code alone would pass ~1,700, the slice stops after the domain commit (translator still refusing `check` steps) and the rest becomes its own slice (plan renumbered to n/8) |
| 6 | `feat/play-checks-6-ui` | `feat(play): play-checks 6/7 checks in the UI` | T7 | ~1,500 |
| 7 | `feat/play-checks-7-preset` | `feat(play): play-checks 7/7 Night Job checks and wrap-up` | T8, T9 | ~700 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0024 (parent); ADR index row; status notes on ADR 0022 and ADR 0017 ("amended by 0024"); ADR 0023 consequence note (`{check:key}` reference); vision open question moved to resolved; glossary (Check, Outcome band, Flow step kinds, Check difficulty, Situational modifier); contract line 255 points to ADR 0024 | delegated writer (slice 1) | [x] | e80a74d |
| T2 | 1 | Formula kernel: reference kind `{check:key}` (`Reference::check()`, tokenizer/parser, `references()`), PHP and TS evaluators, conformance vectors (evaluate and references); existing contexts (Studio `Formulas`, Play translator, `DerivedValues`, flow resolver) reject a `check` reference where it is not allowed | delegated writer (slice 1) | [x] | 33d9af9 |
| T3 | 2 | Contract: `checks` and the `check` step in `v2.schema.json` and the Version2 validator (dice, formulas and scope, band keys and bounds, difficulty range, step references); agreement fixtures; example release; contract doc section | delegated writer (slice 2) | [x] | 7c68c28, e9e9155 |
| T4 | 3 | Play ACL and domain: translator reads `checks` (re-checks every rule; `check` steps still refused); `Check` snapshot; public sheet resolver extracted from `DerivedValues`; check performance (dice, modifier, situational, difficulty, band) with evaluation failure as a domain error; doc example release gains a check (user, 2026-10-14); unit tests | delegated writer (slice 3) | [x] | 8ad5766, 7f84f68 |
| T5 | 4 | Sheet checks: `check` journal content; `POST /api/campaigns/{id}/journal/checks`; Campaign response exposes checks; 404/422 mapping and API tests; `make api` | delegated writer (slice 4) | [x] | dbe7e39, 5f23c6f, 1409e02 |
| T6 | 5 | Check step: translator reads `check` steps (step `check` exists, `difficulty` only for a check with one, `outcomes[].band` exists and unique; doc example gains a check step), `StepKind::Check`, `StepResult::check`, FlowRun branching by band key and effects, Character eligibility (Scene characters, else party), fixed step difficulty; handler, request and response; API tests; `make api` | delegated writer (slice 5) | [x] | 5ed07d1, 32aec22, 8f4f16a |
| T7 | 6 | UI: check dialog in the Characters panel, journal `check` entry, `check` step card; fixtures; Vitest | delegated writer (slice 6) | [x] | 398d550, 8f159d3, 8e7c4fe, af88989 |
| T8 | 7 | Night Job checks (stats vs DV, original wording) and check steps in the heist flow; preset tests; e2e: roll a check from the sheet and from a step | delegated writer (slice 7) | [x] | |
| T9 | 7 | Wrap-up: roadmap 12 done, feature doc closed | | [ ] | |

## Progress

- 2026-10-13: Exploration done (reserved `checks` section, Formula kernel and `DerivedValues` resolver, `DiceExpression`, step-kind path, `Scene::characters()`, journal kinds, Night Job). Three decisions taken with the user; ADR 0024 written by the parent. Issue #114.
- 2026-10-13: T1 done (docs only; structural readback, no RED/GREEN). Route: delegated writer (slice 1).
- 2026-10-13: T2 done. RED: Vitest 7 failed, PHPUnit 16 failing (8 errors, 8 failures); GREEN: Vitest 166 passed, PHPUnit unit 2,061 passed; `make qa` and `make test` green (Behat 70 scenarios, Vitest 680). Reference kind `check` in PHP and TS; conformance vectors; Studio and Play reject it in derived and flow formulas; `DerivedValues` and the TS twin never resolve it. Route: delegated writer (slice 1).
- 2026-10-13: T3 done. RED: agreement test 324 run, 52 failing (3 valid, 24 structural, 25 semantic check cases); GREEN: 324 passed, plus 4 new unit tests; `make qa` and `make test` green (PHPUnit 3,504, Behat 70 scenarios, Vitest 680). Schema `check`/`checkStep`, `Version2\Checks`, check step in `Steps`, sheet validated before steps in `ReleaseVersion2` (returns `sheet` and `checks`). Existing hashes unchanged. The doc example fixture `v2-contract-doc-example` is left as is (Play translator tests read it and refuse `check` steps until slice 3); checks live in the new fixture `valid/v2-checks.json`. Play translator (read only): a `check` step fails closed (`unknown step kind "check"`), non-empty `checks` is ignored. Route: delegated writer (slice 2).
- 2026-10-14: T4 done. Slice 2 review fixes first (R3-001, R3-002: two tests, behavior already correct, no RED). RED (translator tests only, old translator): 52 run, 48 failing; domain tests (`CheckTest`, `SheetValuesTest`, snapshot) were written after the code, no RED observed. GREEN: Play unit suite 1,120+ passed. `Play/Domain/GameSystem/Check/` (`Check::perform`, all-or-nothing: modifier and every band bound evaluated before the dice are rolled; `CheckEvaluationFailed`, difficulty and situational errors), `SheetValues` extracted from `DerivedValues` (same output), snapshot `checks()`/`check()`, translator re-checks every rule of Studio's `Checks.php` (check `bands` skipped by the Tracker-only band walker); `check` steps still refused. The doc example release and contract example gain the check `slip-past` (no check step). Route: delegated writer (slice 3).
- 2026-10-14: T5 done. Slice 3 review fixes first: R3-001 (translator `try` narrowed to `new Check`, exact messages asserted) and R3-002 (total overflow now `CheckEvaluationFailed`; RED observed: `TypeError` from `CheckResult`, then GREEN). T5 tests were written after the code, no RED observed. `make qa` and `make test` green (PHPUnit 3,699, Behat 70 scenarios, Vitest 35 files). `CheckContent` stores `expression` and `diceTotal`, with the invariant total = diceTotal + modifier + situational in the domain and the stored-data reader; `RollContent` group helpers extracted and reused. `PerformCheck` handler, `POST /api/campaigns/{id}/journal/checks`, `checks` in the Campaign response. A `check` entry renders blank in `JournalEntryView` until slice 6. Size ~2,150 changed lines incl. 570 generated; size exception approved by the user; generated files in their own commit. Route: delegated writer (slice 4).
- 2026-10-14: T6 commit 1 (domain and handler; the translator still refuses `check` steps). No RED observed: tests written after the code. GREEN: `FlowRunCheckStepTest` 17, `CompleteFlowStepCheckHandlerTest` 12, Play unit suite 1,256. `CheckStep`, `StepKind::Check`, `StepResult::check` (answer = band label). `Campaign::flowCheckDifficulty` / `FlowRun::checkDifficulty` validate first (Character in the Scene cast, else the session party; difficulty the step fixes), then the handler performs the check and the FlowRun completes the step, so a refused check rolls nothing. The check journals a `CheckContent` with the flow-step snapshot. Tests build the snapshot by hand (`CheckStepReleases`). Route: delegated writer (slice 5).
- 2026-10-14: T6 commit 2 (translator, request, response, API). No RED observed: tests written after the code. GREEN: `CampaignFlowChecksApiTest` 25, Play infrastructure unit tests 283, Unit + Play integration 3,659. The translator reads `check` steps (`CheckStep`, outcomes keyed by band) and re-checks Studio's rules in the raw-content walk (`assertCheckStepMatchesItsCheck`: check exists, difficulty only for a check that declares one and a literal within its min..max, outcome bands exist; duplicates refused while reading); the fail-closed path is gone. The doc example release (fixture and contract doc) gains the check step `sneak-by` on `slip-past`. `POST .../flow-run/answer` takes `difficulty` and `situational` (malformed: 400; domain errors: 422; concurrent save: 409). `FlowStepResponse` exposes `check`, the fixed `difficulty` (resolved now; null when the player sets it or a formula cannot be evaluated) and `characters` (those in the Scene, else the session party). Route: delegated writer (slice 5).
- 2026-10-14: Slice 5 review fix R3-001: a `{tracker}` or `{formula}` step difficulty that resolves outside the check's min..max already failed closed (422 `CheckDifficultyOutOfRange` before any die is rolled, nothing logged, step still current); added two handler cases and one API case (`wild` Scene Type). Tests only; no RED observed, behaviour already correct. `FlowStepResponse` still exposes the resolved difficulty as is. Route: delegated writer (slice 6).
- 2026-10-14: T7 commit 1 (Characters panel dialog). No RED observed: tests written after the code; Vitest caught one bug first (the Roll button stayed disabled without a Character picker), fixed. New `shared/ui/dialog.tsx` on the `radix-ui` umbrella (no new dependency), `play/checks/` (`CheckForm`, `RollCheckDialog`, `usePerformCheck` on `useRecordEntry`, which appends the entry to the cached journal). The form sends the difficulty when the check declares one (default prefilled) and the situational modifier only when not 0. Route: delegated writer (slice 6).
- 2026-10-14: T7 commit 2 (journal). No RED observed: tests written after the code. A `check` entry renders the Character and check, the band (prominent), dice, modifier and situational when not 0, and the total with "vs <difficulty>" when one applied; the flow-step snapshot is shown by `Journal` as for other kinds, so `JournalEntryView` needed nothing. Route: delegated writer (slice 6).
- 2026-10-14: T7 commit 3 (check step card) and T7 done. No RED observed: tests written after the code. `CheckStepForm` in `StepForm` (`case 'check'`) reuses `CheckForm`: Character select from `step.characters` (preselected with one, chosen with several, a message and no Roll with none), a fixed difficulty shown read-only and not sent, the check taken from `campaign.checks` (unknown key: alert, no form). Answer body `{characterId, difficulty?, situational?}`. `make qa` and `make test` green (Vitest 37 files, Behat 70 scenarios); `make api` changes nothing (no endpoint change). Route: delegated writer (slice 6).
- 2026-10-14: Slice 6 review fixes. R3-001: `difficultyFixed` on `FlowStepResponse` (true when the step fixes the difficulty, even when it cannot be evaluated; carried by the domain `CheckView`); the card shows a fixed difficulty read-only, and an alert with no Roll when fixed but null; API test covers fixed, fixed-unevaluable (`{tracker:alarm} / 0`) and player-set; `make api` regenerated. No RED observed (tests written with the code); GREEN: `CampaignFlowChecksApiTest` + `FlowRunCheckStepTest` 49, `CheckStep.test.tsx` 10. R3-002: `CheckForm` keyed by the step key; the two-step Vitest also passes without the key, because `StepCard` is already keyed by scene/part/step key (`GuidedParts.tsx:64`), so the key is defensive and the test a regression guard. Route: delegated writer (slice 7).
- 2026-10-14: T8 done. RED: `NightJobPresetFlowTest` 8 errors and 1 failure against the oracle steps; GREEN: 21 passed once `night-job.json` has six checks (`nerve`, `hands`, `wits`, `grit`, `charm`, `cool`: 1d10 + the stat, difficulty 9..29 default 13, bands botch/fail/success) and the check steps `slip-past` (hands, difficulty `13 + {tracker:alarm}`, botch to the firefight, fail adds an alarm) and `lose-them` (cool, player-set difficulty). The published preset passes `PresetsTest` unchanged. E2E `play-checks.spec.ts` rolls a Hands check from the sheet and finds it in the journal; the step path is not driven through the UI (the heist phase is ~20 steps away) and is covered by `NightJobPresetFlowTest` and `CampaignFlowChecksApiTest`. Route: delegated writer (slice 7).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-f202bd1c19b1295f` | e80a74d, 33d9af9 | approved, acknowledged; R3 suggestion: glossary "Formula reference" row lacks `{check:key}` (fixed in slice 2) | [#115](https://github.com/iribarren/ogami/pull/115) |
| 2 | `review-702624a43129a102` | 7c68c28, e9e9155 | approved, acknowledged; R3-001: no test for a fixed step difficulty above the check's max (`Steps.php:314-316`); R3-002: no test for an authoring warning that depends on a check outcome's `next` (`AuthoringWarnings.php:155`) (both fixed in slice 3) | [#116](https://github.com/iribarren/ogami/pull/116) |
| 3 | `review-32c07dedbf5699fb` | 8ad5766, 7f84f68 | approved, acknowledged; R3-001: the translator's `try/catch (\InvalidArgumentException)` wraps the whole `Check` construction instead of only the constructor, and malformed-check tests don't assert exact messages; R3-002: `total` overflow near `PHP_INT_MAX` gives a `TypeError` instead of `CheckEvaluationFailed` (`Check.php:99`) (both fixed in slice 4) | [#117](https://github.com/iribarren/ogami/pull/117) |
| 4 | `review-1b59ccf60563bb73` | dbe7e39, 5f23c6f (generated 1409e02 not reviewed, declined by the user) | approved, acknowledged; R3-001: frontend fixtures need the regenerated types, resolved by 1409e02 in the same PR (no fix needed) | [#118](https://github.com/iribarren/ogami/pull/118) |
| 5 | `review-d6fc456db1fb3a12` | 5ed07d1, 32aec22, 8f4f16a | approved, acknowledged; R3-001: a `{tracker}` or `{formula}` step difficulty can fall outside the check's min..max at play time (the translator only checks literals, `GameSystemReleaseTranslator.php:302-308`); the 422 is untested (fixed in slice 6) | [#119](https://github.com/iribarren/ogami/pull/119) |
| 6 | `review-a0cf732b224eb7ee` | 398d550, 8f159d3, 8e7c4fe, af88989 | approved, acknowledged; R3-001: `CheckStepForm.tsx:44` treats a null `step.difficulty` as player-set, but the server also sends null for a fixed formula difficulty it cannot evaluate, so the card shows an editable input and every Roll is refused (422); R3-002: `CheckForm.tsx:46-53` sets its state on mount only, so two consecutive check steps keep the first step's values (both fixed in slice 7) | [#120](https://github.com/iribarren/ogami/pull/120) |

## Slice 7 decisions (parent, 2026-10-14)

- R3-001: `FlowStepResponse` gains a required `difficultyFixed` (true when the step fixes the difficulty, even when its formula cannot be evaluated now). The card shows a fixed difficulty read-only; fixed but null shows an alert and no Roll.
- R3-002: `CheckStepForm` keys `CheckForm` by the step key, so each check step starts from its own values.
- Night Job checks, one per stat: `nerve`, `hands`, `wits`, `grit`, `charm`, `cool` (derived). `1d10` + the stat; difficulty `{label: "Difficulty", default: 13, min: 9, max: 29}`; bands `botch` (upTo difficulty − 6), `fail` (upTo difficulty − 1), `success`. Original wording.
- Heist flow: infiltration `slip-past` becomes a `hands` check step, difficulty `{formula: "13 + {tracker:alarm}"}` (13..19), outcomes `botch` → the old exceptional-no effects (firefight), `fail` → alarm +1. Chase `lose-them` becomes a `cool` check step, difficulty set by the player, no outcomes.

## Next step

Slice 7 (T8, T9): writer, then native review, push and PR, then one doc-only commit recording the slice 7 review and PR on the same branch. Then the feature is closed.
