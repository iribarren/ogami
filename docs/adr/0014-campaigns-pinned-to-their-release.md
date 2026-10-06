# 0014. Campaigns stay pinned to their GameSystem release

- **Status:** Accepted
- **Date:** 2026-10-06

## Context

[ADR 0010](0010-versioned-gamesystem-releases.md) binds a Campaign to one immutable GameSystem release and leaves the upgrade path to a newer release open. Feature `play-campaign-journal` creates the first campaigns, so Play must now decide which release a campaign uses and what happens when a game manager publishes a newer one.

Moving a campaign to a newer release can change oracles, flows and, later, sheet templates. Doing that silently would change a game in the middle of play and could break character data.

## Decision

- Creating a campaign **pins the latest release** of the chosen GameSystem at that moment.
- The campaign stores only the **release reference** (GameSystem key and release version) and the GameSystem name for display. Releases are immutable, so no copy of the content is needed.
- Play re-reads the pinned snapshot through its anti-corruption layer whenever it needs the release content (oracles, flow, sheet).
- Publishing a newer release **never changes existing campaigns**.
- Upgrading a campaign is a future, **explicit player action** (feature `play-release-upgrade`, M5) with a preview of the changes and a confirmation. Until then, a campaign keeps its release for its whole life.

## Consequences

- Running campaigns are stable: rules, oracles and flows never change under the player.
- Campaign storage stays small: a reference, not a content copy.
- Releases must never be deleted or changed while a campaign pins them; immutability ([ADR 0010](0010-versioned-gamesystem-releases.md)) already guarantees it.
- Every read of release content goes through the anti-corruption layer, so Play keeps supporting the schema versions of releases that campaigns still pin.
- Players do not get fixes or new oracles from newer releases until the upgrade feature ships.
