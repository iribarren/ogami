# 0002. Modular monolith, hexagonal architecture and DDD module layout

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The app serves 1 to a few users, so scaling is not a driver. Features will change often while the game flow is refined, so the structure must keep domain logic isolated and boundaries clear. Learning DDD is an explicit goal.

## Decision

- **One deployable backend** (modular monolith), one module per bounded context from the [context map](../domain/context-map.md).
- **Hexagonal layout** per module:

  ```
  backend/src/<Context>/
    Domain/          entities, value objects, domain events, ports; framework-free
    Application/     use cases (command/query handlers), application services
    Infrastructure/  Symfony, Doctrine, HTTP controllers, adapters
  ```

- **Dependency rule:** Domain ← Application ← Infrastructure. Domain depends on nothing outside itself.
- **Light CQRS:** commands and queries go through Symfony Messenger command and query buses (synchronous). Same database for reads and writes.
- **Domain events** are dispatched in-process; other contexts react through their own application layer.
- **No event sourcing.** State is stored as current state.
- Contexts talk only through application services, published contracts or domain events, never through another context's Domain or Infrastructure.
- External capabilities (e.g. Narrative assist) are ports in the Domain or Application layer with adapters in Infrastructure.

## Consequences

- Domain logic is testable without the framework; rules can change without touching controllers or persistence.
- One process, one database, simple deploy and debugging.
- More files and mapping code than a plain Symfony app.
- Boundaries are enforced automatically with PHPat ([ADR 0012](0012-phpat-boundary-enforcement.md)).
