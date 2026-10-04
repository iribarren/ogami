# Feature: foundation-docs

- **Locator:** `odd/tasks/foundation-docs.md` · Engram topic `odd/foundation-docs/tasks`
- **Branch:** `docs/foundation` (branch point `5e441a6`, `main`)
- **Delivery strategy:** ask-on-risk · RDD: off (global), deferred decision

## Objective
Record the brainstorm decisions as durable project docs so every later feature builds on a shared vision, domain language and architecture.

## Problem / Why
The project is new. Without written vision, context map, glossary and ADRs, DDD boundaries and stack choices would drift between sessions and agents.

## Scope
- In: README, vision, context map, glossary, ADRs, project CLAUDE.md, skill registry.
- Out: any application code, Docker or tooling (Feature 2 `bootstrap-monorepo`).

## Constraints
- Technical artifacts in English.
- Docs follow cognitive-doc-design (scannable, low cognitive load).
- Decisions source: `~/.claude/plans/we-are-going-to-polymorphic-metcalfe.md` and Engram `ogami/brainstorm/*`.

## Forecast
~700 authored lines (docs only, passive). Above the ~400 line heuristic, but all of it is passive documentation, so ask-on-risk: a single docs PR is proposed, and the user decides at PR time.

## Tasks
| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | `.gitignore`, `README.md`, this feature doc | inline (mechanical) | [x] | 7afa950 |
| T2 | `docs/vision.md` | delegated writer (2+ non-trivial files, T2–T5) | [x] | 87718d8 |
| T3 | `docs/domain/context-map.md`, `docs/domain/glossary.md` | delegated writer | [x] | 1221113 |
| T4 | `docs/adr/` index + ADRs 0001–0011 | delegated writer | [x] | e16f97d |
| T5 | project `CLAUDE.md` | delegated writer | [x] | f723d29 |
| T6 | `.atl/skill-registry.md` via skill-registry skill | inline (skill) | [ ] | |

## Acceptance criteria
- Every brainstorm decision maps to exactly one ADR (status Accepted, or Proposed for deferred items).
- Glossary terms are used consistently across all docs.
- All relative links resolve.

## Checks
Passive documentation: no runnable RED/GREEN. Structural readback + link check (`grep` relative links, `test -f`).

## Progress / Evidence
- Repo initialized, branch `docs/foundation` created.
- T2 `87718d8`: `docs/vision.md` (problem, roles, goals, non-goals, pillars, success criteria, open questions). Structural readback OK.
- T3 `1221113`: context map (6 contexts, Mermaid diagram, relationship table, per-context responsibilities/aggregates/roles) and glossary (terms → definition → context). Structural readback OK.
- T4 `e16f97d`: ADR index + 11 Nygard ADRs (0001–0010 Accepted, 0011 gentle-ai workflow Proposed: RDD deferred). Structural readback OK.
- T5 `f723d29`: project `CLAUDE.md` (doc links, architecture rules, naming, planned layout, workflow, commands TBD).
- Link check over all tracked `*.md`: 43 relative links, 0 broken.

## Next step
T6 (skill registry, parent inline).
