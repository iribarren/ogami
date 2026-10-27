# Feature: preset-vtm-chronicle

- **Locator:** `odd/tasks/preset-vtm-chronicle.md` · Engram topic `odd/preset-vtm-chronicle/tasks`
- **Issue:** #301 · **Branch:** `feat/preset-vtm-chronicle-7-findings` · **Status:** in progress (slice 7)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B10 (after `rules-dice-pools`, [ADR 0041](../../docs/adr/0041-dice-pools-pushes-and-conditions.md))

## Objective

A Vampire: The Masquerade night-court sandbox fan preset, following [example 1](../../docs/domain/flow-examples.md#1-vtm-chronicle-sandbox), authored **in Studio** and exported unedited into `backend/presets/`. The check contract gains what VtM's dice need, in every layer as `rules-dice-pools` did: critical pairs, Hunger dice, special results a flow can branch on, and a push capped at N dice. Credits gain a badge so Play shows the Dark Pack logo.

Out of scope: picked re-rolls (the player choosing dice), opposed rolls, several Characters with their own ambitions (M3), items (B11), Studio polish beyond the gaps that block this preset.

## Problem and why

- Pools (ADR 0041) count successes against a face threshold and push every failed die. VtM adds pairs of 10s worth extra successes, Hunger dice rolled inside the pool in a second colour and never re-rolled, results that come from those dice (messy critical, bestial failure), and a Willpower re-roll of at most three dice.
- Example 1's flow already validates and plays (fixture `backend/tests/Fixtures/Studio/releases/examples/vtm-chronicle.json`, explorer 2026-10-27); only the roll-under masquerade style (`upTo {tracker:masquerade}`) has never run end to end.
- Releases carry no image, but the Dark Pack Agreement requires its logo on the material.

## Decisions

| Question | Decision |
|---|---|
| License | The [Dark Pack Agreement](https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement) (Paradox Interactive, read 2026-10-27; no version or date on the page) covers free, non-commercial fan content and names character sheets, online generators "including downloadable and mobile apps" and virtual dice rollers. Apps must be free with no in-app purchases. It grants use of World of Darkness names and terms (clans, sects, the Camarilla) to a reasonable extent; copying a whole work is never reasonable. It requires the Dark Pack logo on the material, Paradox's copyright notice and a notice that the material is not official. No registration. It fits Ogami, a free non-commercial fan project |
| What we build | A VtM preset under its own name, `vtm-night-court`, "Vampire: The Masquerade — Night Court". VtM names and terms are used; all wording is original, with no verbatim book text and no page references ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29) |
| Logo (user, 2026-10-27) | Additive `credits.badge`, a closed enum, first value `dark-pack`. The SPA ships the official logo from Paradox's asset folder and shows it beside the notice. No image URLs in releases |
| Special results (user, 2026-10-27) | Show and branch: the roll result and the journal flag critical, messy critical and bestial failure, and a `check` step's outcomes can match a special result ahead of the band (bestial failure → a Compulsion table) |
| Willpower re-roll (user, 2026-10-27) | Additive `push.maxDice`: a push re-rolls at most N failed regular dice, never Hunger dice. Picked re-rolls stay out |
| Authoring route | Studio UI through Claude in Chrome, as `preset-pbta` and `preset-vaesen` did; the export is committed unedited ([ADR 0036](../../docs/adr/0036-export-releases-as-preset-files.md)) |
| Order | Mechanics first (contract, Play, Studio), the preset last, so it is authored once |

### For the ADR (slice 1) to settle

Defaults the ADR writer starts from; it may change them with a reason recorded in the ADR.

- `pool.criticals`: `{bonus}` (int 1..10). Every pair of dice showing the top face (`sides`), in any group, adds `bonus` successes (VtM: 2, so a pair of 10s counts 4). Only with `pool`.
- `pool.hunger`: `{count}`, int 0..100 or `{formula}` (sheet scope, as `pool.count`). The Hunger dice are taken out of the pool, clamped to the pool size; they use the same `successAt` and are never pushed. Journalled dice carry group `hunger` (ADR 0041 kept the `group` for this).
- Special results, at most one per roll, computed from the dice: `critical` (a critical pair), `messyCritical` (a critical pair with a Hunger die in it), `bestialFailure` (the roll fails and a Hunger die shows 1). "Fails" must come deterministically from data a check already has: the ADR picks it (suggested: below the difficulty when one applies, otherwise the check's lowest band) and records it. Whether a push recomputes the special result (VtM: yes, the re-rolled dice count) is settled there too.
- `check` step `outcomes[]`: an entry names exactly one of `band` or `special`; a matching special entry wins over the band entry; each band and each special at most once. Only on a check with `pool.hunger` or `pool.criticals` as needed.
- `push.maxDice`: int 1..100, optional; without it a push re-rolls every failed regular die (ADR 0041). With it, the first N failed regular dice in roll order are re-rolled (deterministic, no picking).
- `credits.badge`: null or `"dark-pack"`; unknown values fail closed.
- All additive in schema version 2; existing content hashes unchanged. Play's translator refuses each new shape until the slice that plays it (precedent ADR 0027, 0040, 0041).

## Publisher policy

- License page: [Dark Pack Agreement](https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement).
- `credits.notice`: "Night Court is unofficial fan content and not official World of Darkness material; its text was written with the help of generative AI. Portions of the materials are the copyrights and trademarks of Paradox Interactive AB, and are used with permission. All rights reserved. For more information please visit worldofdarkness.com." `credits.license` "Dark Pack Agreement"; `credits.url` the page above; `credits.badge` `dark-pack`.
- The Dark Pack logo is the only Paradox asset used; no Paradox art, trade dress or Camarilla logo.

## Preset outline (draft; settled in the preset slice)

GameSystem `vtm-night-court`, original wording throughout.

- **Sheet:** concept, clan, predator type (text); attributes (`number` 1..5); skills (`number` 0..5); Hunger (`number` 0..5), Humanity (0..10), Willpower and Health damage; the exact skill list and budgets are chosen in the preset slice.
- **Checks:** pool `{attribute} + {skill}`, d10, success at 6, `criticals.bonus` 2, `hunger.count` `{hunger}`; push "Spend Willpower" with `maxDice` 3 and a Willpower damage cost. Rouse check: one die, Hunger +1 on failure (a step outcome).
- **Narrative:** example 1's trackers (`masquerade`, `hunters`, `night`), fact slots, tags, Scene Types, tables `night-events`, `ally-problems`, `arrivals`, plus a Compulsion table on bestial failure; the masquerade uses the roll-under style.
- **Flow `chronicle`:** Session Zero (City, Court, Factions, Character) and the Chronicle loop with session hooks and a world turn, as example 1.

## Constraints

- Additive in schema version 2; no migration (Character values, FlowRun and journal content are JSONB). Legacy journal entries (pool dice in group `pool` only) read unchanged.
- The upgrade plan stays deterministic; the planner does not read checks today (explorer).
- Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)): original wording, credits carry the notices.
- Every slice safe on `main` alone. Slice ceiling ~1,700 changed lines, including generated files.

## Layer map (explorer, 2026-10-27)

- Contract: `backend/contracts/gamesystem-release/v2.schema.json:724-736` (`$defs/checkPool`); `docs/contracts/gamesystem-release.md:335,360-398` (line 386 stale: Play reads `pool`/`push` now); Studio publisher `Studio/Domain/Release/Version2/Checks.php:35,65-85,119-140`, `DraftReferences.php`; credits `ReleaseVersion2.php:44-58,241`.
- Kernel: `Randomness/Domain/DicePool.php`, `PoolRoll.php` (`push()` re-rolls every failed die), `PoolDie.php` (`{face, success, rerolled}`, no group).
- Play: translator `GameSystemReleaseTranslator.php:1197,1222-1299` (pool, push), `:866-894` (credits); `Check/Check.php:96-175`, `CheckPool.php`, `CheckResult.php`, `PoolResult.php`, `PushResult.php`; journal `CheckPoolContent.php:30-31,145,185` (group `pool` enforced), `CheckPushContent.php:48`; HTTP `CheckPoolContentResponse`, `JournalPoolDieResponse`, `JournalPushDieResponse`, `CheckPoolResponse`; flow `FlowRun.php:331-342,412`, `Flow/CheckStep.php:58` (`outcomeFor`), `PendingCheck`.
- Play UI: `play/checks/CheckForm.tsx`, `RollCheckDialog.tsx`, `PushOffer.tsx`, `pushing.ts` (pushable from `successes < size`), `play/journal/JournalEntryView.tsx:280-317` (`PoolDice`), `play/flow/PendingCheckForm.tsx`, `StepCard.tsx`, `play/campaigns/CreditsNotice.tsx`.
- Studio: `studio/checks/ChecksTab.tsx:853-1005`, `checkDraft.ts:312-332`, `checkError.ts`, `PushEditor.tsx`, `CheckSandbox.tsx`, `checkSandbox.ts`; backend `Studio/Domain/Check/CheckTestRoll.php`, `CheckTestPool*`, views and `GameSystemCheckController.php`, `GameSystemCheckPool.php`, `CheckTestRollPool*Response`; flow editor `studio/narrative/{flows,steps}/`; metadata `studio/MetadataForm.tsx:50-194`.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes. Slice 6 was split in two when it was planned (2026-10-27): the export alone is ~1,900 lines, so the findings, flow test and e2e moved to slice 7 (slices 1–5 PR titles said n/6).

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-vtm-chronicle-1-contract` | `feat(studio): preset-vtm-chronicle 1/6 hunger, criticals and badge contract` | T1–T3 | ~1,550: this doc and roadmap ~250; ADR 0044, glossary ~250; schema and contract doc ~250; publisher (`Checks`, step outcomes, credits) with unit tests and fixtures ~800 |
| 2 | `feat/preset-vtm-chronicle-2-play-roll` | `feat(play): preset-vtm-chronicle 2/6 hunger dice and criticals in Play` | T4–T5 | ~1,650: kernel groups, criticals, Hunger dice, special result, capped push ~400; translator, `Check` perform and push, results, journal content with groups and special ~700; API responses and fixtures ~250; generated ~300 |
| 3 | `feat/preset-vtm-chronicle-3-play-ui` | `feat(play): preset-vtm-chronicle 3/6 special results, flow branching and badge` | T6–T8 | ~1,500: dice in two colours, the special result, push offer with the cap ~450; step outcomes on a special (translator, `CheckStep`, `FlowRun`) ~450; credits badge (translator, responses, logo asset, `CreditsNotice`) ~250; generated ~300 |
| 4 | `feat/preset-vtm-chronicle-4-studio-checks` | `feat(studio): preset-vtm-chronicle 4/6 hunger and criticals in the check editor` | T9–T10 | ~1,700: editor criticals, Hunger and push cap ~550; sandbox backend and views ~450; sandbox UI ~350; generated ~350 |
| 5 | `feat/preset-vtm-chronicle-5-studio-flow` | `feat(studio): preset-vtm-chronicle 5/6 special outcomes and badge in Studio` | T11–T12 | ~1,000: check step outcomes on a special in the flow editor ~550; metadata badge ~250; generated ~200 |
| 6 | `feat/preset-vtm-chronicle-6-preset` | `feat(presets): preset-vtm-chronicle 6/7 night court` | T13–T15 | ~2,300: slice 5 review fixes ~150; this doc ~100; exported JSON ~1,900 (12 Scene Types, 13 checks; one file, so over the ceiling and reviewed apart from it); `PresetsTest` ~120 |
| 7 | `feat/preset-vtm-chronicle-7-findings` | `feat(presets): preset-vtm-chronicle 7/7 findings and preset tests` | T16–T18 | ~600: Studio and Play findings from T13–T14 fixed or filed; flow unit test, e2e ~350; closing |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; roadmap B10 in progress | parent (inline, docs) | [x] | d100598, a219e5d (slice 1 R3 fix) |
| T2 | 1 | ADR 0044 (criticals, Hunger dice, special results, step outcomes on a special, `push.maxDice`, `credits.badge`), ADR index, glossary; schema `v2.schema.json` and contract doc (fix the stale line 386) | delegated writer (Opus, design; preparation trigger: code reading for the design) | [x] | d100598 |
| T3 | 1 | Publisher: `Checks` (`criticals`, `hunger`, `maxDice`), flow `check` step outcomes with `special`, `credits.badge`, `DraftReferences` if Hunger formulas reference fields; unit tests and fixtures; Play's translator refuses the new shapes with a clear error | delegated writer (Opus) | [x] | 3318de9 |
| T4 | 2 | Kernel: dice groups, Hunger dice, critical pairs, special result, push capped at N and never on Hunger dice; unit tests | delegated writer (Sonnet) | [x] | ce45510 |
| T5 | 2 | Play backend: translator reads `criticals`, `hunger`, `maxDice`; `Check` perform and push; results and journal content (groups, special; legacy entries read); API; `make api`; tests | delegated writer (Sonnet) | [x] | 55fd791, 1f58d52 |
| T6 | 3 | Play UI: Hunger dice in a second colour, the special result in the roll dialog and the journal, push offer counts regular dice and shows the cap; fixtures and tests | delegated writer (Sonnet) | [x] | c5769a6 |
| T7 | 3 | Flow: `check` step outcomes on a special (translator, `CheckStep::outcomeFor`, `FlowRun`, pending check Keep/Push); tests | delegated writer (Sonnet) | [x] | 7a2ba3d (slice 2 fixes 2837504) |
| T8 | 3 | Credits badge in Play: translator, `Credits`, responses, the Dark Pack logo asset, `CreditsNotice`; tests | delegated writer (Sonnet) | [x] | 019a728 |
| T9 | 4 | Studio check editor: criticals, Hunger count, push cap; error paths; tests and story | delegated writer (Sonnet) | [x] | bd7e739 (slice 3 fix f6c9ade) |
| T10 | 4 | Studio sandbox: Hunger dice, criticals, special result, capped push (backend, views, API, UI); tests | delegated writer (Sonnet) | [x] | 9ec7929 |
| T11 | 5 | Studio flow editor: check step outcomes on a special; tests | delegated writer (Sonnet; writer trigger: 2+ non-trivial files) | [x] | efcc9f6 (slice 4 fixes 3ed1b59) |
| T12 | 5 | Studio metadata: credits badge; tests | delegated writer (Sonnet; same writer) | [x] | a731325 |
| T13 | 6 | Author `vtm-night-court` in Studio from the content spec, sandbox-roll Hunger and criticals, publish; log Studio findings | parent (browser, Claude in Chrome worker) | [x] | — (Studio only; recorded in the export) |
| T14 | 6 | Play a session (Session Zero, two nights, a world turn, a bestial failure branch, the masquerade roll-under); fix content and republish; log Play findings | parent (browser, Claude in Chrome worker) | [x] | — (v2 published from Studio) |
| T15 | 6 | Export to `backend/presets/vtm-night-court.json` unedited; `PresetsTest` (publishes, credits and badge, 13 checks with Hunger, criticals and the capped push, special step outcomes) | delegated writer (Sonnet) | [x] | dca4271, b3c49c2, 28a81bd (slice 5 fix 53f1ec2) |
| T16 | 7 | Studio and Play findings from T13–T14: fix the small ones, file the rest as a `follow-up` issue | delegated writer (Sonnet) | [x] | |
| T17 | 7 | Flow unit test (a messy critical on a Hunt, a bestial failure reaching the Compulsion, a failed Rouse raising Hunger, the masquerade roll-under forcing the raid, Dawn adding a night); e2e spec (credits badge, a Hunger roll in the journal) | delegated writer (Sonnet) | [ ] | |
| T18 | 7 | Wrap-up: findings fixed or filed; roadmap B10 done; this doc closed | parent (closing doc commit) | [ ] | |

## Acceptance criteria

- A release with `pool.criticals`, `pool.hunger`, `push.maxDice`, special step outcomes and `credits.badge` publishes; malformed shapes fail closed with a path; releases without them keep their content hash.
- Play rolls Hunger dice inside the pool, counts critical pairs, flags the special result, never re-rolls Hunger dice and re-rolls at most `maxDice` failed regular dice; journal entries stored before this feature read unchanged.
- A `check` step branches on a special result before its band, also after Keep or Push on a pending step.
- Studio edits and sandbox-rolls all of it.
- Play shows the Dark Pack logo with the notice for a release with the badge.
- `vtm-night-court` is in `backend/presets/`, publishes with `make presets` and plays example 1's flow.

## Checks

- Per slice: test first where a runnable test exists; `make verify` once at the end; CI runs every suite.
- Slice 6: Chrome authoring and playtest; `PresetsTest`; e2e.

## Studio findings

Authored 2026-10-27 by two Claude in Chrome workers as `night-author@ogami.test` (the first stopped on a browser-tool rate limit; ~1,350 browser actions in all, Scene Types ~30–40 each). v1 published, validation clean on the first run, no console errors. Deviations from the content spec: theme `noir` (no `eldritch`); the raid's "masquerade −3" is a tracker `add -3` (tracker effects have `add` and `set` only, as the contract says); Intrigue's pick had both `leader` and `rival` tags, which matches nobody (a pick needs all its tags), and the skill-check steps were optional: both fixed in v2. Tool artefacts (sign-in, coordinate clicks) are left out.

| ID | Finding | Severity | Plan |
|---|---|---|---|
| S3 | Overview: no `eldritch` theme among the offered themes | Low | Follow-up issue (T18) |
| S5 | Sheet: groups cannot be declared up front; their order follows the first field of each, then Move up / down one step at a time (rapid clicks scramble the order, S28) | Low | Follow-up issue (T18) |
| S8 | Oracles: likelihood levels cannot be reordered | Low | Follow-up issue (T18) |
| S9 | Oracle tables: an entry key is reachable only through each row's expander; Paste entries takes ranges but not keys | Low | Follow-up issue (T18) |
| S13 / S18 | Scene Types: every step, effect and Next is a custom select and each special result needs its own Add outcome, so a skill-check Scene Type takes ~30–40 actions | Medium | Follow-up issue (T18) |
| S16 / S25 | A Next select lists only steps that exist, so the Compulsion step (and the world turn's branch targets) must be created before the outcome that jumps to it | Low | Follow-up issue (T18) |
| S21 | Three Scene Types reported saved by the first worker had no steps; cause unknown (no save, or a lost save) | Medium | Follow-up issue (T18) |
| S22 / S30 | Saving a Scene Type shows no confirmation | Low | Fixed in T16: `SaveState` in the Scene Type editor, like the Flow editor |
| S23 / S19 | Custom selects drop typeahead typed within ~1 s of opening | Medium | Follow-up issue (T18) |
| S24 | Flow editor: after saving, a roll step whose band is bound to a tracker (`upTo {tracker}`) shows "a kind or shape this editor cannot edit yet", so the masquerade roll cannot be corrected in Studio | Medium | Fixed in T16: the preset's stored step reads fine; the editor refused an empty band sent as `[]` (see Progress) |
| S32 | Pick step: no hint that no tags means any NPC | Low | Follow-up issue (T18) |

## Playtest findings

Played 2026-10-27 by a Claude in Chrome worker as `night-author@ogami.test`: campaign "Night Court Test v2" on v2, Session Zero, a Hunt with a push (the failed regular die re-rolled, the Hunger die kept, Willpower 5 → 4), a bestial failure at Hunger 5 reaching the Compulsion table, Dawn (Rouse, night 1 → 2), world turns (a faction move, hunter activity, quiet nights), masquerade set to 10 forcing the Inquisition raid (its closing 10 → 7), Intrigue with the fixed pick and the optional Rouse (Hunger 0 → 1), Haven (Humanity 7 → 6), the session closing. Credits notice, Dark Pack logo and license link show in the new-campaign form and on the campaign. No messy critical came up in two Hunts (the sandbox showed one in T13); no push on a failed roll (its failed dice were Hunger dice, where Push is correctly not offered). No console errors; 122 `/api` calls all 200/201.

| ID | Finding | Kind | Severity | Plan |
|---|---|---|---|---|
| P1 | Closing Session Zero asks the chronicle's "Did you get what you wanted?" and opens Session 2 at once | UX | Low | Follow-up issue (T18) |
| P2 | Hunters strike is checked only after the hunter-activity event (as example 1), so a full Hunters clock filled another way never forces it | Content | Medium | Follow-up issue (T18) |
| P3 | Skipping a world-turn pick takes no branch, and the next prompt reads "What does  do toward their agenda?" with `{picked}` empty | Bug | Low | Open: an empty `{picked}` should render "—" in step text only (needs `FlowRun::view`), not in Effect text (names, Fact values); see Progress |
| P4 | Push is offered on a roll that already succeeded (ADR 0041 allows a push whenever a regular die failed) | UX | Low | Follow-up issue (T18) |
| P5 | The step card shifts after every action, moving Record and Roll | UX | Low | Follow-up issue (T18) |
| P6 | The world turn says "step n of 10" while it has 11 steps; the hunters condition leaves no journal entry | Bug | Low | No change: condition steps are not shown, counted or journalled by design (contract, ADR 0018) |
| P7 | The character step shows "Attribute points: 9 / 22" before any input (the defaults of 1 count) | UX | Low | No change: the budget includes the free first dot (9 attributes at 1); by design |

## Progress

- 2026-10-27: explored ADR 0021, 0041, `rules-dice-pools`, `preset-vaesen`, example 1; Dark Pack Agreement read (fits). Layer map by an explorer. User chose the badge enum, show-and-branch special results and a push cap. Issue #301.
- 2026-10-27: slice 1 written: ADR 0044 (criticals need a roll that does not fail; "fails" is below the difficulty, else the first of 2+ bands), schema and contract doc; Studio publishes the new parts, Play's translator refuses them; fixture `valid/v2-hunger-dice`.
- 2026-10-27: slice 2 commit 1: R3 fix, `Checks::specials()` treats a literal `hunger.count` 0 as no Hunger dice (a formula still enables them); unit test RED then GREEN.
- 2026-10-27: T4 kernel: `DieGroup` enum, `DicePool(hunger, criticalsBonus)`, `PoolRoll::total/criticalPairs/hasCriticalPair/hasHungerCriticalPair/hasHungerOne/isPushable`, `push($random, ?$maxDice)`. Special-result precedence stays in Play (T5): it needs "fails" (difficulty or first band), which only a check knows; the kernel states dice facts only. RED: new tests errored against the old kernel (19 errors, 7 failures); GREEN: 450 Randomness tests.
- 2026-10-27: T5 Play backend: translator reads `pool.criticals`, `pool.hunger` (number or formula) and `push.maxDice`; `Check` evaluates the Hunger formula like `pool.count` (422 path), computes `SpecialResult` per ADR 0044 (fails = below the difficulty, else first of 2+ bands) on perform and push; journal pool part gains `criticalsBonus` and `special`, push part `special` (both left out of the stored JSON when 0 or none, so legacy entries read and rewrite unchanged); the pool part checks groups (Hunger dice last), the total with the bonus, special against the dice, and that a push re-rolls the first failed regular dice only. API: `special` on the entry, the pool part and the push part, `criticalsBonus` on the pool part. The push 409 message now says "No regular die of the roll failed". RED for the translator: 3 errors and 14 failures against the old translator; the journal and `Check` tests were written with the code.
- 2026-10-27: `make api` regenerated `frontend/src/shared/api/openapi.json` and `schema.d.ts`; the spec lives under `frontend/`, outside the brief's edit surfaces (it named `backend/openapi.json`), so both are in their own `chore(api)` commit for the parent to confirm.
- 2026-10-27: slice 3 commit 1 (slice 2 review findings): `PoolRoll::ofFaces` restores `array_values` (RED: a non-list array put every die in the wrong group, then GREEN); tests written against already-correct behaviour: a Hunger formula below 0 rolls no Hunger dice and never fails (`Check`), a roll whose only failed dice are Hunger dice never pends (`FlowRun`, `NoPendingCheck` on Keep and Push) and Push is a 409 over the API; a failed regular die among failed Hunger dice pends and Keep works. `Checks.php` stays: it already reads `['count' => 0] !==`, no `> 0` check is left.
- 2026-10-27: T7 flow outcomes on a special: `CheckStep::specialOutcomes` and `outcomeFor($band, ?$special)` (a special entry wins, no fallback from `messyCritical` to `critical`); `PendingCheck` keeps the rolled `special` (stored as `special`, rows without it read null) and `FlowRun::resolvePendingCheck` takes the pushed roll's special, so a push recomputes it with the band; the translator reads `outcomes[]` entries naming `special` and fails closed with a path (unknown, twice, both or neither of band and special, a special the check cannot produce via `Check::canProduce`, for every check of a set). RED: the translator refused specials (27 failures and the new API test class erroring); GREEN: unit and integration Play suites (3,367 tests).
- 2026-10-27: T8 credits badge: `CreditsBadge` enum (`dark-pack`) on `Credits`, the translator reads `badge` (unknown or non-string fails closed with the path; the `refuseUnplayed` helper is gone), `CreditsResponse.badge` (nullable enum) on the catalog and the pinned release, `make api`; the SPA maps each badge to its image in `shared/credits/badges.ts` (a `Record` over the API enum, so a new badge fails to compile until its image is added) and `CreditsNotice` shows it at 48px on a white disc, which keeps the black logo readable in dark mode. Tests written with the code for the backend; the Vitest cases were RED before the component change (no `img`), then GREEN.
- 2026-10-27: T6 Play UI: the check definition's push response gains `maxDice` (nullable; the API test was written with the code), `make api`; Hunger dice get a second colour (`border-destructive`), a "Hunger die" label and `data-group`; the special result is text ("Special result: Messy critical") in the journal entry and the roll dialog, and the push line names the first roll's ("Rolled Failure (bestial failure), pushed to Win"); `canPush` counts failed regular dice only (`failedRegularDice`), `PushOffer` shows "Re-roll up to N failed dice." and that Hunger dice are never re-rolled, `PendingCheckForm` shares both; the roll dialog stays open on a roll with a special result even when nothing can be pushed. RED: the new Vitest cases (journal, `canPush`, dialog, pending step) failed against the old components, then GREEN (596 Play tests). Stories for the Hunger entries.
- 2026-10-27: slice 4 commit 1 (slice 3 review finding): `CreditsNotice` looks the badge up by any string and shows the notice without a logo for one this build does not know. RED: the Vitest case crashed on the undefined entry, then GREEN.
- 2026-10-27: T9 Studio check editor: `PoolDraft.criticalsBonus` / `hunger` and `PushDraft.maxDice` are optional text inputs (empty or never set stays absent, so stored checks without them save byte-identical); `readPool`/`readPush` accept the new keys, `writePool`/`writePush` enforce 1..10, 0..100 or a formula, and 1..100; `checkError` maps `pool.criticals(.bonus)`, `pool.hunger.count(.formula)` and `push.maxDice`; the pool editor gains "Critical pairs bonus" and a "Hunger dice" formula input, `PushEditor` a "Dice cap"; `GameSystemCheckPool` / `GameSystemCheckPush` document the fields, `make api`. The checks GET already returns the stored JSON as is. Vitest and the API tests were written with the code (the publisher already accepted the shapes); story `HungerAndCriticals`.
- 2026-10-27: T10 Studio sandbox: `CheckTestPool` reads `criticals.bonus` and `hunger.count` (number or formula, evaluated on the samples like `count`; the kernel clamps it, so a negative formula rolls no Hunger die), `CheckTestPush` reads `maxDice`; malformed values are `CheckTestRollFailed::unreadable`. `perform` builds the Hunger-aware `DicePool` and totals with `PoolRoll::total()`; `CheckTestSpecialResult` (backed enum, pure `of(PoolRoll, bool $failed)`) mirrors Play's precedence, and `CheckTestRoll::specialOf` its "fails" rule (below the difficulty, else the first of 2+ bands). The stateless push rebuilds the roll with Hunger dice last, re-rolls at most `maxDice` failed regular dice, recomputes the special and tells the first roll's (`push.rolledSpecial`); a roll whose only failed dice are Hunger dice is the 409 "no regular die failed". Responses: die `group`, pool `hunger`/`hungerFormula`/`criticalsBonus`, roll `special` (OpenAPI enum), push `maxDice`/`rolledSpecial`; `rolledSuccesses` is now the first roll's total. `make api`. UI: Hunger dice in the second colour with a "Hunger die" label, "Special result: …", the push line names the first roll's special, the push offer counts failed regular dice and says "Re-roll up to N failed dice." Unit and API tests were written with the code (the new API cases passed on first run against it); the existing JSON assertions were the RED signal for the new fields.
- 2026-10-27: slice 5 commit 1 (slice 4 review findings): `readPool` / `readPush` read a stored `null` criticals, Hunger or push cap as absent and the write leaves it out (RED: the check was unreadable, then GREEN). `CheckTestPool` already took 0 as "no critical pairs" (the default) and the reader refuses a stored 0, so its message now says "0 for none or in 1..10" (RED: the old message, then GREEN).
- 2026-10-27: T11 flow editor special outcomes: `CheckOutcomeDraft` is a band or a `special` outcome (an entry naming both, neither or an unknown special stays a stored step); `setCheckOutcome` writes bands in the check's order, then critical, messyCritical, bestialFailure, strays last, and `setBandOutcome` wraps it. `StepContext.checks[].specials` (optional) comes from `specialsOf` over the checks read model (`stepContext(narrative, checks)`; a literal Hunger count 0 is no Hunger dice) and the Flow and Scene Type editors load `useGameSystemChecks`, so no endpoint was added; a set offers the intersection, and while the pools are unknown nothing is offered or flagged. "Special results" group under the bands with Add / Remove, strays say "This check cannot produce this special result."; `outcomes[n].special` errors already map to the row. Vitest and the story were written with the code; the suite passed on first run, apart from one test of mine fixed before it counted.
- 2026-10-27: T12 Studio credits badge: `GameSystemCreditsRequest` / `GameSystemCreditsResponse` gain a nullable `badge` (OpenAPI enum from `ReleaseVersion2::BADGES`); the controller accepts a string or null and 400s any other type like the license, the domain refuses an unknown badge (422 naming `credits.badge`), a null or absent badge clears it, `make api`. `MetadataForm` has a "Badge" select (None / Dark Pack, an unknown stored one stays selectable) with the logo beside it, leaves `badge` out of the request when none is picked, and a badge needs a notice. API, unit and Vitest tests (new `MetadataForm.test.tsx`) were written before the code for the backend (RED: badge missing from the answer, unknown badge accepted) and with the component for the frontend; story `MetadataForm`.
- 2026-10-27: slice 6 commit 1 (slice 5 review findings): `FlowEditorPage` test proves the checks read model reaches `stepContext` (a World turn check step on a Hunger check with criticals offers Critical, Messy critical and Bestial failure). No production change: the test passed on its first run against the existing wiring; mutation check (dropping `checks.data` from `stepContext` in `FlowEditor.tsx`) made it fail, then restored. The oracle table finding needs no change: a table entry holds a Scene Type and effects (`EntriesEditor`), never steps, so no check step can sit there and `OracleTableEditorPage` needs no checks.
- 2026-10-27: T13 done: `vtm-night-court` v1 authored in Studio from the content spec and published (export 2,361 lines, above the ~1,900 forecast); sandbox showed Hunger dice, a messy critical, a capped push that kept the Hunger dice, and Rouse failing and succeeding. Findings S3–S32 above.
- 2026-10-27: T14 done: v2 (Intrigue pick without tags, skill-check steps mandatory) played end to end; findings P1–P7 above. No content change was needed to play.
- 2026-10-27: T15 done: exported v2 unedited to `backend/presets/vtm-night-court.json` (2,363 lines); a re-export to a scratch path is `cmp`-identical; `make presets` prints `Unchanged vtm-night-court (v2, managed in Studio)`. `PresetsTest` gains one test (10 tests, 580 assertions) that publishes it once and reads Play's snapshot: credits notice, license, URL and `dark-pack` badge; 13 checks, 12 Hunger pools of attribute + skill (d10, success 6, criticals 2, Hunger `{hunger}`, push "Spend Willpower" capped at 3 dice, costing 1 Willpower), Rouse as a plain 1-die pool; the 22/14 point budgets; the Hunt's `bestialFailure` outcome to the Compulsion step and its `messyCritical` outcome; the world turn's Second Inquisition roll banded on the Masquerade tracker. Live proof: the test was temporarily changed to expect 23 attribute points, failed, and was restored (no RED-first, the file already existed).
- 2026-10-27: slice 7 commit 1 (slice 6 review findings): R3-001 `PresetsTest` asserts the Hunt's `messyCritical` effects (Hunger −2, masquerade +1) and the failure band's masquerade +1; characterisation (passed on its first run). R3-003 / P3 not done: the dash cannot live in `Placeholders` alone, because `FlowRun::renderedEffectText` (line 1339) shares it and an Effect's name or `fillFact` text must stay empty (a naive change broke `FlowRunEffectsTest` and `FlowRunFactsTest`); it needs a flag set by `FlowRun::view` (line 718), outside the writer's surfaces.
- 2026-10-27: T16 done. S22/S30: the Scene Type editor shows `SaveState` (saved at, unsaved changes, saving, not saved: n errors) like the Flow editor; RED: no "Saved" status after a save, then GREEN; a refused save now adds a second alert beside Save, so the page-message tests read the first alert. S24: the step as stored in `vtm-night-court.json` reads and saves back byte-identical (characterisation, passed on first run). The band `{}` is what fails once it arrives as `[]` (the server decodes stored content to arrays, so an empty band is sent back as `[]`; inferred from `Bands.php:59` and the json column, not confirmed in a browser): `readBand` rejected it, so the whole step fell to `stored`. `readBand` now reads an empty array as an empty band (RED: step `stored`, then GREEN).

## Reviews

| Slice | Lineage | Commits | Outcome | Findings | PR |
|---|---|---|---|---|---|
| 1 | `review-32fb84966e5a47d6` | d100598, 3318de9 | approved, acknowledged | R3 warning: a literal `hunger.count` 0 still accepts `bestialFailure` / `messyCritical` step outcomes (`Checks.php:147-150`); fixed in slice 2. Note: Studio's sandbox (`CheckTestRoll::readPool`) ignores criticals and Hunger until slice 4 | [#302](https://github.com/iribarren/ogami/pull/302) |
| 2 | `review-92d91f85ad4060fc` (main..ce45510), `review-095722f7f9626921` (ce45510..1f58d52); 1,936 lines, size accepted by the user | a219e5d, ce45510, 55fd791, 1f58d52 | approved, acknowledged (both) | No test for a negative Hunger formula (the pool clamps it to 0; must not 500); no flow-level test that a roll whose only failed dice are Hunger dice is not pushable on a pending step (`FlowRun::pushable`); `PoolRoll::ofFaces` dropped `array_values` (list contract); optional: `Checks.php:195` literal Hunger `> 0` where the schema already refuses negatives. Fixed in slice 3 | [#303](https://github.com/iribarren/ogami/pull/303) |
| 3 | `review-742c3094e8c4a81d` | 2837504, 7a2ba3d, 019a728, c5769a6 | approved, acknowledged | R3 suggestion: `CreditsNotice.tsx:17` crashes when the API sends a badge this SPA build does not know (`creditsBadges` lookup undefined); fall back to no logo, with a test. Fixed in slice 4 | [#304](https://github.com/iribarren/ogami/pull/304) |
| 4 | `review-8f746d03d236f101` | f6c9ade, bd7e739, 9ec7929 | approved, acknowledged | Warning: `checkDraft.ts` `readPool` / `readPush` refuse a stored explicit `null` for `criticals`, `hunger` or `maxDice`, which the contract documents as nullable and the sandbox reads as absent. Suggestion: `CheckTestPool.php:41` accepts a critical bonus of 0..10 but its message says 1..10. Fixed in slice 5 | [#305](https://github.com/iribarren/ogami/pull/305) |
| 5 | `review-618ddda709b5a87d` | 3ed1b59, efcc9f6, a731325 | approved, acknowledged; 1,191 lines | R3 suggestion: the checks wiring of `FlowEditor.tsx:78-80` into `stepContext` has no flow-editor page test (the Scene Type editor page has one). Fixed in slice 6 (53f1ec2). A review note that `OracleTableEditorPage` offers no special outcomes was moot: table entries hold a Scene Type and effects, never steps | [#306](https://github.com/iribarren/ogami/pull/306) |
| 6 | `review-b01d06f9303cb135`; 2,534 lines (export 2,363), size accepted by the user | 53f1ec2, dca4271, b3c49c2, 28a81bd | approved, acknowledged | R3-001: `PresetsTest` does not assert the Hunt's `messyCritical` effects (Hunger −2, masquerade +1) nor the failure's masquerade +1. R3-002: the raid's masquerade `add -3` (`vtm-night-court.json:1102-1108`) relies on the tracker clamp at 0, untested. R3-003: Intrigue's optional pick, when skipped, leaves `{picked}` empty in the next prompt (as P3). Fixed or filed in slice 7 | [#307](https://github.com/iribarren/ogami/pull/307) |

## Next step

Slice 7: the writer fixes slice 6's review findings (Hunt outcome effects in `PresetsTest`, the empty `{picked}`), T16 (Scene Type save confirmation, S24) and T17 (flow unit test with the raid's clamp, e2e); then review, PR, and the closing doc commit with the follow-up issues (T18).
