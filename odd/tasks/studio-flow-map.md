# Feature: studio-flow-map

- **Locator:** `odd/tasks/studio-flow-map.md` · Engram topic `odd/studio-flow-map/tasks`
- **Issue:** [#421](https://github.com/iribarren/ogami/issues/421) · **Branch:** `feat/studio-flow-map-1-map` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B35 in [`docs/roadmap.md`](../../docs/roadmap.md); builds on B34 `flow-diagram` ([ADR 0051](../../docs/adr/0051-flow-diagram-layout-and-rendering.md))

## Objective

A Map tab in the Studio flow editor that shows the flow on the B34 diagram, laid out automatically from the Studio draft, and edits it by dragging. Every gesture maps to an edit the editor already makes, goes through the same draft and save, and the publisher's coherence checks still apply.

Out of scope: new flow rules, phase jumps or branches between phases, saved node positions.

## Problem and why

- Authors edit a flow only through the outline and its panels, so they see its shape only in the manual diagrams or after publishing.
- The B34 component is read-only and reads release content only.

## Evidence

- Route `frontend/src/routes/studio.game-systems.$key_.flows.$flow.tsx:7` (search param `node`), page `studio/narrative/flows/FlowEditorPage.tsx:25`, editor `FlowEditor.tsx`: `FlowOutline` on the left, one panel from `panel()` (:291) on the right, sticky Save bar; no tab bar. State is `useState` in `FlowEditor.tsx:71` (`draft`), `answer` (save errors, :74), `baseline` (:78); `changePhases(next, answers)` (:285) keeps errors aligned.
- Draft helpers are plain functions: `moveItem` (`steps/draftParts.ts:80`) for phase and selection order (up/down buttons in `PhasePanel.tsx:110`, `SelectionEditor.tsx:138`); mode is `{...phase, mode}` (`PhasePanel.tsx:69`); `withRule` (`flowDraft.ts:270`), `setHook` (:281). No "add effect" helper; `EffectDraft` and `newEffect(kind)` in `steps/effectDraft.ts:55,199`. Effects sit on `StepBase.effects` and on bands, options and outcomes (`steps/stepDraft.ts:133-190`).
- Panels open by URL: `?node=` is a `FlowNode` path (`flowNode.ts`: `settings`, `phases.N`, `phases.N.selection`, `phases.N.hooks.H`, `phases.N.hooks.H.S`); `select()` (`FlowEditor.tsx:129`). Scene Types have no panel here: their own route `studio.game-systems.$key_.scene-types.$sceneType.tsx` (no search params yet), with an unsaved-changes guard.
- Save: `flowToItem` (`flowDraft.ts:330`) + `flowChecks` (`flowError.ts:107`), `PUT /api/studio/game-systems/{key}/narrative/flows/{original}` with the GameSystem draft's `expectedVersion` (one version for the whole draft: saving a Scene Type from the flow page would make the flow's next save conflict). Errors `flows[i].phases[j]…: message` become a `FlowAnswer` (`flowError.ts:31,76`); `answerNode` (`flowNode.ts:129`) picks the first.
- Scene Types are a separate narrative section (`narrative.sceneTypes[].definition`, release-shaped); the flow editor reads only `{key, name}` (`steps/stepContext.ts:110`). Oracle tables with entries (`sceneType`, `effects`) come from `useGameSystemOracles` (`studio/oracles/useGameSystemOracles.ts:48`); `narrative.catalog.tables` has names only.
- B34 module `frontend/src/shared/flowDiagram/`: `flowDiagramFromReleaseContent` (throws `FlowDiagramError` on dangling references), `layoutFlowDiagram` (a rect per node, phase and header item; points per edge), `FlowDiagramView` (`{diagram, className}` only) with `data-node-id`/`data-phase-id`/`data-header-id`/`data-edge-id`; ids `scene:<phase>:<sceneType>`, `choice:<phase>`, `table:<phase>`, `end`, `exit:<phase>`, `mode:<phase>`, `hook:<phase>:<hook>`, `phase:<phase>`. `DiagramTransition.from` is a Scene Type, hook or selection table, without the step that carries it.
- No drag-and-drop library in `frontend/package.json`.
- Tests: `FlowEditorPage.test.tsx` (`renderAppAt`, `signedInAs`, `FakeHandler`, `studio/fixtures.ts`, `ResizeObserver` stub); e2e `frontend/e2e/studio-flow-editor.spec.ts`.

## Decisions

| # | Decision | By |
|---|---|---|
| D1 | Dragging and arrow drawing use pointer events on B34's own SVG, hit-tested on the layout's rects; no new library. Recorded as ADR 0052 | User (2026-11-27) |
| D2 | The source decides where a drawn effect goes. From a Scene Type node: one of that Scene Type's steps (`setup`, `play`, `closing`), and the step itself or one of its bands, options or outcomes; the map opens the Scene Type page at that step with the effect added as an unsaved edit, saved there (the flow page's unsaved-changes dialog guards the flow draft). From a phase hook chip: one of that hook's steps and part, edited in the flow draft, opening the existing hook step panel. The saved arrow starts where it was drawn (B34 D3) | User (2026-11-27) |
| D3 | The Map is a tab of the editor's right-hand pane (`Panel` / `Map`), search param `view=map`; the outline stays. Clicking a phase, selection or hook node selects its `FlowNode` and switches to its panel; clicking a Scene Type node opens its Scene Type page | Parent |
| D4 | Draft adapter `flowDiagramFromDraft(draft, sceneTypes, tables)` never throws on an in-progress draft: references to unknown Scene Types or tables, and parts `flowToItem` cannot build, become issues on the node they belong to instead of a blank map. The save's validation stays the authority. The release adapter keeps failing closed | Parent |
| D5 | Save errors (`FlowAnswer`) and client checks map to node ids: phase fields → `phase:<key>`, selection → its selection nodes, hook steps → `hook:<phase>:<hook>`. A node with issues is marked and names them in its accessible label | Parent |
| D6 | Every gesture has a keyboard path on the focused node (roving focus over nodes, Enter opens the panel, a node menu for move earlier/later, mode, add to or remove from a phase, add a transition); the outline remains the full accessible editor | Parent |
| D7 | Refusals, each with a short reason in a live region: an arrow to a phase that is not the next one or back ("phases always run in order"); a `nextScene` / `switchSceneType` arrow to a Scene Type in another phase; dropping a Scene Type on an `oracle` phase (edit the table instead); an arrow from an `oracle` table node (its effects sit on table entries, edited in the oracle editor) | Parent |
| D8 | A Scene Type palette beside the map lists the GameSystem's Scene Types to drag into a phase; dropping between chained `sequence` nodes sets its position; dragging a selected node out of its phase removes it from the selection (it stays drawn if a transition still reaches it) | Parent |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/4 | T1, T2, T3 | ~1,400–1,650 lines: ADR + index ~120, draft adapter ~300 + Vitest ~300, interactive view props ~150 + Vitest ~150, Map tab ~200 + Vitest ~250, feature doc ~150 | `feat/studio-flow-map-1-map` |
| 2/4 | T4, T5 | ~1,300–1,600 lines: drag engine and phase reorder + mode toggle ~400, palette and selection drag ~350, keyboard menu ~150, Vitest ~600 | `feat/studio-flow-map-2-drag` |
| 3/4 | T6, T7 | ~1,300–1,600 lines: arrow gesture and refusals ~350, carrier picker dialog (step, band, option, outcome) ~350, hook-source effect in the flow draft ~150, Vitest ~600 | `feat/studio-flow-map-3-arrows` |
| 4/4 | T8, T9 | ~800–1,100 lines: Scene Type page `step` / pending-effect search params and handoff ~300, Vitest ~350, Playwright ~150, manual chapter ~80, roadmap/doc ~60 | `feat/studio-flow-map-4-scene-types` |

No API change is forecast. Shippable: slice 1 adds a read-only Map tab (useful alone); slices 2–3 add gestures behind it; slice 4 completes Scene Type arrows (until then, an arrow from a Scene Type is refused with the reason "add it on the Scene Type page").

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | ADR 0052 (D1, D2, D4) and its row in `docs/adr/README.md` | delegated writer (Sonnet): slice of 2+ non-trivial files | [ ] | |
| T2 | 1 | `flowDiagramFromDraft` (D4) sharing the release adapter's building, issues per node, Vitest on draft fixtures and on the Cold Berth preset read as a draft | delegated writer (Sonnet): slice of 2+ non-trivial files | [ ] | |
| T3 | 1 | `FlowDiagramView` interaction props (selected id, issues, `onActivate`, focusable nodes with labels), Map tab (`view=map`, D3), save errors on nodes (D5), keyboard open (D6 part) | delegated writer (Sonnet): slice of 2+ non-trivial files | [ ] | |
| T4 | 2 | Drag engine on the SVG; drag a phase to reorder; toggle a phase's self-arrow for `mode`; keyboard menu equivalents | | [ ] | |
| T5 | 2 | Scene Type palette; drag into or out of a `sequence` / `player` selection, drop position in a sequence; `oracle` refusal (D7, D8) | | [ ] | |
| T6 | 3 | Arrow gesture between nodes with the refusals (D7) and a keyboard "Add transition" path | | [ ] | |
| T7 | 3 | Carrier picker (step, then the step itself or a band, option or outcome) and the hook-source effect in the flow draft, opening the hook step panel (D2) | | [ ] | |
| T8 | 4 | Scene Type source: Scene Type page search params for the step and the pending effect, added as an unsaved edit (D2) | | [ ] | |
| T9 | 4 | Playwright pass on the Map, the Studio manual's flow editor chapter, roadmap `Done:` line | | [ ] | |

## Acceptance criteria

- The Map shows the draft as the outline does, including unsaved edits, and never blanks on an in-progress draft.
- Every Map edit equals an edit the outline already makes; the dirty state and the save are the same.
- Save errors appear on their node; refused gestures say why.
- Every gesture has a keyboard path.
- No new flow rules, no saved positions.

## Checks

- Vitest for the draft adapter, view interaction, Map tab and gestures; ESLint, Prettier, tsc via `make verify`.
- Playwright on the Map in slice 4; a Chrome pass by hand at the end of slices 2–4.

## Progress

- 2026-11-27: Feature doc created; four slices, nine tasks; D1 and D2 decided by the user.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

Slice 1: T1–T3 on `feat/studio-flow-map-1-map`.
