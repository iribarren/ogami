# 0007. PostgreSQL with JSONB for configurable definitions

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Game content is configurable data: sheet templates, derived value formulas, checks, narrative flows and oracle tables differ per game system. Character sheets follow those templates. A fixed relational schema per game system is not possible.

## Decision

- Use **PostgreSQL** as the single database.
- Store configurable definitions (sheet templates, flows, checks, oracle tables, GameSystem release snapshots) and sheet instance values as **JSONB** columns.
- Use regular relational columns for identity, ownership, versions and relationships (users, campaigns, releases).
- Domain objects own the structure; Infrastructure maps them to and from JSONB.

## Consequences

- Flexible schemas per game system without migrations for each content change.
- Relational integrity where it matters (who owns what, which release a campaign uses).
- JSONB contents need validation in the domain, not in the database.
- Querying inside JSONB is possible but should stay rare.
