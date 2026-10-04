# 0003. PHP and Symfony for the backend

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The backend needs a mature framework with good support for DDD-style layering, a message bus, security, an ORM and OpenAPI generation. The developer is fluent in PHP.

## Decision

Use **PHP** (current stable) with **Symfony**:

| Need | Symfony component |
|---|---|
| Command/query buses, domain events | Messenger |
| Persistence | Doctrine ORM / DBAL (PostgreSQL) |
| Authentication, roles | Security |
| HTTP API | HttpKernel controllers, Serializer, Validator |
| Runtime | FrankenPHP (see [ADR 0008](0008-docker-compose-dev-env.md)) |

Symfony lives only in the `Infrastructure` (and wiring) layer of each module ([ADR 0002](0002-modular-monolith-hexagonal-ddd.md)).

## Consequences

- Fast delivery with known tooling and strong static analysis (PHPStan).
- Messenger gives CQRS buses without extra libraries.
- Keeping Domain framework-free needs discipline: no Doctrine attributes or Symfony types in `Domain/`.
