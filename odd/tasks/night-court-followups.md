# Feature: night-court-followups

- **Locator:** `odd/tasks/night-court-followups.md` · Engram topic `odd/night-court-followups/tasks`
- **Issue:** [#309](https://github.com/iribarren/ogami/issues/309) · **Branch:** `feat/night-court-followups-2-play-content` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `vaesen-ux-followups` (PRs #354–#355)

## Objective

Roadmap backlog B7n. Fix the UX and content findings logged while authoring and playing Night Court in [`preset-vtm-chronicle`](preset-vtm-chronicle.md#studio-findings). None blocks play.

Out of scope: S21, the Scene Types saved without steps ([#310](https://github.com/iribarren/ogami/issues/310), B7o), and tall step cards ([#356](https://github.com/iribarren/ogami/issues/356), B7p).

## Problem and why

- Studio: a skill-check Scene Type takes ~30–40 browser actions, a branch target must exist before the outcome that jumps to it, and sheet group order and table entry keys are slow to set.
- Play: Push on a success does not say why it helps, the step card shifts its buttons, and closing Session Zero asks a question about a session the phase never opened.
- Content: the hunters strike never fires for a full clock filled outside the hunter-activity event.

## Decisions

| Finding | Decision | By |
|---|---|---|
| S13 | One button adds every special result the pool can roll, each a copy of a band's outcome: critical and messy critical from the top band, bestial failure from the lowest. A single Add prefills the same way. A copy does not follow later band edits | User, 2026-11-02 |
| S16 | The Next select gains a "New step…" entry that appends a step and points at it | User, 2026-11-02 |
| P4 | Copy only: on a success the offer says what the push can still change and what it risks. The rule, ADR 0041 and ADR 0044 stay. The offer shows this on every pool roll: a band check has no difficulty, so the frontend cannot tell a success, and the copy is true either way | User, 2026-11-02; every pool roll by parent, after the Chrome pass |
| P1 | Engine rule: skip a phase's closing when this session's opening did not run in that phase. No schema change. Applies to existing releases. Reproduce "opens Session 2 at once" first: no code found that does it. Accepted side effect: a West Marches founding session that ended far from town no longer rolls the stranded table | User, 2026-11-02 |
| P5 | The Flow outcome moves below the step card, and in the Journal view the page scroll follows the Journal's growth, so a new entry above does not move the step card. A step's own content can still differ in height between steps | Parent, after the Chrome pass |
| S8 | Already fixed by `bb68fdbd` (`vaesen-ux-followups` T9); no work | Parent |
| S3 | `eldritch` has been offered since `c332a882`, before the authoring run, so there is no code gap. Confirm it in the form, then move Night Court from `noir` to `eldritch` with P2's release, as its content spec asked | Parent |
| S9 | Paste takes an optional `[key]` before the entry text, only on a schema version with entry keys; anything else in brackets is refused per line. A row with a key shows it while collapsed. The input stays in the expander | Parent |
| S5 | Move to top and Move to bottom join Move up and down for groups. Declare layout on a sheet with no fields is dropped: the backend refuses it on purpose (`GameSystem::declareSheetLayout`). The scramble is read as rows swapping under a still pointer, not a version race (not reproduced) | Parent; drop by user, 2026-11-02 |
| S23 | Time-boxed Playwright repro with real key events first. Fix only if it reproduces; otherwise record it as not reproduced and likely a browser-tool artefact | Parent |
| P2 | Point the 8 `next: end` exits of the world turn at the single `hunters` condition, and move the `inquisition` roll after it, as the turn's last step. The last `nextScene` of a turn wins, so a raid overrides a strike in the same turn; the clock stays full, so the strike comes on the next turn. Every turn keeps its night event. The clock's reset prompt becomes mandatory, since skipping applies no effects and the clock would strike every turn | Parent; step order by user, 2026-11-02 |

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/night-court-followups-1-studio` | `feat(studio): night-court-followups 1/2 authoring cost` | T1–T6 | ~800: this doc ~110; S32 ~15; S9 ~120; S5 ~120; S13 ~130; S16 ~250; S23 0–60 |
| 2 | `feat/night-court-followups-2-play-content` | `feat(play): night-court-followups 2/2 play and content` | T7–T11 | ~500: P4 ~30; P5 ~80; P1 ~150; P2 and S3 ~100; closing ~100 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | S32: a pick step's hint says no tags means any NPC, and an NPC must have all the tags | delegated writer (slice 1) | [x] | `78574800` |
| T2 | 1 | S9: `[key]` in Paste entries; a row with a key shows it while collapsed | delegated writer (slice 1) | [x] | `7c3668e0` |
| T3 | 1 | S5: Declare layout on a sheet with no fields; Move to top and bottom for groups | delegated writer (slice 1) | [x] (b only) | `273691d2` |
| T4 | 1 | S13: add every special result at once, prefilled from a band; a single Add prefills too | delegated writer (slice 1) | [x] | `4f0210fa` |
| T5 | 1 | S16: "New step…" in the Next select | delegated writer (slice 1) | [x] | `bdf8c4a4` |
| T6 | 1 | S23: reproduce the dropped typeahead; fix only if it reproduces | delegated writer (slice 1) | [x] | `a941f2b0` |
| T7 | 2 | Slice 1 review findings | delegated writer (slice 2) | [x] | `b27bc600` |
| T8 | 2 | P4: the push offer on a success says what it can change and risks | delegated writer (slice 2) | [x] | `9e482ef4`, `f304dfb4` |
| T9 | 2 | P5: the step card's actions stop shifting | delegated writer (slice 2) | [x] | `220efbf4`, `c13c9565`, this task\'s last commit |
| T10 | 2 | P1: skip a phase's closing when this session's opening did not run in that phase | delegated writer (slice 2) | [x] | `587f8306` |
| T11 | 2 | P2, S3: re-author Night Court in Studio, re-export; tests for the strike after a clock filled elsewhere | delegated writer (slice 2); Studio by Chrome worker | [x] | `c81e84f9` |
| T12 | 2 | Chrome pass: a step clicked in the Studio outline scrolls into view, as it does on a reload | parent, inline: one line, understood | [x] | this slice's last commit |
| T13 | 2 | Slice 2 review findings, on the last slice's branch | parent, inline: small and understood | [x] | this slice's last commit |

## Acceptance criteria

- A check step with special results gets all their outcomes in one click, and no band effects are silently dropped.
- A branch target can be created from the Next select.
- Pasted lines can set entry keys; a bad key fails the paste with its line number.
- Groups can be moved to either end in one click.
- A full Hunters clock forces the strike however it was filled, and never twice for one fill.
- Closing Session Zero does not ask the chronicle's closing question.

## Checks

`make verify` per slice (`rtk proxy make verify`: the filtered output can hide failures), CI as the full run. Chrome pass on slice 2 for P4, P5, P1, P2 and S3.

## Progress

- 2026-11-02: explored all findings against `main` at `c4545576`, reading code only. S8 is already fixed and S3 is not a code gap; S13 is partly fixed (band rows appear, Expand all exists); the rest are present, with S23 and the second half of P1 not yet reproduced. Slice plan written.
- 2026-11-02 T1: pick step hint added (`PickFields`), worded from `Campaign::pickCandidates` (open items only; `carries` all tags; empty tags match all). No RED observed: written with the code; the new Vitest case passes.
- 2026-11-02 T2: `parsePaste(.., withKey)` reads `[key]` (rule `^[a-z0-9-]{1,64}$`, as the other Studio key checks; the entry key input has no client rule, the server decides); duplicates in the paste always fail, against the table only when appending; collapsed row shows the key. No RED observed: tests written with the code; all pass.
- 2026-11-02 T3: (b) Move to top / Move to bottom for groups, disabled at the ends. (a) left out: the backend refuses declaring a layout on a sheet with no fields (`GameSystem::declareSheetLayout`, "add a field first", asserted in `GameSystemSheetApiTest`); dropped by the user. No RED observed: tests written with the code; all pass.
- 2026-11-02 T4: "Add all special results" (two or more rollable specials without an outcome) and a prefilled single Add, via `specialStart`/`addSpecialOutcomes` in `stepDraft.ts`. Top band = last of the check's `bands`, lowest = first (glossary: the last band catches the rest; ADR 0044: the first band is the failing one). A source band with no outcome gives an empty copy, which is not saved, as for any empty outcome. No RED observed: tests written with the code; all pass.
- 2026-11-02 T5: "New step…" in every Next select (`NextPicker`, so steps, choice options, oracle answers, table entries, roll bands, check bands), asking the kind in the Add step menu's own items (`StepKindMenuItems`). One callback through `NewStepContext`: `create(kind)` stages the step and the next `change` appends it with the link, so the edit is atomic. No RED observed: tests written with the code; all pass.
- 2026-11-02 T6: not reproduced with real key events; likely a browser-tool artefact. Tried in Playwright (Chromium, stack up): focus the entry nested-table select, Enter to open, type the first 3 letters of each of 3 options at 50ms, 0ms and 120ms per key, 18 runs; the matching option was highlighted and focused every time. Throwaway spec deleted. Found instead, checking T5 in a real browser: inside a `<form>` Radix echoes `''` from its hidden native select when `value` becomes an option that is new in that render, which undid "New step…" (jsdom tests without a form pass). `select.tsx` now ignores that echo while the commit settles; RED observed on a new `select.test.tsx` case (changes recorded `['']`), GREEN after the fix; re-checked in Playwright: Next points at the new step, and Escape leaves Next as it was.
- 2026-11-02 T7: (W) the New step kind menu returns focus to the Next select on close (`onCloseAutoFocus` on the menu content, the select found by its id); RED observed: the cancel test's new `toHaveFocus` failed before the fix. The harness needed no `<form>`: the focus loss reproduces outside one. (S) `parsePaste`: without `withKey` brackets are plain text and never refused; with it a leading `\[` is a literal bracket (backslash dropped), any other bracket is still refused per line, and that message and the Paste hint name the escape. No RED observed for the paste cases: tests written with the code; all pass.
- 2026-11-02 T8: `PushOffer` adds a note on a pool roll that already succeeded, worded from ADR 0041 (a push re-rolls failed dice, successes stay, the band is chosen again, the cost applies whatever the dice show, once per roll, Keep costs nothing) and ADR 0044 (critical recomputed). The entry has no success flag and the check's bands are not in the view, so `rollSucceeded` reads a critical or a total at or above the difficulty (ADR 0044's failure rule); a check with no difficulty is not known and keeps the old copy (e.g. Vaesen: it shows the offer without the note). Failure copy unchanged, no API change. No RED observed: tests written with the code; all pass.
- 2026-11-02 T9: cause: the Flow outcome section was mounted above the step card only while the newest journal entry is a flow outcome, so it pushed the card (and Record or Roll) down on one action and pulled it back on the next. Moved below the cards in `GuidedBody`. RED is not available (jsdom has no layout); the existing order test now asserts the outcome follows the card in the DOM. Other content above the card (phase description, Tips, Start session) changes per scene or phase, not per action. The parent checks the real layout in Chrome.
- 2026-11-02 T10: `FlowRun` keeps `sessionOpenedInPhase` (nullable int, set where `reachScenePick` consumes `sessionOpeningDue`, empty opening included, reset to null by `sessionStarted`); `endSession` runs the session closing hook as skipped (`runHook(.., skipped)`, the existing empty-hook path) when it is set and differs from `phaseIndex`. Persisted in the FlowRun JSON (`CampaignFlowRunType`): absent reads as null, so legacy rows run the closing as before; a non-int, negative or past-the-current-phase value fails to load, and the domain refuses it in `reconstitute`. No schema or DB change; no API change (`nextLabel` and the view never named a closing). RED observed on the new `FlowRunSessionClosingTest` with the skip disabled (6 of 7 failed). `WestMarchesPresetFlowTest` encoded the old behaviour (the founding session's closing in the expedition phase) and was updated: the founding session now ends at once, so its stranded check no longer runs if the player travelled in it. "Opens Session 2 at once": not reproduced. Only `POST /sessions` starts a session (`StartSessionHandler`, no other caller); `Campaign::endSession` never calls `startSession`; the guided end path leaves the FlowRun waiting for a session; the frontend End session button only calls the end mutation. The new tests assert no second session exists after the end. Glossary updated; ADR 0018 lists hook names, not run rules: the end-session rule is in ADR 0020, left untouched for the parent.
- 2026-11-02 T8 correction: the Chrome pass saw no note: Night Court checks are band checks with `difficulty: null`, so `rollSucceeded` read real successes as failures. `PushOffer` now shows the note on every pool roll, worded to read true on a success and a failure, plus one Hunger sentence (a new critical paired with a Hunger die is a messy critical, `Check::specialOf`) only with Hunger dice; `rollSucceeded` and its tests deleted. Burns unchanged. No RED observed: tests written with the code; all pass.
- 2026-11-02 T9 correction: the Chrome pass measured the Roll button moving +92 px per action in the Journal view: `GuidedPanel` sits below the Journal, so each entry pushes it down. New `useKeepPanelInPlace(active)` (`flow/`): a `ResizeObserver` on a wrapper around `CampaignJournal`; on a height change it calls `window.scrollBy` by the delta when the panel was on screen before the change (its top now minus the delta); first measurement skipped; no `ResizeObserver` means no-op; disconnects on unmount, inactive in focus mode. The only other scroll on an action is `reveal` in `Journal.tsx` (`scrollIntoView({block: 'nearest', behavior: 'smooth'})` on the entry just recorded): it only moves the page when that entry is off screen, so they agree whenever the panel is below the viewport top; near the top edge they can fight. Not changed (outside the surface). No RED observed: tests written with the code; jsdom has no layout, the parent re-measures in Chrome.
- 2026-11-02 T11: Night Court v3 authored in the local Studio by a Chrome worker (8 Next targets at `hunters`, `inquisition` last, `hunters-strike` closing `prompt-1` mandatory, theme `eldritch`) and copied byte for byte to `backend/presets/vtm-night-court.json`. `make presets` stops at `lanterns-of-the-mire` (authored in Studio in the dev database: pre-existing, unrelated); publishing the file alone reports `Unchanged vtm-night-court (v3, managed in Studio)`. `VtmNightCourtPresetFlowTest` follows the new order (the turn starts at `stir`; the raid tests no longer pass through `inquisition` first) with no assertion weakened, and new tests: a clock filled by the tracker strikes on a quiet night and on the arrival route; the hunter-activity event still strikes at 4; the strike's closing cannot be skipped and sets the clock to 0, so the next turn forces no strike; a raid and a full clock in one turn force the raid, keep the clock at 4 and strike on the next turn. `PresetsTest` asserts theme `eldritch`, `stir` first and `inquisition` last, and the mandatory reset. RED is not observable: the export was in before the tests were written. ADR 0020 and `flow-examples.md` amended.
- 2026-11-02 Chrome pass on v3: P1 (Session Zero ends at once, no second session; a later session asks the closing), P2 (a strike from a clock filled by hand, the reset cannot be skipped, no second strike; a raid wins, the strike follows), P4 and S3 pass. The messy critical sentence was not seen in Chrome (no push offered on a Hunger pool in the rolls made); Vitest covers it. P5 fails twice: the journal's smooth `reveal` fought the compensation, and the Journal's load counted as growth (scrollY 0 to 11,080).
- 2026-11-02 T9 correction 2: `reveal` no longer animates, and the compensation starts once the Journal has loaded; reloads stay at scrollY 0. P5 evidence is limited: settled positions match on rolls and record, but two steps that change the scene ended 59 px off, and timer samples show a 100–150 px peak for 0.3–1 s. The automated tab renders at about 1 frame per second (gaps of 1,017 ms measured), which matches that window, so the peak is probably never painted at a normal frame rate; not confirmed by eye. Focus view is stable.
- 2026-11-02 T12: the outline's step click ended at the top because the router's scroll reset undid the step's `scrollIntoView`. `select` skips the reset for a step node. Checked in Chrome: two steps scroll into view, flow settings go back to the top. No Vitest: jsdom has no scroll and the router reset does not run there.
- 2026-11-02 `make presets` on the authoring database stops at `lanterns-of-the-mire`, before Night Court; publishing the Night Court file alone gives `Unchanged vtm-night-court (v3, managed in Studio)`. Not investigated: this slice does not touch that file.
- 2026-11-02 T13: `useKeepPanelInPlace` asks whether any of the panel was on screen, not only its top, and leaves the page still while focus is inside the Journal; RED observed on both new Vitest cases, GREEN after. `PresetsTest` asserts no world-turn step before `hunters` targets `end` and counts its 9 routes in; written against the export, so no RED. Not changed: a new entry that would not fit is revealed first and the panel moves by that distance, which is the intended priority.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 | `review-f7592762099db9bc` | Approved and acknowledged, 1,114 lines | Fixed in T7: (W) `BandsEditor.tsx` closing the New step kind menu drops keyboard focus; (S) `pasteEntries.ts` refuses text starting with `[`, with no escape. S5(a) dropped by the user | [#357](https://github.com/iribarren/ogami/pull/357) |

## Next step

Slice 2: review, PR, closing doc commit.
