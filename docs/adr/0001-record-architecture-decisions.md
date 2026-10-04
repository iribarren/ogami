# 0001. Record architecture decisions

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Ogami is built across many sessions, partly by AI agents. Decisions about architecture, stack and workflow must survive between sessions and be easy to find, or they drift.

## Decision

Record every significant architecture decision as an ADR in `docs/adr/`, using Michael Nygard's format:

| Section | Content |
|---|---|
| Context | The forces and constraints |
| Decision | What we do, stated actively |
| Consequences | What becomes easier or harder |
| Status | Proposed, Accepted, Deprecated or Superseded |

- Files are named `NNNN-short-title.md` and listed in the [index](README.md).
- ADRs are immutable once Accepted. A change of mind gets a new ADR that supersedes the old one.

## Consequences

- New contributors and agents can read the "why" behind the codebase.
- Small cost per decision; ADRs stay short (about 20–40 lines).
- The project `CLAUDE.md` links here instead of repeating decisions.
