# 0004. React + Vite SPA and UI toolkit

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Play and Studio are both shared with other users. Studio needs rich editors (flow editor, sheet builder, dice/formula editor), so its UX is first-class, not an admin panel. Play needs a clear, iterative flow UI and a journal editor.

## Decision

A **React + Vite single-page app in TypeScript**, with areas `play`, `studio`, `admin` and `shared`.

| Need | Library |
|---|---|
| Styling, components | Tailwind CSS + shadcn/ui |
| Routing, server state | TanStack Router, TanStack Query |
| Data tables | TanStack Table |
| Forms, validation | React Hook Form + Zod |
| Narrative flow editor (Studio) | React Flow |
| Sheet builder drag and drop (Studio) | dnd-kit |
| Dice / formula DSL editor | CodeMirror 6 |
| Journal editor (Play) | Tiptap |
| Component development | Storybook |

- **Libraries are added only when the feature that needs them starts.** The bootstrap installs only the base (Vite, React, Tailwind, shadcn/ui, TanStack Router/Query, Storybook).
- Studio UX gets the same design care as Play.

## Consequences

- Rich editing is possible with proven libraries instead of custom code.
- A separate frontend build; served from the same origin as the API ([ADR 0006](0006-session-cookie-auth-roles.md)).
- Many libraries over time; adding them per feature keeps the bundle and learning curve in check.
