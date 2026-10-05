# 0010. Versioned, immutable GameSystem releases

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Game managers keep editing game systems in Studio: sheet templates, checks, flows and oracles. Campaigns in Play depend on those definitions. If Play read Studio's live drafts, every edit could break running campaigns and character sheets.

## Decision

- Studio publishes a **GameSystem release**: an immutable, versioned snapshot of a GameSystem. This is the **Published Language** between Studio and Play.
- A **Campaign is bound to one release**. Play stores the snapshot and reads it through an **anti-corruption layer** that translates it into Play's own model.
- Editing a GameSystem in Studio only changes its draft. Nothing reaches Play until a new release is published.
- Moving a campaign to a newer release is an explicit player action (upgrade path to be designed).
  Settled by [ADR 0014](0014-campaigns-pinned-to-their-release.md): campaigns stay pinned to their release until the player upgrades.

See the [context map](../domain/context-map.md).

## Consequences

- Editing rules never breaks running campaigns.
- Play and Studio models can evolve independently.
- Releases duplicate data (acceptable at this scale).
- Release validation and the campaign upgrade path need design work (open question).
