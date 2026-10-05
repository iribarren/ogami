# 0013. Studio is a core context

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The [context map](../domain/context-map.md) left open whether Studio is a core or a supporting context: core for the authoring UX, supporting for Play's value. The answer decides how much modeling and UX effort Studio gets, and how the contract between Studio and Play ([ADR 0010](0010-versioned-gamesystem-releases.md)) must be able to evolve.

TTRPG systems are many and very diverse. Even after Ogami implements flows and rules for popular systems, some systems may need programming: a game flow that adapts to a system's background can require new code, not only new data. The [vision](../vision.md) aims for game systems without code, but cannot promise it for every system.

## Decision

- **Studio is a core context**, alongside Play. Authoring a game system is part of the fun, not a back-office chore.
- `SOLO_PLAYER` and `GAME_MANAGER` are both first-class citizens for UX.
- Game-flow definitions must be flexible enough to create system-specific flows that adapt to a system's background. When a system needs new code (for example a code-backed flow step or rule), the feature is analyzed when the need arises, not up front.

## Consequences

- Studio gets the same modeling and UX investment as Play: rich domain model, rich editors, Gherkin specs and tests.
- The GameSystem release contract (the Published Language, [ADR 0010](0010-versioned-gamesystem-releases.md)) is versioned by **schema version**, so new capabilities, including code-backed flow or rule extensions, arrive as new schema versions without breaking older releases. See the [release contract](../contracts/gamesystem-release.md).
- Play stays decoupled from Studio: it reads releases only through its anti-corruption layer, and supports schema versions explicitly.
- "No code per system" stays the default goal, but a system-specific extension in code is an accepted outcome, decided case by case.
