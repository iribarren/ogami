# Feature: preset-ironsworn

- **Locator:** `odd/tasks/preset-ironsworn.md` · Engram topic `odd/preset-ironsworn/tasks`
- **Issue:** [#341](https://github.com/iribarren/ogami/issues/341) · **Branch:** `feat/preset-ironsworn-2-play-roll` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-west-marches` (PRs #338–#339)

## Objective

Roadmap backlog B16. An Ironsworn preset built only on the Ironsworn SRD (CC BY 4.0): challenge dice, momentum that goes below zero and burns, and vow progress tracks. Name the contract gaps ([ADR 0024](../../docs/adr/0024-structured-checks-without-scripting.md)) and close them in every layer. Credits carry the licence notice ([ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)).

Out of scope: asset cards as data (a `list` field holds their names; the player applies them), a flow branch on a match, a momentum maximum as a field bound, unlimited vows.

## Problem and why

- A check has one total and picks a band from it. Formulas cannot read dice, and a push only re-rolls failed pool dice. So an Ironsworn move cannot be expressed today.
- Comparing against two dice is a dice system many open games use (Ironsworn, Starforged and their descendants), so the gap is worth closing as data rather than as hints.

## Contract gaps

| Need | Gap? | Answer |
|---|---|---|
| A score against two d10, each compared on its own | **Gap** | `challenge` on a check |
| A match on the challenge dice | **Gap** | Special result `match`, shown only |
| Burning momentum | **Gap** | `push` on a challenge check |
| Negative momentum cancels the action die | **Gap** | `challenge.momentum` |
| A progress roll with no action die | **Gap** | `challenge` with no `dice` |
| Momentum from −6 to +10 | No | `number` field; negative bounds are valid |
| Momentum reset and maximum by debility | No | Derived `max(0,2-{debilities})`; gains written as `min({momentum}+2,10-{debilities})` |
| Choosing the stat of a move | No | One check per stat in a check set |
| Vow progress tracks | No | Five fixed slots (user's decision) |

## Decisions

| Question | Decision |
|---|---|
| Vows (user) | Fixed slots: five counters of 40 ticks, with the score as ticks/4. No contract change for vows. Limits accepted: five open vows, shown as 12/40 rather than boxes. Revisit only if play shows the limit hurts |
| Licence (Claude) | Only the Ironsworn SRD, and later the text of assets, are CC BY 4.0. The full rulebook is CC BY-NC-SA. So the preset uses the SRD's rules and tables only, summarized in its own words. **The official licensing page (`tomkinpress.com/pages/licensing`) returned HTTP 429 during exploration, and its terms come from search snippets; T11 reads it before the release is written** |
| Credits (Claude, from the SRD's licence section) | `notice` opens with: "This work is based on Ironsworn (found at www.ironswornrpg.com), created by Shawn Tomkin, and licensed for our use under the Creative Commons Attribution 4.0 International License (https://creativecommons.org/licenses/by/4.0/)." It goes on: "Unofficial: not an official Ironsworn product. Rules summarized and adapted for guided solo play." `license`: "CC BY 4.0"; `url`: `https://creativecommons.org/licenses/by/4.0/`. No badge: no images, icons or trade dress |
| Naming (Claude) | Key `ironsworn`, name "Ironsworn (unofficial)". The licence requires a reference to Ironsworn and forbids implying an official product |
| Challenge shape (Claude) | `challenge { count, sides, scoreMax?, momentum? }`: `count` int 2..10, `sides` int 2..1000, `scoreMax` int 1..1000, `momentum` a `number` field's key. Same pattern as a pool: the total is a count, so bands, step outcomes and check sets work unchanged |
| Action roll (Claude) | `dice` + `challenge`. Score = dice + modifier + situational, capped at `scoreMax`. Total = challenge dice strictly below the score: ties go to the dice. Ironsworn bands: `miss` up to 0, `weak` up to 1, `strong` |
| Progress roll (Claude) | `challenge` with no `dice`, and `modifier` required. Score = modifier + situational. Refuses `momentum`, as the SRD ignores momentum on a progress move |
| Refused shapes (Claude) | `pool` + `challenge`; `difficulty` on a challenge check (the dice are the difficulty); `momentum` unless `dice` rolls exactly one die (the action die); `push` on a challenge check without `momentum`; `push.maxDice` on a challenge check; a `momentum` key that is not a `number` field |
| Negative momentum (Claude) | When the momentum value is below 0 and its absolute value equals the action die, the die counts 0. The journal says so |
| Burn as a push (Claude) | A challenge check's `push` burns momentum: no dice are rolled, and the score becomes the momentum value (capped at `scoreMax`). Reuses the label, the cost effects, once per roll, Keep or Push on a pending step, and the sheet's push of the latest entry. Cost: `set momentum {formula: "{momentum-reset}"}`, applied after the score is read |
| When a burn is offered (Claude) | Only when momentum beats more challenge dice than the score. So a roll that cannot improve completes at once, as a roll with no failed die does. Checked again when the burn arrives, so a momentum edited by hand in between fails closed. The SRD's "cancel any die below momentum" is the same as using the larger of score and momentum: if the score beats die A and momentum beats die B, and neither beats the other's die, then S > A ≥ M > B ≥ S, a contradiction |
| Match (Claude) | A new special result `match`, when every challenge die shows the same face. Shown in the roll dialog, journal and sandbox. **Not** offered in step `outcomes`: a special entry replaces the band entry (ADR 0044), and a match must keep the band's effects. A flow tip points at the Major Plot Twist oracle. A burn cannot change it |
| Rollout (Claude) | Additive in schema version 2, so every release keeps its hash. Play's translator refuses `challenge` until slice 2 and a challenge `push` until slice 3, with the path and a message naming the ADR (precedent ADR 0044) |
| Authoring route (Claude) | The writer authors the JSON by hand. A Chrome play pass in slice 6 replaces a separate playtest |

## Design

### Play challenge rolls (slice 2)

- **Kernel:** `Randomness\Domain\ChallengeDice { count 2..10, sides 2..1000 }` rolls a `ChallengeRoll`, built like `DicePool` and `PoolRoll`: faces, `beatenBy(score)`, `isMatch()`, `ofFaces()`. Action die first, then challenge dice.
- **Domain:** `Check` gains `?CheckChallenge { count, sides, scoreMax, momentum }`. Exactly one of `dice` and `pool`, or `challenge` with or without `dice`. Everything is evaluated before anything rolls. `scoreMax` applies to action and progress rolls alike (slice 1 finding).
- **Result:** `CheckResult` gains `?ChallengeResult { roll, score, momentum, cancelled }`, its total is the dice beaten, and its special is `match` or null. `SpecialResult::Match` is refused as a step outcome, by `canProduce` and in the translator.
- **Journal:** a `challenge` part beside the check content: `sides`, `scoreMax`, `score`, `momentum`, `cancelled`, `dice [{face, beaten}]`. The match is derived from the faces, never stored. A progress roll stores `expression` `NdS` of the challenge dice, `diceTotal` 0 and no groups. A row without the part reads as before.
- **Dialog:** stays open on a match or a cancelled die, for the player to read; otherwise it closes as a dice roll does.

### Preset sketch (detailed before slice 5)

- **Sheet:** `edge`, `heart`, `iron`, `shadow`, `wits` number 1–3 with a point budget of 9 (the 3, 2, 2, 1, 1 array fits); `momentum` number −6..10, start 2; `health`, `spirit`, `supply` tracks 0–5, start 5; conditions fields `conditions` (wounded, shaken, unprepared, encumbered), `banes` (maimed, corrupted), `burdens` (cursed, tormented); derived `debilities`, `momentum-reset`, `momentum-max`; `experience` number; `assets` list.
- **Vow slots:** trackers `vow-1`…`vow-5` 0–40, `vow-1-step`…`vow-5-step` (ticks per milestone: Troublesome 12, Dangerous 8, Formidable 4, Extreme 2, Epic 1); Fact slots holding each vow's Thread. Journey, fight and bonds also as 0–40 trackers.
- **Checks:** action rolls `edge`, `heart`, `iron`, `shadow`, `wits`, `health`, `spirit`, `supply`, `endure-harm`, `endure-stress`, with `scoreMax` 10, `momentum` and a burn. Progress rolls `fulfill-1`…`fulfill-5`, `journey`, `fight`, `bonds`, score `{tracker}/4`.
- **Flow:** moves as Scene Types; the SRD's tables (Action, Theme, Combat Action, Major Plot Twist, Challenge Rank, Pay the Price) as oracles.

## Constraints

- Every slice is safe on `main` alone: a release using `challenge` publishes but cannot start a Campaign until Play plays it, and no shipped release uses it until slice 5.
- Formula kernel unchanged. Flow step outcomes unchanged.
- Every release valid before keeps its hash.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-ironsworn-1-contract` | `feat(release): preset-ironsworn 1/6 contract` | T1, T2, T10 | ~1,200 |
| 2 | `feat/preset-ironsworn-2-play-roll` | `feat(play): preset-ironsworn 2/6 challenge rolls` | T3, T4, T5 | ~1,700 (API ~400) |
| 3 | `feat/preset-ironsworn-3-play-burn` | `feat(play): preset-ironsworn 3/6 burning momentum` | T6, T7 | ~1,400 (API ~400) |
| 4 | `feat/preset-ironsworn-4-studio` | `feat(studio): preset-ironsworn 4/6 check editor` | T8, T9 | ~1,600 (API ~300) |
| 5 | `feat/preset-ironsworn-5-release` | `feat(presets): preset-ironsworn 5/6 release` | T11, T12, T13 | ~1,700 |
| 6 | `feat/preset-ironsworn-6-play-pass` | `feat(presets): preset-ironsworn 6/6 play pass` | T14, T15, T16 | ~600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: ADR 0049 and its index, amendments noted on ADR 0024 and ADR 0044, `docs/contracts/gamesystem-release.md`, glossary | delegated writer (slice 1) | [x] | `02afb8aa` |
| T2 | 1 | Schema `v2.schema.json`, Studio validation in `Checks.php`, Play translator refuses `challenge`, valid and invalid fixtures, contract tests | delegated writer (slice 1) | [x] | `4b493539` |
| T3 | 2 | Randomness: challenge roll kernel. Play domain: action and progress rolls, score cap, hits, negative momentum, `match` | | [ ] | |
| T4 | 2 | Journal content and legacy read, Play API, `make api` | | [ ] | |
| T5 | 2 | Play UI: roll dialog and journal show the score, each challenge die, the cancelled die and a match | | [ ] | |
| T6 | 3 | Burn: domain, `CheckPusher` from the sheet, pending step, journal, API | | [ ] | |
| T7 | 3 | Play UI: burn offer on the sheet and the pending step | | [ ] | |
| T8 | 4 | Studio check editor: challenge mode, action or progress, momentum select, burn | | [ ] | |
| T9 | 4 | Studio sandbox: challenge roll, match, burn | | [ ] | |
| T10 | 1 | Studio references: `challenge.momentum` blocks a rename or deletion (`DraftReferences`) | delegated writer (slice 1) | [x] | `5df5b214` |
| T11 | 5 | Read the official licensing page; design the flow in this doc; flow example in `docs/domain/flow-examples.md` | | [ ] | |
| T12 | 5 | `backend/presets/ironsworn.json` base: credits, sheet, trackers, checks; `PresetsTest` | | [ ] | |
| T13 | 5 | Flow, Scene Types and tables; `IronswornPresetFlowTest` | | [ ] | |
| T14 | 6 | Slice 5 review fixes; e2e `frontend/e2e/play-ironsworn.spec.ts` | | [ ] | |
| T15 | 6 | Chrome play pass: swear a vow, a move with a burn, a match, a negative momentum, fulfilling a vow; fixes | | [ ] | |
| T16 | 6 | Closing: Reviews, roadmap `Done:` line under B16, follow-ups if any | | [ ] | |

## Progress

- 2026-11-01: Exploration: five contract gaps named, vows fit as fixed slots (user's decision). Rules read from a copy of the SRD; the official licensing page refused the fetch.

- 2026-11-01: T1 done: ADR 0049, index, amendments on ADR 0024/0041/0044, contract doc and glossary. Docs only: no RED.
- 2026-11-01: T2 done: schema `challenge` and its oneOf, Studio `Checks` refusals, Play translator refuses `challenge` (ADR 0049), fixtures and tests. RED: 32 refusal cases and 41-test class failed (`challenge: unknown property`), GREEN after `Checks.php`; translator RED `unknown property`, GREEN after `refuseUnplayed`.
- 2026-11-01: T10 done: `challenge.momentum` counts in `DraftReferences::sheetFieldPathsTo`. RED: expected the momentum path, got the push effect only; GREEN after the edit.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-fac7148035c23430` | `02afb8aa`, `4b493539`, `5df5b214` | Approved. Two suggestions, fixed in slice 2: `scoreMax` on a progress roll unstated in the contract; no test of a multi-die action roll without `momentum` | [#342](https://github.com/iribarren/ogami/pull/342) |

## Next step

Slice 2: Play challenge rolls (T3, T4, T5), then slice 3: burning momentum.
