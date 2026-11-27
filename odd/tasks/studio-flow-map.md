# Feature: studio-flow-map

- **Locator:** `odd/tasks/studio-flow-map.md` · Engram topic `odd/studio-flow-map/tasks`
- **Issue:** [#421](https://github.com/iribarren/ogami/issues/421) · **Branch:** `feat/studio-flow-map-5-scene-types` · **Status:** in progress
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
| D9 | Lenient builder: `FlowDiagramError` gains `problem` (message without path); unknown references say "is not in the draft". Issues sit on layout node ids: a dangling Scene Type in a `player` selection on `choice:<phase>`, in a `sequence` on `phase:<phase>`, a dangling effect target or table on the node the effect is drawn from, an unknown selection table on `table:<phase>` (still drawn, named by its key). Hook steps `flowToItem` refuses are drawn as a chip of blank steps with the refusal on `hook:<phase>:<hook>`. Phases with no or repeated keys get unique map keys (`mapPhaseKeys`) | Writer |
| D10 | `FlowDiagramView` is `role="group"` instead of `role="img"` when `onActivate` is given: an image hides its buttons from assistive technology. `destructive` joins `DIAGRAM_TOKENS` and `print.css` for the issue mark (a "!" badge, so it is not colour alone). The phase box is the button (its rect), the chips stop their own clicks; plain tab order, no roving focus yet | Writer |
| D11 | The Map reads the oracle tables with `useGameSystemOracles` only while it is shown (existing flow editor tests need no new handler); it waits for them, and on a failed read says so and marks every table. A refused save keeps the Map tab and puts the answer on the nodes (`refuse`), "Go to the first" and the outline go to the Panel. `view` is dropped from the URL for the Panel | Writer |
| D12 | Toggling a phase's mode is activating its `mode:<phase>` header chip on the Map (Once ↔ Loop), not opening the panel; its label says what it switches to | Parent |
| D13 | The keyboard path of D6 is an action bar beside the Map for the last focused node (focus or pointer-down sets it): a phase gets Move earlier / Move later / mode; a phase with a `sequence` or `player` selection gets "Add Scene Type" (a select of the GameSystem's Scene Types); a Scene Type node in a selection gets Remove from the selection and, in a `sequence`, Move earlier / later. Buttons, no menu inside the SVG | Parent |
| D14 | Drag rules: a 4px move threshold keeps clicks as activation; Escape or a drop on nothing cancels, except a selected Scene Type dropped outside every phase, which leaves the selection (D8). Dropping a selected Scene Type on another phase moves it (removed from the first, added to the second). Each Map edit announces what it did, or why it was refused, in one live region | Parent |
| D15 | The drag engine is `useDiagramDrag` in `shared/flowDiagram` (with the pure `hitTestDiagram` and `clientToSvg`): the pointer is captured on the first move past the threshold, not at the press, since a captured press would retarget the click of a chip; a click that follows a drag is swallowed. `FlowDiagramView` takes `drag`, `labels` (accessible names by id) and `onFocusNode`; the drag source may sit outside the drawing (the palette) | Writer |
| D16 | A phase is dragged by its box (its title lets presses through; the chips never start a drag). The mode chip is a button whenever the drawing is interactive (kind `mode`), named by the host (`Mode of "Setup": once. Switch to loop`). Dropping a phase on itself, or outside every phase, changes nothing and says so | Writer |
| D17 | `FlowMapDiagram` also carries `drawn` (ids the drawing has), `layout` and `flowIssues` (what no node can carry, shown as an alert above the Map); `mapTarget` resolves a `scene:` id against the drawing's phases, and is null when two phases could own it | Writer |
| D18 | The Map refuses adding a Scene Type to a phase whose selection already lists it ("already in phase …"): its node id is one per phase, so a second copy could not be drawn or dragged apart. The outline still edits the lists in full (D19: every phase has a selection) | Parent |
| D19 | Every phase draft has a selection (a new phase starts as an empty `sequence`, `withRule` never drops it), so "a phase with no selection" does not occur: a drop on an empty sequence just adds. The Map refuses an add into a full list (20 Scene Types, as the outline's Add select is disabled) | Writer |
| D20 | The duplicate refusal of D18 holds for a `sequence` too, though the outline lets one repeat a Scene Type; a legacy list that repeats one has one node, so Remove takes every copy and a reposition moves the first. A drop position is the first listed Scene Type whose node centre lies right of the pointer (a phase's flow runs left to right), else the end; a `player` list appends and "stays where it is" | Writer |
| D21 | The action bar follows a Scene Type edit: after Remove or a drop outside every phase it returns to the phase; after a move to another phase it acts on the node in its new phase. The palette is the pointer's path only (`ScenePalette`, no keyboard stop) | Writer |
| D22 | An arrow starts from a port: a small circle on the right edge of each Scene Type node, hook chip and selection table node, drawn only when the view is interactive and not in the tab order. Dragging a port draws a line to the pointer (the node bodies keep their D14 drags); Escape or a drop on nothing cancels | Parent |
| D23 | Arrow targets: a Scene Type node in the source's phase makes `nextScene` or `switchSceneType` (chosen in the dialog, `nextScene` first); the phase's `exit:<phase>`, the `end` node or the next phase's box makes `endPhase`. Refused with a reason (D7): the source's own phase box, a phase that is not the next one, a Scene Type in another phase, an `oracle` table source. In slice 4 a Scene Type source is refused with "add it on the Scene Type page" (slice 5 lifts it) | Parent |
| D24 | A valid hook-source arrow opens a dialog (`shared/ui/dialog`): the hook's step, then the part (the step itself, or one of its bands, options or outcomes), and the kind for a Scene Type target. Confirm appends the effect to that part's `effects` in the flow draft, selects `phases.N.hooks.H.S` and shows the Panel; cancel changes nothing. A hook with no steps refuses ("add a step in its panel first"); an identical effect already on that part refuses. The keyboard path is "Add transition" in the action bar for a focused hook chip: the same dialog with a "To" select first (this phase's drawn Scene Types, then the phase's end) | Parent |
| D25 | An arrow is a drag of kind `arrow` in `useDiagramDrag` (`bind(id, label, 'arrow')`): a port stops its own pointer events, so the node body's drag never starts from it; `DiagramDrop` and `DragState` carry `kind`; a click on a port opens nothing | Writer |
| D26 | `end` is a target only from the last phase (from any other, an `endPhase` would go to the next phase, not to the flow's end: refused with "phases always run in order"); `exit:<key>` (drawn only on a single-phase cut, never on the Map) ends the phase only when `<key>` is the next phase; the phase title and mode chip count as the phase box | Writer |
| D27 | An effect is added by `addEffect(phases, at, carrier, effect)` in `flowMapCarriers.ts`: all or nothing, refusing a hook with no step, a step kept as stored, an unknown part, a part that has the same effect (same kind and Scene Type) and a part with `MAX_EFFECTS` effects. T6 alone added it to the first step itself; T7 replaced that with the dialog | Writer |
| D28 | Carriers are string ids (`step`, `band:N`, `option:N`, `oracle:<answer>`, `pick:<outcome>`, `branch:N`, `otherwise`, `check:N`) built by `partsOf(step)`, which pairs each with its effects and a writer; `carriersOf` and `CarrierPicker` read only a `StepDraft[]`, so slice 5 can reuse them for Scene Type steps. A step kept as stored has none and is listed disabled | Writer |
| D29 | The dialog (`TransitionDialog`) is mounted per request, so its choices start afresh; a refusal on confirm (already there, full list) shows inside it and keeps it open; cancel says "No transition added." A hook with no step, or only steps kept as stored, refuses before the dialog opens (`hookSteps`). A confirmed edit carries `select` (`MapEdit`): the editor shows the panel of that step and says the edit in a status line there, since the Map's own live region leaves with the Map | Writer |
| D30 | A Scene Type source (an arrow from its port, or "Add transition" in the action bar of a focused Scene Type node) opens the same `TransitionDialog` over that Scene Type's steps, read from the stored Scene Type (`sceneTypeToDraft`) and named by part and position (`Play · 2 "…"`); only parts and effects the Scene Type editor's own effect menu offers there. A Scene Type the editor cannot read, or with no step that carries effects, refuses before the dialog ("add a step on its page first"). The D23 target rules hold | Parent |
| D31 | Confirm changes nothing in the flow draft: it goes to the Scene Type page with search `step=<part>.<index>`, `carrier=<carrier id>`, `effect=nextScene:<key>` / `switchSceneType:<key>` / `endPhase` (the flow page's unsaved-changes guard asks first; staying changes nothing) | Parent |
| D32 | The Scene Type page validates those params (a malformed or unknown value is an explicit undefined). `step` alone scrolls to and marks that step. With a pending effect the form opens with it added as an unsaved edit (dirty, guarded, kept by Save), a status line says what was added where, and the URL is replaced without `carrier` / `effect` so a reload or Back never adds it twice. One that cannot apply (unknown step or part, already there, full list, unknown target) shows an alert with the reason and leaves the form as stored | Parent |
| D33 | One all-or-nothing `addEffectToStep(step, carrier, effect)` with the D27 refusals serves hook steps (`addEffect` delegates to it) and Scene Type steps | Parent |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/5 | T1, T2, T3 | ~1,400–1,650 lines: ADR + index ~120, draft adapter ~300 + Vitest ~300, interactive view props ~150 + Vitest ~150, Map tab ~200 + Vitest ~250, feature doc ~150 | `feat/studio-flow-map-1-map` |
| 2/5 | slice 1 fixes, T4 | ~1,400 lines (came out): drag engine, phase reorder, mode chip, action bar, Vitest | `feat/studio-flow-map-2-drag` |
| 3/5 | slice 2 fix, T5 | ~1,000–1,300 lines: drag-swallow fix ~40, palette and selection drag/drop/position ~400, action bar Add / Remove / Move ~150, Vitest ~550, feature doc ~50 | `feat/studio-flow-map-3-palette` |
| 4/5 | T6, T7 | ~1,300–1,600 lines: arrow gesture and refusals ~350, carrier picker dialog (step, band, option, outcome) ~350, hook-source effect in the flow draft ~150, Vitest ~600 | `feat/studio-flow-map-4-arrows` |
| 5/5 | T8, T9 | ~800–1,100 lines: Scene Type page `step` / pending-effect search params and handoff ~300, Vitest ~350, Playwright ~150, manual chapter ~80, roadmap/doc ~60 | `feat/studio-flow-map-5-scene-types` |

No API change is forecast. Shippable: slice 1 adds a read-only Map tab (useful alone); slices 2–3 add gestures behind it; slice 5 completes Scene Type arrows (until then, an arrow from a Scene Type is refused with the reason "add it on the Scene Type page").

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | ADR 0052 (D1, D2, D4) and its row in `docs/adr/README.md` | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `e85c8675` |
| T2 | 1 | `flowDiagramFromDraft` (D4) sharing the release adapter's building, issues per node, Vitest on draft fixtures and on the Cold Berth preset read as a draft | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `48c5b313`, `5ce11931` |
| T3 | 1 | `FlowDiagramView` interaction props (selected id, issues, `onActivate`, focusable nodes with labels), Map tab (`view=map`, D3), save errors on nodes (D5), keyboard open (D6 part) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `41678f85` |
| T4 | 2 | Drag engine on the SVG; drag a phase to reorder; toggle a phase's self-arrow for `mode`; keyboard menu equivalents | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `7e8f270d` |
| T5 | 3 | Scene Type palette; drag into or out of a `sequence` / `player` selection, drop position in a sequence; `oracle` refusal (D7, D8) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `e56f58ac` |
| T6 | 4 | Arrow gesture between nodes with the refusals (D7) and a keyboard "Add transition" path | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `ae4c6bc2` |
| T7 | 4 | Carrier picker (step, then the step itself or a band, option or outcome) and the hook-source effect in the flow draft, opening the hook step panel (D2) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `e98b437c` |
| T8 | 5 | Scene Type source: Scene Type page search params for the step and the pending effect, added as an unsaved edit (D2, D30-D33) | | [ ] | |
| T9 | 5 | Playwright pass on the Map, the Studio manual's flow editor chapter, roadmap `Done:` line | | [ ] | |

## Acceptance criteria

- The Map shows the draft as the outline does, including unsaved edits, and never blanks on an in-progress draft.
- Every Map edit equals an edit the outline already makes; the dirty state and the save are the same.
- Save errors appear on their node; refused gestures say why.
- Every gesture has a keyboard path.
- No new flow rules, no saved positions.

## Checks

- Vitest for the draft adapter, view interaction, Map tab and gestures; ESLint, Prettier, tsc via `make verify`.
- Playwright on the Map in slice 5; a Chrome pass by hand at the end of slices 2–5.

## Progress

- 2026-11-27: Feature doc created; four slices, nine tasks; D1 and D2 decided by the user.
- 2026-11-27 T1: ADR 0052 (D1, D2, D4) and its index row; doc-only, structural readback.
- 2026-11-27 T2: `flowDiagramFromDraft` in `studio/narrative/flows/flowMapDiagram.ts` on a lenient mode of the release builder (`flowDiagramFromDraftContent`); 16 new Vitest tests, the release adapter's 40 unchanged and green. No RED observed: the refactor and its tests were written together and the existing strict tests served as the regression net.
- 2026-11-27 T3: interaction props on `FlowDiagramView` (buttons, selected, issues), `EditorTabs` (Panel / Map tablist), `FlowMap`, `view=map` in the route, D5 mapping in `flowMapNodes.ts`. Vitest: 5 view tests, 13 `FlowEditorMap` tests, 12 `flowMapNodes` tests, `viewParam`. Existing 263 flow editor tests green unchanged. No meaningful RED: the view tests first failed on the `img` role hiding the buttons (D10), the rest were written with the code.

- 2026-11-27: Slice 1 came out at ~2,000 changed lines (T3 ~1,100, mostly tests); the user kept it as one slice (size exception) to be reviewed as two candidates, T1–T2 against `main` and T3 against the T2 commit. The parent fixed two test type errors `tsc` caught after the writer stopped.

- 2026-11-27 slice 1 review fixes (delegated writer, Sonnet): `flowDiagramFromDraft` now strips every hook when the second build is still refused, so the phases are drawn; issues with no node (catalog lists, flow name) go to `flowIssues`, shown as an alert above the Map, and `drawn` lists the ids the drawing has; `answerIssues` falls back to `phase:<key>` and dedupes Scene Type nodes; `mapTarget`/`sceneOf` resolve `scene:` ids against the drawing instead of key lengths. RED observed for the nodes and orphan tests (7 failures); the second-build test needs a stubbed `flowToItem` (the real one never refuses twice), so it is a mocked test in `flowMapDiagramRefused.test.ts`.
- 2026-11-27 T4: `useDiagramDrag`/`hitTestDiagram` in `shared/flowDiagram`, `FlowDiagramView` `drag`/`labels`/`onFocusNode`, `flowMapEdits.ts` (`movePhase`, `toggleMode`, `followNode`), `MapActionBar`, live region in `FlowMap`, `editFromMap` in `FlowEditor` (answers and selection follow a moved phase). Vitest: 8 drag tests, 8 edit tests, 8 Map gesture tests; the existing flow editor tests are green unchanged and the view test only counts the new mode button. No RED observed: engine and tests were written together (jsdom has no layout, so the tests stub the CTM).
- 2026-11-27: Slice 2 came out at ~1,400 lines with the slice 1 fixes and T4; T5 moved to slice 3 and the plan became five slices.
- 2026-11-27 slice 2 review fix (R3-stale-click-swallow): a drag that ends without a click (Escape, cancel, release off the source) no longer leaves the swallow flag set; the flag is cleared by the next pointerdown anywhere (`diagramDrag.ts`). RED observed: the new Escape-then-click test failed, then passed.
- 2026-11-27 T5: `ScenePalette`, Scene Type drag (palette to phase, selected node to another phase, out, reposition), `addSceneType`/`removeSceneType`/`placeSceneType`/`sceneDrop` in `flowMapEdits.ts`, `selectedScene`/`insertionIndex` in `flowMapNodes.ts`, "Add Scene Type" / Remove / Move in the action bar (D13, D18-D21). Vitest: 12 edit tests, 4 node tests, 11 Map gesture tests; the other flow editor tests are green unchanged. No RED observed: code and tests were written together (jsdom has no layout, so the tests stub the CTM and read node rects from the SVG).
- 2026-11-27 slice 3 review fixes (delegated writer, Sonnet): the Add Scene Type pick is keyed by phase, the click-reset listener is removed on unmount, the CTM stub is restored, one test title says only what it asserts. RED observed for the picker and the listener tests.
- 2026-11-27 T6: ports and rubber band (`useDiagramDrag` `arrow` kind, `FlowDiagramView` `arrows`), `arrowTarget` (D23) in `flowMapArrows.ts`, `addEffect` in `flowMapCarriers.ts` (the first step of the hook, the step itself); T6 added the transition to the first step itself. Vitest: 6 arrow-rule tests, 5 `addEffect` tests, 3 port/engine tests, 6 Map arrow tests. No RED observed: code and tests were written together (jsdom has no layout, so the tests stub the CTM).
- 2026-11-27 T7: `TransitionDialog` and the generic `CarrierPicker`, `partsOf`/`carriersOf`/`hookSteps` (bands, options, oracle and pick outcomes, table branches, check outcomes), "Add transition" in the action bar of a focused hook chip with a "To" select, `MapEdit.select` and a status line in the editor. Vitest: 4 carrier tests, 5 dialog and bar tests in `FlowEditorMap`. No RED observed: code and tests were written together.
- 2026-11-27: Slice 4 would come out at ~1,815 changed lines (commits 1-2 ~1,005, T7 ~810), over the 1,700 stop line; the writer stopped with T7 staged and verified (`make verify` green), uncommitted, for the parent to decide: commit as is, or move the 'Add transition' action bar path to slice 5.
- 2026-11-27: The user kept slice 4 as one slice (size exception, ~1,750 lines); T7 committed as is.
- 2026-11-27 slice 4 review fixes (delegated writer, Sonnet): `confirmTransition` refuses inside the dialog while a save is on its way and when the phase at the captured index is no longer the one named (its map key is captured at open); the editor's "Added …" status line ends on the next draft edit or node pick (it is keyed to the draft and the node it was made for). RED observed: 3 new tests failed, then passed.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 (T1–T2) | `review-cc7b3e3c02a152b7` | approved | R3-second-build-blank (a draft that fails its second build draws no phases and records no issue, `flowMapDiagram.ts:77`); R3-catalog-issue-orphan (catalog and flow-level issues keyed to ids no node has). Fixed in slice 2 | [#422](https://github.com/iribarren/ogami/pull/422) |
| 1 (T3) | `review-b9ffe761da3cf9e6` | approved | R3-sequence-selection-issue-target (a selection message is lost when every Scene Type of a sequence dangles, repeated when one is listed twice, `flowMapNodes.ts:90`); R3-scene-owner-ambiguity (phase keys with colons make the Scene Type node id ambiguous, `flowMapNodes.ts:42`). Fixed in slice 2 | [#422](https://github.com/iribarren/ogami/pull/422) |
| 2 (fixes, T4) | `review-543bc240f9732c99` | approved | R3-stale-click-swallow (a drag that ends without a click leaves the swallow flag set, so the next click on any node is ignored, `diagramDrag.ts:136-148`). Fixed in slice 3 | [#423](https://github.com/iribarren/ogami/pull/423) |
| 3 (fix, T5) | `review-dca88735c757be3a` | approved | R3-add-picker-state-carries-across-phases (`MapActionBar.tsx:40`, the Add select keeps its value when the bar moves to another phase); R3-swallow-reset-listener-unmount (`diagramDrag.ts:144-150`, the capture listener survives unmount); R3-ctm-stub-not-restored (`FlowEditorMap.test.tsx:417-422`); R3-test-title-overclaims (`FlowEditorMap.test.tsx:521`). Fixed in slice 4 (`R3-*` all four) | [#424](https://github.com/iribarren/ogami/pull/424) |
| 4 (fixes, T6–T7) | `review-86e4a7aef2f996c1` | approved | R3-001 (`FlowMap.tsx:181-194`, `confirmTransition` applies the effect at the phase index and hook captured when the dialog opened, without re-checking `locked` or that the phase is still the one named; untested); R3-002 (`FlowEditor.tsx:332`, the "Added …" status line stays visible across later panel edits; untested). Fixed in slice 5 | [#425](https://github.com/iribarren/ogami/pull/425) |

## Next step

Slice 5 on `feat/studio-flow-map-5-scene-types`: slice 4 review fixes (R3-001, R3-002), T8 (D30-D33), T9 (Playwright, manual), native review, PR, then the closing doc commit on the same PR.
