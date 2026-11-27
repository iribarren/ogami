# 0052. Studio flow map: editing on the diagram

- **Status:** Accepted
- **Date:** 2026-11-27

## Context

Feature `studio-flow-map` (roadmap B35, [#421](https://github.com/iribarren/ogami/issues/421)) puts the flow diagram of B34 ([ADR 0051](0051-flow-diagram-layout-and-rendering.md)) in the Studio flow editor, built from the draft, and lets authors change the flow by dragging on it. ADR 0051 left the choice open: dragging on the same SVG, or React Flow over the same shape.

Every gesture must be an edit the outline editor already makes, go through the same draft and the same save, and leave the publisher's coherence checks as the authority. A flow draft is in progress by nature: the map must not blank while it is incomplete.

## Decision

- **Pointer events on our own SVG.** Dragging and arrow drawing use pointer events on B34's `FlowDiagramView`, hit-tested on the layout's rectangles. No drag library is added. The same events keep the manual and print output unchanged, because the interactive props are optional.
- **The source decides where a drawn effect goes.** An arrow drawn from a Scene Type node edits that Scene Type, and one drawn from a phase hook chip edits that hook in the flow draft. A GameSystem draft has one version for all its sections, so a Scene Type effect is saved on the Scene Type page, never from the flow page: saving it from there would make the flow's next save conflict. The map hands the effect over to that page as an unsaved edit.
- **The draft adapter never throws.** `flowDiagramFromDraft` builds the diagram from the Studio draft in a lenient mode of the same builder the release adapter uses. A dangling Scene Type or table, or a part the editor cannot build into an item, becomes an issue on the node it belongs to. The save's validation stays the authority, and the release adapter keeps failing closed.

## Alternatives

| Option | Why not |
|---|---|
| `@dnd-kit` | DOM-first: its sensors and droppables are built for HTML elements, and adapting them to SVG rectangles costs more code than the hit-testing it would replace |
| React Flow (`@xyflow/react`) | A second renderer that looks unlike the manual diagrams, with its own viewport; the shared `FlowDiagram` shape would need two views kept in step |

## Consequences

- One renderer serves the manual, the print edition and the Studio; interaction is opt-in through props.
- Keyboard access is ours to build: every gesture needs a path on the focused node, and the outline stays the full accessible editor.
- Hit-testing, drag thresholds and touch behaviour are our code to maintain.
- An author drawing an effect from a Scene Type leaves the flow page for the Scene Type page; the flow page's unsaved-changes guard protects the flow draft on the way.
