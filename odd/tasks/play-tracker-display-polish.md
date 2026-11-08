# Feature: play-tracker-display-polish

- **Locator:** `odd/tasks/play-tracker-display-polish.md` · Engram topic `odd/play-tracker-display-polish/tasks`
- **Issue:** [#353](https://github.com/iribarren/ogami/issues/353) · **Branch:** `fix/play-tracker-display-polish-1-display` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-ironsworn` (#341, PR #352), whose Chrome play pass found these

## Objective

Roadmap backlog B19. Fix six small display issues in Play and the Studio check sandbox: tracker level names outside the panel, a Threads badge that counts closed Threads, a cancelled action die that reads as rolled, "None" beside "Skip" on a choice, a Character select with one Character, and the sandbox's burn offer without its dice.

## Problem and why

- `{tracker:x}` placeholders are rendered on the backend (`Placeholders.php:54`) from an int-only map, and the journal's tracker note (`JournalEntryView.tsx:263`) has no tracker definitions. Only the panel uses `Tracker::levelAt()`, so Ironsworn's prompts say `rank 2` and the journal reads `Vow 1 rank 2 → 0`.
- `CampaignPage.tsx:212` passes `threads.length` to the badge while `ListPanel` hides closed items under "Closed (n)". Places and NPCs pass their full lists the same way.
- `JournalEntryView.tsx:326-330` always renders the action die's dice expression; the scoring already counts a cancelled die as 0. `RollCheckDialog` renders `JournalEntryView`, and the Studio sandbox (`CheckSandbox.tsx:599`) shows the same.
- A `ChoiceStep`'s `skip` is the key of one of its own options and skipping applies that option, so the option and the Skip button do the same thing. The API does not expose `skip`.
- `SheetCharacterSelect.tsx:33-50` always renders, though `effectiveCharacterId.ts` already picks the only Character.
- The sandbox's burn offer (`CheckSandbox.tsx:522`) gives the new score only; Play's `BurnOutlook` (`PushOffer.tsx:78`) also says how many challenge dice it beats.

## Decisions

| Item | Decision | By |
|---|---|---|
| 1 placeholder | `{tracker:x}` in text renders the level label when the tracker has `levels` (`Dangerous`), otherwise the number. Formulas keep numbers | Parent |
| 1 journal | The tracker note shows value and label as the panel does: `Vow 1 rank 2 Dangerous → 0 Free`. Labels come from the pinned release's trackers passed to `JournalEntryView`, so stored entries read the same; no API change | Parent |
| 1 presets | Ironsworn's "Pick a slot with rank 0, which holds no vow" becomes "Pick a Free slot, which holds no vow"; `(rank {tracker:vow-N-rank}, …)` stays and reads `rank Dangerous`. West Marches' "The threat is {tracker:threat}." reads `Tough` and stays. `make presets` after editing | Parent |
| 2 | Every `ListPanel` badge counts open items only (Threads, Places, NPCs) | Parent |
| 3 | A cancelled action die's expression is shown struck through (`<del>`) beside the existing "counts 0" note, in the journal (so the dialog too) and in the sandbox | Parent |
| 4 | `FlowStepResponse` leaves out the option whose key equals the step's `skip`; the Skip button stays and the journal still records that option. Hiding Skip instead would need `skip` in the API | Parent |
| 5 | `SheetCharacterSelect` renders nothing with one Character; the submit still sends its id | Parent |
| 6 | The sandbox's burn offer adds Play's sentence ("It would beat N of M challenge dice, against K with the roll") using the sandbox's own `diceBeatenBy`, exported. No Studio import from `play/` | Parent |

## Constraints

- No API or OpenAPI change.
- Campaigns stay pinned to their release: prompts change for new Ironsworn and West Marches releases only; placeholders and the journal change for every campaign.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/play-tracker-display-polish-1-display` | `fix(play): play-tracker-display-polish 1/1 tracker levels and display fixes` | T1–T6 | ~350: this doc ~80; T1 ~110; T2 ~15; T3 ~35; T4 ~25; T5 ~25; T6 ~25; e2e and fixtures ~35 |

## Tasks

| ID | Task | Route | Done | Commit |
|---|---|---|---|---|
| T1 | Tracker level labels in `{tracker:x}` placeholders and in the journal's tracker note; Ironsworn's free-slot wording | delegated: slice runs under one writer | [x] | |
| T2 | `ListPanel` badges count open items only | delegated: slice runs under one writer | [x] | |
| T3 | Cancelled action die struck through in the journal and the sandbox | delegated: slice runs under one writer | [x] | |
| T4 | A choice's skip option is not offered beside Skip | | [ ] | |
| T5 | No Character select with one Character | | [ ] | |
| T6 | Sandbox burn offer names the challenge dice it beats | | [ ] | |

## Applicable checks

- `make verify` once at the end; CI runs the full suites.
- Focused PHPUnit and Vitest while iterating, test first.
- `make presets` after the preset edit.

## Progress

- 2026-11-08: Mapped the six items (explorer); one slice, delegated writer: two or more non-trivial files.
- 2026-11-08: T1 done. RED: `PlaceholdersTest` failed before the change (new `trackers` argument), GREEN after; the journal test was written with the code (no separate RED). `make presets` ran with no refusal.
- 2026-11-08: T2 done. RED: the badge test failed on the old code, GREEN after.
- 2026-11-08: T3 done. RED: the journal and sandbox tests failed on the old code, GREEN after.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

T1–T6 on `fix/play-tracker-display-polish-1-display`, then review and PR.
