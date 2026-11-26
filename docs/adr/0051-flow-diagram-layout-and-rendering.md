# 0051. Flow diagram: dagre layout, own SVG renderer

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Readers learn the shape of a narrative flow only from prose: the chapters on the arc and on choosing the next scene, and the flow appendices. Feature `flow-diagram` (roadmap B34, [#417](https://github.com/iribarren/ogami/issues/417)) draws one flow as a read-only diagram, laid out automatically with no saved positions, and puts it in the manual (web and Paper edition) from the real preset data, so it cannot drift.

B34 is also the base of three later features: `studio-flow-map` (B35, a flow map in Studio that can edit), `studio-print-cards` (B36) and `play-tabletop` (B38). They need one diagram component that does not care where the flow comes from.

[ADR 0004](0004-react-vite-spa-ui-toolkit.md) adds a library only when the feature that needs it starts. This feature needs a layout algorithm and a way to draw boxes and arrows. Mermaid was rejected up front: it cannot read preset data without a text detour, and it cannot match the theme tokens or the print stylesheet.

What a flow can say is small and fixed ([ADR 0017](0017-narrativeflow-model.md), [ADR 0018](0018-narrativeflow-control-flow-and-cast.md)): ordered phases, each with a Scene Type selection, hooks, and effects (`nextScene`, `switchSceneType`, `endPhase`) that move between scenes. There are no jumps or branches between phases.

## Decision

- **Layout.** `@dagrejs/dagre` lays out the inside of each phase, ordering its Scene Types to cut crossings. Our code stacks the phases in their fixed order and routes the arrows between phases, the exit stub and the end marker. Positions are deterministic for the same input.
- **Rendering.** Our own React component draws SVG. It is themed by CSS variables and stays vector in the Paper edition's PDF, where Chromium keeps inline SVG as paths. A text alternative (phases, Scene Types, every transition) is rendered beside it.
- **One neutral shape.** `FlowDiagram` in `frontend/src/shared/flowDiagram/` holds phases, selections, hooks and transitions, independent of its source. An adapter per source builds it: this feature adds the one for release content (release schema version 2 JSON, which the preset files share); B35 adds the one for the Studio draft.
- **What the diagram draws.**
  - A transition is drawn from where its effect sits: a Scene Type, or a hook of the phase. An effect on the entry of a table that a step rolls is drawn dashed and labelled with that table. Effects on the entries of an `oracle` selection table come from the table node.
  - `nextScene` and `switchSceneType` point to a Scene Type of the same phase; one outside the phase's selection is drawn there, marked as reached by a transition. `endPhase` points to the next phase, or to the end of the flow after the last one.
  - Selections show their rule: `sequence` chained, `player` as a choice, `oracle` as one table node. Hooks are compact chips with a step count.
- **Fail closed.** The adapter throws a named error for malformed content, never a default.

## Alternatives

| Option | Size (gzip, measured 2026-11-27) | Why not |
|---|---|---|
| `elkjs` 0.12.2 | about 479 kB, asynchronous | Better layered layout, but 28 times the size of dagre and an async API for a static, print-friendly drawing |
| React Flow (`@xyflow/react` 13) | about 50 kB plus a layout library | An HTML viewport with pan and zoom, awkward for the static manual and for print; the editing it adds is B35's concern |
| Hand-written layout | none | Messy on dense flows: Ironsworn's flow has 47 scene arrows, West Marches' 37 |
| Mermaid | not measured | Reads text, not preset data; cannot follow the theme tokens or print as part of our stylesheet |
| `@dagrejs/dagre` 3.1.4 | about 17 kB | Chosen: small, synchronous, layered layout with crossing reduction |

## Consequences

- The manual diagrams are built from the preset files PHP publishes, so a preset change shows in the manual and a malformed preset fails the manual tests.
- The shared shape is the seam for B35, B36 and B38. B35 may add dragging on the same SVG, or React Flow over the same shape and positions, without changing the adapters or the text alternative.
- dagre is added to `package.json` in slice 2 with the layout, not with this ADR's slice, in line with ADR 0004.
- Phases stack vertically in their fixed order, so a very long flow makes a tall diagram. The manual's one-phase placements cut one phase out and end its exit arrows in a stub naming the next phase.
- The renderer is ours to maintain: arrow routing, labels and the theme are our code.
