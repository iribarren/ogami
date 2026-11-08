# Feature: studio-step-card-height

- **Locator:** `odd/tasks/studio-step-card-height.md` · Engram topic `odd/studio-step-card-height/tasks`
- **Issue:** [#356](https://github.com/iribarren/ogami/issues/356) · **Branch:** `feat/studio-step-card-height-1-cards` · **Status:** done
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Origin:** `preset-vaesen` finding S15, carried by `vaesen-ux-followups` (#300, PR #355); roadmap B7p

## Objective

Scene Type step cards in Studio are tall, so a Scene Type with many steps takes a lot of scrolling. Make the step list scannable: steps start collapsed, each with a one-line summary, and the list gets Expand all / Collapse all.

## Problem and why

- `StepCard` (`frontend/src/studio/narrative/steps/StepListEditor.tsx:396`) is already a `Collapsible`, but `useState(true)` opens every card on mount. An open card shows Key/Title, Prompt and Tip areas, Requirement, Next, the kind's fields and Effects.
- A collapsed header shows only kind tile, title, key, kind badge and bound name: not enough to scan the flow without opening cards.
- A check step's Outcomes already collapse with Expand all / Collapse all (S16, `PickCheckFields.tsx:125` `ExpandRequest`, `BandRow`).
- `StepListEditor` is shared by Scene Types (`SceneTypeEditor.tsx:279`) and Flow hooks (`HookPanel.tsx:30`): both get the change.

## Decisions

| Item | Decision | By |
|---|---|---|
| Layout | Collapsed cards with a one-line summary and list-level Expand all / Collapse all (option A). Not a two-column editor panel, not denser open cards | User, 2026-11-08 |
| Start state | A step already in the list when the editor mounts starts collapsed. A step the author adds (the Add step menu, or a new step created from a Next select) starts open | Parent |
| Auto-open | Kept: the current step (outline / Go to) and a step with messages in a new server answer open. New: on mount, a step with messages or client guide notes (backward next, key notes, band/item/scene notes) starts open, so no problem is hidden | Parent |
| Problem marker | The header shows a "Needs a fix" badge (destructive) while the step has messages or guide notes, open or closed | Parent |
| Summary | Under the title, the key line gains the parts that apply, joined with ` · `: `next: <key>` when set; `N effect(s)` for the step's own effects; `N outcome(s)` / `N option(s)` / `N branch(es)` / `N band(s)` by kind (counts of the items the step has). A pure function `stepSummary(step)` with unit tests. Stored (unknown) steps keep their current header | Parent |
| List controls | "Expand all steps" and "Collapse all steps" ghost buttons above the list when it has 2+ steps. A card follows the newest press once, then is the author's again: the `ExpandRequest` pattern of `BandRow`, moved to a shared module both use. Distinct names from the Outcomes' "Expand all" | Parent |
| Scope | Frontend only. Accessible names unchanged except the new buttons and badge. Check Outcomes (S16) unchanged | Parent |
| Tests | Tests open cards the way an author does (the list's Expand all steps, or the card's trigger) in shared helpers; no test-only production prop | Parent |

## Constraints

- No API or OpenAPI change.
- `contrast.test.ts` rules hold for the new badge.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-step-card-height-1-cards` | `feat(studio): studio-step-card-height 1/1 collapsed step cards` | T1–T3 | ~600: this doc ~90; T1 ~100; T2 ~120; T3 test and e2e updates ~200–300 |

## Tasks

| ID | Task | Route | Done | Commit |
|---|---|---|---|---|
| T1 | `stepSummary` and the summary line plus "Needs a fix" badge in the step card header | delegated: slice runs under one writer (2+ non-trivial files) | [x] | `db8c73ad` |
| T2 | Cards start collapsed (added steps open, problems open), list-level Expand all steps / Collapse all steps on a shared `ExpandRequest` | delegated: slice runs under one writer | [x] | `f7c4b00b` |
| T3 | Existing Vitest and e2e specs open the cards they edit; stories updated | delegated: slice runs under one writer | [x] | `ec9ee083` |
| T4 | Review findings: e2e opens a card only while closed, `stepSummary` counts lists only, the test helper moves to `src/test/` | inline: mechanical, ~40 lines | [x] | `e6387111` |

## Applicable checks

- `make verify` once at the end; CI runs the full suites and e2e.
- Focused Vitest while iterating, test first.

## Progress

- 2026-11-08: Explored `StepListEditor`, `OutcomeEditor`, `PickCheckFields` (S16 pattern) and the two users of the list. User chose option A. One slice, delegated writer.
- 2026-11-08: T1 done. RED: `stepSummary` tests failed on the missing module, header tests failed on the missing summary and badge; GREEN: `src/studio` Vitest 2007 passed. `hasGuideNotes` shares the module with `stepSummary`.
- 2026-11-08: T2 done. RED: the 9 new open-state and Expand/Collapse tests failed before the change; GREEN: all 10 pass. Existing tests that edit mounted steps now fail until T3. `ExpandRequest` moved to `expandRequest.ts`; the list gives a card `startOpen` when its origin is null and `expand`.
- 2026-11-08: T3 done. RED: the old tests failed on collapsed cards (about 30 in `src/studio`); GREEN: `src/studio` Vitest 2017 passed. Helper `openStepCards` (`steps/openCards.ts`, presses each closed card's disclosure) used by `renderOpen` in `StepListEditor.test.tsx`, the renders test and both page tests' `openEditor`. Stories: each play opens the cards first. e2e edited, not run: `studio-flow-editor.spec.ts`, `studio-shop-editor.spec.ts`.
- 2026-11-08: T4 done. Finding 2's test was written before the guard and passed at once (an unreadable choice or check is already kept as a stored step); the guard keeps `stepSummary` safe if that changes. e2e uses `openStepCard` (`e2e/open-step-card.ts`), which presses the disclosure only while it reads closed; not run locally, CI runs it. `openStepCards` moved to `src/test/openStepCards.ts`.
- 2026-11-08: Slice 1 reviewed (T1–T3 approved, T4 skipped by the user) and opened as PR #376. Feature done.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1, T1–T3 | `review-ec924a705e8703f8` | Approved and acknowledged | 2 warnings, 1 suggestion: e2e pressed the disclosure unconditionally, `stepSummary` read `.length` of non-arrays, a test helper in `src/` beside app code. All fixed in T4 | [#376](https://github.com/iribarren/ogami/pull/376) |
| 1, T4 | None | Skipped by the user: review fixes, 87 lines | None | [#376](https://github.com/iribarren/ogami/pull/376) |

## Next step

None. Every review finding is fixed in T4.
