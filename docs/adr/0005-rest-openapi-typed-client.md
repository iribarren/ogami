# 0005. REST + OpenAPI with a generated typed TypeScript client

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The SPA and the backend must agree on request and response shapes. Hand-written client types drift from the backend.

## Decision

- The backend exposes a **REST API** (JSON) under `/api`.
- The backend **generates an OpenAPI spec** from its controllers and DTOs.
- The frontend uses a **typed TypeScript client generated from that spec**; no hand-written API types.
- The spec export and client generation run as part of the dev workflow and CI.

## Consequences

- Contract changes surface as TypeScript compile errors in the SPA.
- The OpenAPI spec doubles as API documentation.
- REST endpoints are shaped by use cases (commands/queries), not by database tables.
- Generator choice is made in Feature 2 (`bootstrap-monorepo`).
