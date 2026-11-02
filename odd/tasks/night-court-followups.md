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
| P4 | Copy only: on a success the offer says what the push can still change and what it risks. The rule, ADR 0041 and ADR 0044 stay | User, 2026-11-02 |
| P1 | Engine rule: skip a phase's closing when this session's opening did not run in that phase. No schema change. Applies to existing releases. Reproduce "opens Session 2 at once" first: no code found that does it | User, 2026-11-02 |
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
| T7 | 2 | Slice 1 review findings | delegated writer (slice 2) | [x] | |
| T8 | 2 | P4: the push offer on a success says what it can change and risks | delegated writer (slice 2) | [x] | |
| T9 | 2 | P5: the step card's actions stop shifting | delegated writer (slice 2) | [x] | |
| T10 | 2 | P1: skip a phase's closing when this session's opening did not run in that phase | | [ ] | |
| T11 | 2 | P2, S3: re-author Night Court in Studio, re-export; tests for the strike after a clock filled elsewhere | | [ ] | |

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

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 | `review-f7592762099db9bc` | Approved and acknowledged, 1,114 lines | Fixed in T7: (W) `BandsEditor.tsx` closing the New step kind menu drops keyboard focus; (S) `pasteEntries.ts` refuses text starting with `[`, with no escape. S5(a) dropped by the user | [#357](https://github.com/iribarren/ogami/pull/357) |

## Next step

Slice 2: T7–T11 on `feat/night-court-followups-2-play-content`.
