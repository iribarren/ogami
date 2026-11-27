# Feature: flow-diagram

- **Locator:** `odd/tasks/flow-diagram.md` · Engram topic `odd/flow-diagram/tasks`
- **Issue:** [#417](https://github.com/iribarren/ogami/issues/417) · **Branch:** `feat/flow-diagram-3-manual` · **Status:** slice 3 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B34 in [`docs/roadmap.md`](../../docs/roadmap.md); base of B35 `studio-flow-map`, B36 `studio-print-cards`, B38 `play-tabletop`

## Objective

A read-only diagram of one narrative flow, laid out automatically (no saved positions), and flow diagrams in the manual, web and Paper edition, rendered from the real preset data so they cannot drift.

Out of scope: editing (B35), saved positions, phase jumps or branches between phases (the model has none), the Spanish edition.

## Problem and why

- Readers learn a flow's shape only from prose (`the-arc`, `choosing-the-next-scene`, the flow appendices).
- B35, B36 and B38 need one diagram component that does not care where the flow comes from.

## Evidence

- Flow shape (ADR 0017, ADR 0018, `docs/contracts/gamesystem-release.md`): `flows[]` → `phases[]` in array order, each `{key, name, act?, description?, mode: once|loop, selection, <hooks>}`. Selection `{rule: sequence|player, sceneTypes: [keys]}` or `{rule: oracle, table: key}` (entries may carry `sceneType`). Hooks: `sessionOpening, sessionClosing, phaseOpening, phaseClosing, sceneOpening, sceneClosing, worldTurn`, each a list of steps (`backend/src/Studio/Domain/Release/Version2/ReleaseVersion2.php:50`).
- Effects are tagged on `kind`: `{nextScene, sceneType}`, `{switchSceneType, sceneType}`, `{endPhase}`; others (`tracker`, `sceneTitle`, `sheet`, threads…) are not transitions. They sit on steps, bands, branches and table entries; `backend/src/Studio/Domain/Release/Version2/StepParts.php:84` (`effects`) and `FlowReferences.php:196` (`reach`) walk them.
- Manual flows: in Cold Berth (`backend/presets/dogwatch.json`) and The Long Carry (`hard-country.json`) every `nextScene` sits on the entries of a table rolled by the `worldTurn` hook (`ship-trouble`, `road-risks`) and every `endPhase` on a `sceneClosing` hook step. The Long Carry's `the-road` selects by oracle (`road-deck`: camp, ford, stranger, ruin, omen). Dense flows: Ironsworn (47 scene arrows), West Marches (37).
- Frontend: `FlowSummaryResponse` has no phases; the only typed phase model is Studio's draft (`frontend/src/studio/narrative/flows/flowDraft.ts`). Preset JSON reaches the frontend only in the print entry (`frontend/src/manual/print/PrintApp.tsx:13`, `preset.ts`).
- Manual: `react-markdown` + `remark-gfm`; the only block marker is `:::in-ogami`, split by hand in `frontend/src/manual/editionBlocks.ts` (unknown `:::` lines throw). Print: `make manual-pdf`, `print.css` black on white with no app tokens; Chromium PDF keeps inline SVG as vector.
- Library sizes (gzip, measured 2026-11-27): `@dagrejs/dagre` 3.1.4 ≈ 17 kB (dagre.min.js), `elkjs` 0.12.2 ≈ 479 kB, `@xyflow/react` 13 ≈ 50 kB plus a layout library.

## Decisions

| # | Decision | By |
|---|---|---|
| D1 | Layout and rendering: `@dagrejs/dagre` lays out the inside of each phase (ordering Scene Types to cut crossings); our code stacks phases in their fixed order and routes phase arrows; our own React SVG renders it (vector in print, theme via CSS variables). Recorded as ADR 0051. elkjs (size, async), React Flow (HTML viewport, awkward static and print) and a hand-written layout (messy on dense flows) rejected. B35 drags on the same SVG or adds React Flow then, over the same shape and positions | User (2026-11-27) |
| D2 | One neutral shape, `FlowDiagram`, in `frontend/src/shared/flowDiagram/`, independent of its source. Slice 1 adds the adapter from release content (release schema v2 JSON, which preset files share); B35 adds the Studio draft adapter | Parent |
| D3 | Transitions are drawn from where the effect sits: a Scene Type (its setup, play and closing steps, their bands, branches and options, and the entries of tables those steps roll) or a hook on its phase (its steps, the same parts, and the tables they roll). Arrows from a rolled table's entries are marked `via` that table (drawn dashed, labelled with its name). Effects on the entries of a phase's `oracle` selection table are drawn from the table node | User (2026-11-27) |
| D4 | Targets: `nextScene` / `switchSceneType` → a Scene Type node in the same phase; `endPhase` → the next phase, or the flow's end after the last phase. A target Scene Type outside the phase's selection is drawn in that phase, marked "reached by a transition" (transitively). Identical transitions (same kind, source, target, via) appear once | Parent |
| D5 | Selection nodes: `sequence` keeps its order (chained), `player` is a choice (spread), `oracle` is one table node listing the Scene Types its entries draw, in table order, without duplicates | Parent (from the roadmap prompt) |
| D6 | Hooks show compactly on their phase: one chip per non-empty hook, in `PHASE_HOOKS` order, with its label and step count. Hook labels live in the shared module (Studio's `flowDraft.ts` is not imported) | Parent |
| D7 | Malformed content fails closed: the adapter throws a `FlowDiagramError` naming the path (unknown flow key, phase without `mode`, unknown selection rule, an effect pointing to an unknown Scene Type or table). Unknown effect kinds that are not transitions are ignored | Parent |
| D8 | `phaseDiagram(diagram, phaseKey)` cuts one phase out for the manual's one-phase placements; its `endPhase` and next-phase arrows end in an exit stub naming the next phase | Parent |
| D9 | Text alternative: `describeFlowDiagram(diagram)` builds a structured list (phases in order with mode and hooks, their Scene Types, every transition with its source, kind, target and table); slice 2 renders it beside the SVG | Parent |
| D10 | Manual placements, approved 2026-11-27: `flow-cold-berth` and `flow-the-long-carry` under "The Phases" (whole flow); `the-arc` in "The Long Carry" and "Cold Berth" (whole flow); `choosing-the-next-scene` rule sections (Sequence → Long Carry `session-zero`, Player → Cold Berth `the-ship`, Oracle → Long Carry `the-road`) only; its example sections get none (user, 2026-11-27: they would repeat the rule-section diagrams). All outside `:::in-ogami`, so they print | User (2026-11-27) |
| D11 | Directive syntax (slice 3): one line `::flow-diagram{flow="cold-berth"}` or `::flow-diagram{flow="cold-berth" phase="the-ship"}`, handled by the `editionBlocks.ts` splitter; an unknown flow or phase fails the manual tests | Parent |
| D12 | The splitter only parses: a directive becomes its own segment (`kind: 'flow-diagram'`, flow, optional phase) in the edition it sits in; any other `::name` line, or a malformed `::flow-diagram` line, throws with its line number; inside a code fence it stays text. A manual module resolves flow keys against the two manual presets (`dogwatch.json`, `hard-country.json`, the same files the card sheets read); an unknown flow or phase throws. The Paper edition keeps segments in order instead of joining them into one Markdown string | Parent |
| D13 | Print: `print.css` defines the five theme variables the view uses (black on white), keeps a diagram on one page and hides the `<details>` text alternative (the chapter prose already says it); the web manual shows the text alternative as in slice 2 | Parent |
| D14 | R3-004 (slice 2): a nested `table: null` on a table entry is read as absent, as `FlowReferences` and the preset reader do; any other non-string still throws | Parent |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/3 | T1, T2 | ~1,000–1,300 lines: ADR + index ~130, shape, adapter, phase cut and text alternative ~450, Vitest ~550, feature doc ~150 | `feat/flow-diagram-1-model` |
| 2/3 | T2b, T3, T4 | ~1,200–1,500 lines: `@dagrejs/dagre` + lockfile ~40, layout ~350, SVG renderer + text alternative ~450, Storybook story ~80, Vitest ~450 | `feat/flow-diagram-2-render` |
| 3/3 | T4b, T5, T6 | ~700–1,000 lines: directive in the splitter and renderer ~150, preset loading in the manual ~60, print styles ~60, chapter placements ~40, manual tests ~250, roadmap/doc ~60 | `feat/flow-diagram-3-manual` |

Shippable: slices 1 and 2 add code nothing imports outside its tests and story; slice 3 puts the diagrams in the manual.

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | ADR 0051 (D1, D2, D3) and its row in `docs/adr/README.md` | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `0a4dc33b` |
| T2 | 1 | `FlowDiagram` shape, release-content adapter (D2–D7), `phaseDiagram` (D8), `describeFlowDiagram` (D9), with Vitest on Cold Berth, The Long Carry, Ironsworn and hand-made fixtures | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `19d614d8` |
| T2b | 2 | Slice 1 review fixes R3-001–R3-004 | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `566f2875` |
| T3 | 2 | Layout: phases stacked in order, dagre inside each phase, phase and self arrows, exit stub, end marker; deterministic positions | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `4692459d`, `83da4bdf` |
| T4 | 2 | `FlowDiagramView` SVG renderer (theme tokens, dashed `via` arrows, hook chips) with the text alternative, Storybook story, Vitest | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `52b9b189` |
| T4b | 3 | Slice 2 review fixes R3-001–R3-004 | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T5 | 3 | `::flow-diagram{…}` directive, preset data in the manual chapter pages and the Paper edition, print styles | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T6 | 3 | Placements (D10) and a test that every directive in the chapters resolves; `make manual-pdf` checked by hand | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T6b | 3 | Print theme tokens and one label per hook table group (Chrome/PDF pass findings) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |

## Acceptance criteria

- The diagram shows exactly what the model expresses (D3–D6); arrows it cannot express never appear.
- Cold Berth and The Long Carry diagrams appear in the approved chapters (D10), legible in light and dark, and print as vector in the Paper edition.
- Each diagram has a text alternative listing phases, scenes and transitions.
- Malformed content and unknown directive targets fail closed with a clear error.

## Checks

- Vitest for the adapter, phase cut, text alternative, layout, renderer and directive; ESLint, Prettier, tsc via `make verify`.
- `make manual-pdf` by hand in slice 3 (vector diagrams in the PDF).

## Progress

- 2026-11-27: Feature doc created; three slices, six tasks; placements and library approved.
- 2026-11-27: T1 done: ADR 0051 and its index row.
- 2026-11-27: T2 done: `shared/flowDiagram` (shape, adapter, phase cut, text alternative); 48 Vitest tests, RED then GREEN; `nextPhase` targets also carry the phase name so a one-phase cut can name its exit stub.
- 2026-11-27: T2b done: R3-001, R3-003, R3-004 fixed with tests; R3-002 kept as a throw because the backend rejects oracle selection entries without a Scene Type (`FlowReferences::checkOracleSelections`), now commented.
- 2026-11-27: T3 done: `layoutFlowDiagram` (dagre 3.1.4 inside each phase, own routing for hook, self, exit and phase arrows); 12 Vitest tests, RED (module missing) then GREEN.
- 2026-11-27: T4 done: `FlowDiagramView` (inline SVG on theme variables, text alternative in a `<details>` linked by `aria-describedby`), four Storybook stories on the real presets; 7 Vitest tests, RED (component missing) then GREEN.
- 2026-11-27: T3 amended: `phaseDiagram` records `continuesTo`, so a cut of a non-last phase always ends in an exit stub naming the next phase, with or without an `endPhase` transition; "End of flow" only for the last phase.
- 2026-11-27: T4b done: hook gutters step inward inside a width reserved per phase (R3-001, bounds test on Cold Berth, The Long Carry and Ironsworn, RED then GREEN); text-alternative keys carry the index (R3-002); `testSupport.test.ts` chdirs to `os.tmpdir()` (R3-003); nested `table: null` read as absent (R3-004, D14), RED then GREEN.
- 2026-11-27: T5 done: `Segment` is a union and the splitter parses `::flow-diagram{flow="…" phase="…"}` (unknown or malformed `::` lines throw with their line); `manualFlowDiagram` resolves flows against the two manual presets; `ManualFlowDiagram` renders in the chapter page and the Paper edition (`PaperChapter.blocks`); `print.css` themes the diagram black on white and hides the text alternative; splitter tests RED (19 failing) then GREEN, the rest written with the code (no meaningful RED for glue).
- 2026-11-27: T6 done: seven directives placed (D10), no new prose; phase keys `session-zero`, `the-ship`, `the-road` match their sequence, player and oracle rules; a content test resolves every directive and pins the seven placements; `make manual-pdf` ran and printed the Paper edition and both card sheets (visual check by the parent).
- 2026-11-27: T6b done: `DIAGRAM_TOKENS` lists every theme token the view paints with and `print.css` defines `--muted` and `--accent` too (a test reads the CSS against the list); hook edges from one hook through one table share a single label, ending left of the group's leftmost gutter inside the phase box (bounds test on Cold Berth and The Long Carry); the text alternative still lists every transition. RED then GREEN for both.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 | review-6645489f33876474 | approved, acknowledged | R3-001 `testSupport.ts` reads presets from `process.cwd()`, use `import.meta.url`; R3-002 oracle-selection entries without `sceneType` throw, check the backend validation and relax if it accepts them; R3-003 a non-string nested `table` reference must throw `FlowDiagramError`; R3-004 `describeFlowDiagram` lower-cases Scene Type origins of `via` transitions, only hook labels should be. All fixed in slice 2 (T2b) | [#418](https://github.com/iribarren/ogami/pull/418) |
| 2 | review-a927f41ced1bf32e | approved, acknowledged | R3-001 `layout.ts:578/588` hook gutter x (`width - PHASE_PAD/2 + GUTTER_STEP*index`) leaves the phase box and viewBox with several hook edges (Cold Berth `the-ship` has four); R3-002 `FlowDiagramView.tsx:130` text-alternative key is the sentence, duplicates possible; R3-003 `testSupport.test.ts` chdirs to a fixed system directory, use `os.tmpdir()`; R3-004 `fromReleaseContent.ts:193` nested `table: null` throws (D14). All to fix in slice 3 (T4b) | [#419](https://github.com/iribarren/ogami/pull/419) |

## Next step

Slice 3 (T4b, T5, T6), Chrome pass on the manual pages and `make manual-pdf`, review, PR, closing doc commit.
