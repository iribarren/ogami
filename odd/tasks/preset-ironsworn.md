# Feature: preset-ironsworn

- **Locator:** `odd/tasks/preset-ironsworn.md` · Engram topic `odd/preset-ironsworn/tasks`
- **Issue:** [#341](https://github.com/iribarren/ogami/issues/341) · **Branch:** `feat/preset-ironsworn-5-play-burn-api` · **Status:** in progress
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
| Rollout (Claude) | Additive in schema version 2, so every release keeps its hash. Play's translator refuses `challenge` until slice 2 and a challenge `push` until slice 5, with the path and a message naming the ADR (precedent ADR 0044) |
| Authoring route (Claude) | The writer authors the JSON by hand. A Chrome play pass in slice 9 replaces a separate playtest |

## Design

### Play challenge rolls (slices 2 and 3)

- **Kernel:** `Randomness\Domain\ChallengeDice { count 2..10, sides 2..1000 }` rolls a `ChallengeRoll`, built like `DicePool` and `PoolRoll`: faces, `beatenBy(score)`, `isMatch()`, `ofFaces()`. Action die first, then challenge dice.
- **Domain:** `Check` gains `?CheckChallenge { count, sides, scoreMax, momentum }`. Exactly one of `dice` and `pool`, or `challenge` with or without `dice`. Everything is evaluated before anything rolls. `scoreMax` applies to action and progress rolls alike (slice 1 finding).
- **Result:** `CheckResult` gains `?ChallengeResult { roll, score, momentum, cancelled }`, its total is the dice beaten, and its special is `match` or null. `SpecialResult::Match` is refused as a step outcome, by `canProduce` and in the translator.
- **Journal:** a `challenge` part beside the check content: `sides`, `scoreMax`, `score`, `momentum`, `cancelled`, `dice [{face, beaten}]`. The match is derived from the faces, never stored. A progress roll stores `expression` `NdS` of the challenge dice, `diceTotal` 0 and no groups. A row without the part reads as before.
- **API (slice 3):** `challenge` on `CheckResponse` and `CheckContentResponse`, `match` in the `special` enum. Until then a challenge roll answers with its total and band only.
- **Dialog (slice 3):** stays open on a match or a cancelled die, for the player to read; otherwise it closes as a dice roll does.

### Burning momentum (slices 4 to 6)

- **Domain:** `Check` accepts a `push` on a challenge check that names a `momentum`, refuses `maxDice` on it, and the push of a check without a pool or a challenge stays refused. `Check::burn(sheet, ChallengeRoll, firstScore)` reads the momentum from the sheet, evaluates every band bound first, and returns a `BurnResult { momentum, score, total, band, special }`: score = min(momentum, `scoreMax`), total = dice below it, special = `match` or null as rolled. It throws `CheckNotPushable` when the burn beats no more dice than `firstScore`. No randomness: the dice stay as rolled.
- **Offer:** one domain rule, the burn score beats more dice than the first score, used by `FlowRun::pushable` (the momentum of the `ChallengeResult`) and by `Check::burn` (the momentum now). A roll that cannot improve completes at once. Negative or zero momentum beats nothing, so it is never offered.
- **Journal:** `challenge.burn { label, momentum, score, rolledBandKey, rolledBandLabel, changes }`, null when not burned and on every row stored before it. `challenge.score` and each die's `beaten` stay those of the first roll; the check's `total`, band key and band label are those after the burn. Invariants: `burn.score` = min(`burn.momentum`, `scoreMax`), it beats more dice than `challenge.score`, and `total` is the dice below it. The match stays derived from the faces.
- **Application:** `CheckPusher` branches on the entry's part: pool as before, challenge burns. It compares the pinned check with the entry (dice count, sides, `scoreMax`, a momentum named) and refuses a changed one. The cost applies after the momentum is read, so the journal keeps the burned value. `JournalEntry::pushedWith`, the `savePush` guard in `DoctrineJournalEntryRepository` (today only `content->'pool'->'push'`) and the in-memory repository all treat a burned entry as already pushed.
- **API:** same push endpoints. `burn` on `CheckChallengeContentResponse`, and the field key `momentum` on `CheckChallengeResponse`, so the UI reads the value on the Character's sheet. A burn the momentum no longer improves answers 409, as other `CheckCannotBePushed` do.
- **UI:** the offer says "Burn" and states the momentum now on the Character's sheet and the dice it would beat, against those of the roll. When that no longer improves on the roll, the burn button is gone and Keep stays, with a line saying why. The journal shows the burned momentum, the new score, the band before and each die against the new score.

### Preset sketch (detailed before slice 8)

- **Sheet:** `edge`, `heart`, `iron`, `shadow`, `wits` number 1–3 with a point budget of 9 (the 3, 2, 2, 1, 1 array fits); `momentum` number −6..10, start 2; `health`, `spirit`, `supply` tracks 0–5, start 5; conditions fields `conditions` (wounded, shaken, unprepared, encumbered), `banes` (maimed, corrupted), `burdens` (cursed, tormented); derived `debilities`, `momentum-reset`, `momentum-max`; `experience` number; `assets` list.
- **Vow slots:** trackers `vow-1`…`vow-5` 0–40, `vow-1-step`…`vow-5-step` (ticks per milestone: Troublesome 12, Dangerous 8, Formidable 4, Extreme 2, Epic 1); Fact slots holding each vow's Thread. Journey, fight and bonds also as 0–40 trackers.
- **Checks:** action rolls `edge`, `heart`, `iron`, `shadow`, `wits`, `health`, `spirit`, `supply`, `endure-harm`, `endure-stress`, with `scoreMax` 10, `momentum` and a burn. Progress rolls `fulfill-1`…`fulfill-5`, `journey`, `fight`, `bonds`, score `{tracker}/4`.
- **Flow:** moves as Scene Types; the SRD's tables (Action, Theme, Combat Action, Major Plot Twist, Challenge Rank, Pay the Price) as oracles.

## Constraints

- Every slice is safe on `main` alone: a release using `challenge` cannot start a Campaign until slice 2, one with a challenge `push` until slice 5, and no shipped release uses it until slice 8. Between slices 2 and 3 the backend rolls challenge checks and no UI shows their dice, and between slices 5 and 6 the API burns and no UI offers it; no shipped release reaches either. In slice 4 the domain burns and the journal stores it, and nothing calls them.
- Formula kernel unchanged. Flow step outcomes unchanged.
- Every release valid before keeps its hash.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Slice 2 was split after its writer reached ~2,040 lines before the UI: the API and the UI became slice 3, and later slices moved up by one. Slice 4 was split after its writer reached ~2,330 lines before the UI (`backend/tests` alone 1,515): the domain and the journal stayed, the application and the API became slice 5, the UI slice 6.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-ironsworn-1-contract` | `feat(release): preset-ironsworn 1/7 contract` (merged as 1/6) | T1, T2, T10 | ~1,200 |
| 2 | `feat/preset-ironsworn-2-play-roll` | `feat(play): preset-ironsworn 2/7 challenge rolls` | T3, T4 | ~1,480 |
| 3 | `feat/preset-ironsworn-3-play-api` | `feat(play): preset-ironsworn 3/7 challenge API and UI` | T17, T5 | ~1,050 (API ~190) |
| 4 | `feat/preset-ironsworn-4-play-burn` | `feat(play): preset-ironsworn 4/9 burn domain and journal` | T6 | ~1,060 |
| 5 | `feat/preset-ironsworn-5-play-burn-api` | `feat(play): preset-ironsworn 5/9 burn API` | T18 | ~1,290 (API ~120). Drafted on local branch `draft/preset-ironsworn-5-play-burn-api` (`69c73ff2`) |
| 6 | `feat/preset-ironsworn-6-play-burn-ui` | `feat(play): preset-ironsworn 6/9 burn UI` | T7 | ~700 |
| 7 | `feat/preset-ironsworn-7-studio` | `feat(studio): preset-ironsworn 7/9 check editor` | T8, T9 | ~1,600 (API ~300) |
| 8 | `feat/preset-ironsworn-8-release` | `feat(presets): preset-ironsworn 8/9 release` | T11, T12, T13 | ~1,700 |
| 9 | `feat/preset-ironsworn-9-play-pass` | `feat(presets): preset-ironsworn 9/9 play pass` | T14, T15, T16 | ~600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: ADR 0049 and its index, amendments noted on ADR 0024 and ADR 0044, `docs/contracts/gamesystem-release.md`, glossary | delegated writer (slice 1) | [x] | `02afb8aa` |
| T2 | 1 | Schema `v2.schema.json`, Studio validation in `Checks.php`, Play translator refuses `challenge`, valid and invalid fixtures, contract tests | delegated writer (slice 1) | [x] | `4b493539` |
| T3 | 2 | Randomness: challenge roll kernel. Play domain: action and progress rolls, score cap, hits, negative momentum, `match` | delegated writer (slice 2) | [x] | `5e375728` |
| T4 | 2 | Journal content and legacy read | delegated writer (slice 2) | [x] | `7226d202` |
| T5 | 3 | Play UI: roll dialog and journal show the score, each challenge die, the cancelled die and a match | delegated writer (slice 3) | [x] | `5cd305d2` |
| T6 | 4 | Burn domain: `Check::burn`, `BurnResult`, the offer rule, `FlowRun::pushable`, a pending challenge roll, journal `challenge.burn`, the `savePush` guard. The translator still refuses a challenge push | delegated writer (slice 4), split inline by the parent | [x] | `bbf1879f` |
| T7 | 6 | Play UI: burn offer on the sheet and the pending step | | [ ] | |
| T8 | 7 | Studio check editor: challenge mode, action or progress, momentum select, burn | | [ ] | |
| T9 | 7 | Studio sandbox: challenge roll, match, burn | | [ ] | |
| T10 | 1 | Studio references: `challenge.momentum` blocks a rename or deletion (`DraftReferences`) | delegated writer (slice 1) | [x] | `5df5b214` |
| T11 | 8 | Read the official licensing page; design the flow in this doc; flow example in `docs/domain/flow-examples.md` | | [ ] | |
| T12 | 8 | `backend/presets/ironsworn.json` base: credits, sheet, trackers, checks; `PresetsTest` | | [ ] | |
| T13 | 8 | Flow, Scene Types and tables; `IronswornPresetFlowTest` | | [ ] | |
| T14 | 9 | Slice 8 review fixes; e2e `frontend/e2e/play-ironsworn.spec.ts` | | [ ] | |
| T15 | 9 | Chrome play pass: swear a vow, a move with a burn, a match, a negative momentum, fulfilling a vow; fixes | | [ ] | |
| T16 | 9 | Closing: Reviews, roadmap `Done:` line under B16, follow-ups if any | | [ ] | |
| T17 | 3 | Play API: `challenge` on the check and on its journal content, API tests, `make api`. Drafted on local branch `draft/preset-ironsworn-3-play-api` (`74ec85a8`, `7384cee9`), brought onto the latest `main` | delegated writer (slice 3) | [x] | `3a51fbb1` |
| T18 | 5 | Burn through the application and the API: `CheckPusher`, the translator accepts a challenge push (needs a momentum, refuses `maxDice`), `burn` and `momentum` on the responses, API tests from the sheet and the pending step, contract doc, `make api`. Drafted on local branch `draft/preset-ironsworn-5-play-burn-api` (`69c73ff2`), whose tree is the end state of slices 4 and 5 | | [ ] | |

## Progress

- 2026-11-01: Exploration: five contract gaps named, vows fit as fixed slots (user's decision). Rules read from a copy of the SRD; the official licensing page refused the fetch.

- 2026-11-01: T1 done: ADR 0049, index, amendments on ADR 0024/0041/0044, contract doc and glossary. Docs only: no RED.
- 2026-11-01: T2 done: schema `challenge` and its oneOf, Studio `Checks` refusals, Play translator refuses `challenge` (ADR 0049), fixtures and tests. RED: 32 refusal cases and 41-test class failed (`challenge: unknown property`), GREEN after `Checks.php`; translator RED `unknown property`, GREEN after `refuseUnplayed`.
- 2026-11-01: T10 done: `challenge.momentum` counts in `DraftReferences::sheetFieldPathsTo`. RED: expected the momentum path, got the push effect only; GREEN after the edit.
- 2026-11-01: Slice 1 review fixes: `scoreMax` caps a progress roll (contract, ADR 0049); test of a multi-die action roll without momentum or push (already passing: no RED).
- 2026-11-01: T3 done: `ChallengeDice`/`ChallengeRoll`, `DiceExpression::rollsOneDie`, `CheckChallenge`, `ChallengeResult`, `Check::perform` for action and progress rolls, `SpecialResult::Match` (refused as a step outcome in `outcomeKey`, not offered by `canProduce`), translator `checkChallenge`, a challenge push refused. RED: classes not found (kernel, domain) and 23 translator failures (`not played yet`); GREEN after the implementation.
- 2026-11-01: T4 done: `CheckChallengeContent` and its invariants in `CheckContent`; the match is derived from the faces. RED: class not found, GREEN after the implementation. Slice split: API and UI moved to slice 3.
- 2026-11-01: Slice 2 review fixes: a null `push` or `challenge` reads as not set in the translator (Studio and the schema accept both; a real challenge push is still refused); the overflow test now reaches the overflow guard. RED: 2 errors and 1 failure (`not played yet`, `must be an object`); the overflow test was failing on the formula range, not the guard.
- 2026-11-01: T17 done: `challenge` on `CheckResponse` and `CheckContentResponse`, `match` in the `special` enum, API tests (campaign read, action roll, progress roll, match with a cancelled die, a refused challenge push answers 409), `make api`, `challenge: null` on frontend fixtures, `match` label. Draft code and tests written before this session: no RED (they pass on the slice 2 domain).
- 2026-11-01: T5 done: `ChallengeDice`, `Score`/`Beaten`/`Momentum` details, no `Dice` row on a progress roll, the dialog stays open on a match or a cancelled die; the flow step renders `JournalEntryView`, so it needed no change; `canPush` needs a pool, so a challenge roll offers no push. RED: 7 Vitest failures (journal and dialog), GREEN after the view and the dialog.
- 2026-11-01: Slice 3 review fixes: tests for a progress roll rolled from the form, a situational modifier near `scoreMax` (API and view), and a null push beside a real challenge. All passed at once: the code was right and unproved, so no RED.
- 2026-11-01: T6 done: the writer built T6 and T18 together and stopped at the size line; the parent split the tree by path. On the split: `backend-qa` and `frontend-qa` pass, `backend-test` OK (8,358 tests, 187 scenarios). The writer's report gave no RED evidence for this code, so none is claimed.
- 2026-11-01: Slice 4 review fixes: `pushedWith` tests (burned entry, unburned challenge content, plain dice entry; passed at once, no RED); a blank `rolledBandLabel` is refused like a blank key, as published check band labels are never blank. RED: the blank-label case did not throw, GREEN after the check.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-fac7148035c23430` | `02afb8aa`, `4b493539`, `5df5b214` | Approved. Two suggestions, fixed in slice 2: `scoreMax` on a progress roll unstated in the contract; no test of a multi-die action roll without `momentum` | [#342](https://github.com/iribarren/ogami/pull/342) |
| 2 | `review-4b6a6a1688d15960` | `d61231dd`, `5e375728`, `7226d202` | Approved. Three warnings, fixed in slice 3: the translator probably refuses a challenge check with an explicit `push: null`, which Studio accepts, with a misleading message; the overflow test scripts one random value where an action roll needs three; no test drives a challenge roll through the API (a progress roll, a match) | [#343](https://github.com/iribarren/ogami/pull/343) |
| 3 | `review-b9631babc081be6b` | `d5426a99`, `3a51fbb1`, `5cd305d2` | Approved. Two warnings and a suggestion, fixed in slice 4: no test rolls a challenge check from the roll form; `scoreWasCapped` counts the situational modifier with no test where it alone decides the cap; `aNullPushReadsAsNoPush` also passes if the check is missing | [#344](https://github.com/iribarren/ogami/pull/344) |
| 4 | `review-49d6f083bf0372ca` | `5c1c300c`, `38a8a9a6`, `bbf1879f` | Approved. A warning and a suggestion, fixed in slice 5: no test calls `JournalEntry::pushedWith` on a burned entry, with unburned content on a challenge entry or on a plain dice entry; a blank `rolledBandLabel` is accepted where a blank `rolledBandKey` is refused | [#345](https://github.com/iribarren/ogami/pull/345) |

## Next step

Slice 5 in progress: slice 4 review fixes, then the rest of `draft/preset-ironsworn-5-play-burn-api` onto the latest `main` (T18). Then slice 6: the burn UI (T7).
