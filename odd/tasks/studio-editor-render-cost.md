# Feature: studio-editor-render-cost

- **Locator:** `odd/tasks/studio-editor-render-cost.md` · Engram topic `odd/studio-editor-render-cost/tasks`
- **Issue:** #259 · **Branch:** `fix/studio-editor-render-cost-1-memo-cards` · **Status:** slice 1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B7h

## Objective

Stop one input event from re-rendering the whole Studio editor. Memoise the step cards, the check band rows and the sheet group cards so that only the edited card re-renders. Measure the slowest Studio test files before and after, run serially.

## Problem and why

- Baseline on `main` `3acd431` (2026-10-24), `vitest run --no-file-parallelism`, two runs with under 2% spread: `StepListEditor.test.tsx` 27.3s, `ChecksTab.test.tsx` 20.7s, `FlowEditorPage.test.tsx` 17.0s, `SheetLayoutPanel.test.tsx` 15.3s, `SceneTypeEditorPage.test.tsx` 14.2s. Total wall time is 100.7s. The 128s for `StepListEditor` quoted in #259 was summed file time under parallel load.
- Profile (dev-mode jsdom, `stepList` fixture cloned to n steps, one `fireEvent.change` on step 1's title):
  - With 5 steps, all 5 cards re-render, about 60ms per change, and mount takes about 650ms.
  - With 10 steps, all 10 cards re-render, 108–230ms per change. The cost grows linearly with the number of steps.
- Causes:
  - `StepCard` in `narrative/steps/StepListEditor.tsx` is not memoised and is keyed by index.
  - Every callback is an inline closure over `steps`, `index` and `origins`: `onChange`, `onMove`, `onDelete`, `onItems` and `onInnerRestructure`, at about `:586-615`.
  - The derived props `laterKeys`, `mine` (`messagesOf(index)`) and `guide` (`stepGuides(...)`) are fresh arrays on every render.
  - `StepFields` builds about 10 inline closures.
  - `FlowEditor.tsx:76` and `SceneTypeEditor.tsx:60` rebuild `stepContext(narrative)` on every render, so `context` is a new object each time.
  - The check band row is an inline `<li>` in `checks/BandsEditor.tsx:~149-260`. It runs `checkFormulaDiagnostics` inline for each formula band.
  - `ChecksTab.tsx` passes `errors={shown.band ?? {}}`, a fresh object, and an inline `onChange` (`:~851`).
  - `SheetLayoutPanel.tsx` `GroupFields` (`:453`) is keyed by id but gets inline callbacks, a fresh `held` array and a fresh `errors` object.
- Neither `memo` nor the React Compiler is used anywhere in `frontend/src/studio` (React 19.3, plain `@vitejs/plugin-react`).
- Why it matters:
  - Studio test files make up most of the Vitest time, so `make verify` and Vitest get faster on Studio changes.
  - Editors on large GameSystems (many steps) get snappier for game managers.

## Decisions

| Item | Decision |
|---|---|
| Technique | `memo` on the card components, plus stable callbacks that take the index (e.g. `onChange(index, step)`, `onMove(index, by)`), plus `useMemo` for derived props. No React Compiler: it would be a new build dependency for the whole SPA, and #259 asks for memoised cards. The compiler is the alternative if manual memo proves fragile |
| External contracts | Unchanged. `StepListEditor` keeps `onChange(steps: StepDraft[])`, and the band and group editors keep their props. No API, schema or behaviour change |
| Cross-step derived props | `guide`, `mine` and `laterKeys` keep their previous reference when their content is unchanged (one small shared helper, e.g. `useStableArray` with a shallow or structural compare), so an unrelated card's memo holds |
| Latest-state access in stable callbacks | Prefer the functional update form where the owner holds the state. Where the editor is controlled (`StepListEditor`), read the latest props through a ref that is updated in an effect, never during render, so the `react-hooks` lint rules pass |
| Regression test | A render-count test per editor, without test-only hooks in production code: edit one card, then assert that the other cards did not re-render. Count renders with `vi.mock` on a leaf module rendered once per card (for example `StepKindTile`), or with `React.Profiler` `onRender` counts. Existing behaviour tests stay unchanged and must pass |
| Speed target | Aim for at least 30% less serial wall time on the five files (100.7s to about 70s). Record the actual numbers either way; the render-count tests are the acceptance check |

## Constraints

- Frontend only. No backend, API or generated client change.
- No test loses an assertion.
- Planning heuristic only: the slice ceiling is ~1,700 changed lines.

## Slice plan

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `fix/studio-editor-render-cost-1-memo-cards` | `fix(studio): studio-editor-render-cost 1/1 memoised editor cards` | T1–T4 | ~550: step cards and contexts ~180, check band rows ~100, sheet group cards ~40, render-count tests ~200, doc ~40 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent, inline (doc only) | [x] | |
| T2 | 1 | Step cards: `memo(StepCard)`, stable index callbacks, stable `guide`/`mine`/`laterKeys`, memoised `context` in `FlowEditor` and `SceneTypeEditor`, `memo` on step `BandsEditor`/`EffectsEditor` where their props become stable; render-count test | writer (delegated) | [x] | |
| T3 | 1 | Check band rows: extract `memo(BandRow)` from `checks/BandsEditor.tsx`, memoised formula diagnostics per row, stable `errors` and `onChange` in `ChecksTab`; render-count test | writer (delegated) | [x] | |
| T4 | 1 | Sheet group cards: `memo(GroupFields)` with stable callbacks, `held` and `errors`; render-count test; update the "re-renders the whole editor" comment in `frontend/src/test/typeText.ts`; after-measurement of the five files | writer (delegated) | [x] | |
| T5 | 1 | Slice 1 review fixes, if any | writer (delegated) | [ ] | |

## Applicable checks

- T2–T4: test first. Each render-count test is RED on `main` (every card re-renders) and GREEN after the memo. The existing tests of the five files must stay green.
- T4: the after-measurement is the same command as the baseline (`vitest run --no-file-parallelism` on the five files), run twice.
- `make verify` once by the writer; CI runs the full suites.

## Progress

- 2026-10-24: feature doc created (5 tasks, one slice); issue #259; baseline and profile recorded above.
- 2026-10-24 T2: RED on `main` code: editing step 1 of 6 re-rendered 6 cards (`expected 6 to be 1`). GREEN after `memo(StepCard)`, stable index callbacks (latest list read through a ref set in `useLayoutEffect`), `useStableItems` for `guide`/`mine`/`laterKeys` and memoised `context`: 1 card. The two-quick-edits test passes before and after. Focused: `vitest run src/studio/narrative` 24 files, 529 tests green. Step `BandsEditor`/`EffectsEditor` not memoised: their props are inline closures of the step, so a memo would be dead.
- 2026-10-24 T3: RED on the previous code: editing band 1 of 5 drew 14 inputs instead of the 3 of its row (`BandsEditor.test.tsx`), and band 2 of `luck` re-rendered 3 label inputs instead of 1 (`ChecksTab.test.tsx`). GREEN after `memo(BandRow)`, stable `change`/`move`/`remove` over a latest-bands ref, per-row diagnostics `useMemo`, memoised band errors and `useCallback` `changeBands` in `ChecksTab`. `vitest run src/studio/checks`: 8 files, 201 tests green.
- 2026-10-24 T4: RED on the previous code: editing group 2 of 3 drew 6 text fields instead of 1 (`SheetLayoutPanel.test.tsx`). GREEN after `memo(GroupFields)`, index-taking `useCallback` handlers over the functional `setDraft`, and stable per-group `errors` (`useStableItems`). The comment in `typeText.ts` now says the edited card and its editor re-render. `vitest run src/studio/sheet`: 11 files, 220 tests green.
- 2026-10-24 After-measurement (`vitest run --no-file-parallelism`, five files, two runs; baseline in brackets): `StepListEditor` 26.0 / 26.0s [27.3], `ChecksTab` 20.2 / 20.4s [20.7], `FlowEditorPage` 17.4 / 17.5s [17.0], `SheetLayoutPanel` 15.1 / 15.2s [15.3], `SceneTypeEditorPage` 11.1 / 10.7s [14.2]; wall 96.4 / 96.1s [100.7], about 4.5% less. The 30% target is not met: the render count per input event is down to one card, but the time of these files is dominated by what is not card renders (mounting the editors, `user-event` and Radix interaction, the test API). The render-count tests are the acceptance check.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T2–T4 by one writer, then review and PR (`Closes #259`).
