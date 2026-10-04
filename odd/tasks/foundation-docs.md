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
| T1 | `.gitignore`, `README.md`, this feature doc | inline (mechanical) | [ ] | |
| T2 | `docs/vision.md` | delegated writer (2+ non-trivial files, T2–T5) | [ ] | |
| T3 | `docs/domain/context-map.md`, `docs/domain/glossary.md` | delegated writer | [ ] | |
| T4 | `docs/adr/` index + ADRs 0001–0010 | delegated writer | [ ] | |
| T5 | project `CLAUDE.md` | delegated writer | [ ] | |
| T6 | `.atl/skill-registry.md` via skill-registry skill | inline (skill) | [ ] | |

## Acceptance criteria
- Every brainstorm decision maps to exactly one ADR (status Accepted, or Proposed for deferred items).
- Glossary terms are used consistently across all docs.
- All relative links resolve.

## Checks
Passive documentation: no runnable RED/GREEN. Structural readback + link check (`grep` relative links, `test -f`).

## Progress / Evidence
- Repo initialized, branch `docs/foundation` created.

## Next step
T1.
