# Architecture decision records

Why Ogami is built the way it is. One decision per record, in Nygard format ([ADR 0001](0001-record-architecture-decisions.md)). Add a new ADR to supersede an old one; do not rewrite Accepted ADRs.

| # | Title | Status |
|---|---|---|
| 0001 | [Record architecture decisions](0001-record-architecture-decisions.md) | Accepted |
| 0002 | [Modular monolith, hexagonal architecture and DDD module layout](0002-modular-monolith-hexagonal-ddd.md) | Accepted |
| 0003 | [PHP and Symfony for the backend](0003-php-symfony-backend.md) | Accepted |
| 0004 | [React + Vite SPA and UI toolkit](0004-react-vite-spa-ui-toolkit.md) | Accepted |
| 0005 | [REST + OpenAPI with a generated typed TypeScript client](0005-rest-openapi-typed-client.md) | Accepted |
| 0006 | [Session cookie authentication, same origin, role model](0006-session-cookie-auth-roles.md) | Accepted |
| 0007 | [PostgreSQL with JSONB for configurable definitions](0007-postgresql-jsonb.md) | Accepted |
| 0008 | [Full Docker Compose dev environment and Makefile](0008-docker-compose-dev-env.md) | Accepted |
| 0009 | [Testing and quality strategy](0009-testing-and-quality-strategy.md) | Accepted |
| 0010 | [Versioned, immutable GameSystem releases](0010-versioned-gamesystem-releases.md) | Accepted |
| 0011 | [gentle-ai development workflow](0011-gentle-ai-workflow.md) | Accepted |
| 0012 | [Enforce DDD boundaries with PHPat](0012-phpat-boundary-enforcement.md) | Accepted |
| 0013 | [Studio is a core context](0013-studio-is-a-core-context.md) | Accepted |
| 0014 | [Campaigns stay pinned to their GameSystem release](0014-campaigns-pinned-to-their-release.md) | Accepted |
| 0015 | [Sequential slice delivery to main](0015-sequential-slice-delivery.md) | Accepted |
| 0016 | [The flow is presented in the journal, with an optional focus mode](0016-flow-presentation-journal-with-focus-mode.md) | Accepted, amended by 0017 |
| 0017 | [The NarrativeFlow model](0017-narrativeflow-model.md) | Accepted, amended by 0018, 0022 and 0023 |
| 0018 | [NarrativeFlow control flow and cast](0018-narrativeflow-control-flow-and-cast.md) | Accepted, amended by 0020 |
| 0019 | [Random events from fate-question doubles](0019-random-events-from-fate-question-doubles.md) | Accepted |
| 0020 | [The scene pick waits after a scene](0020-scene-pick-waits-after-a-scene.md) | Accepted |
| 0021 | [Release credits for fan presets](0021-release-credits-for-fan-presets.md) | Accepted |
| 0022 | [Character sheets in schema version 2](0022-character-sheets-in-schema-version-2.md) | Accepted, amended by 0023 |

## Template

```markdown
# NNNN. Title

- **Status:** Proposed | Accepted | Deprecated | Superseded by NNNN
- **Date:** YYYY-MM-DD

## Context
## Decision
## Consequences
```
| 0023 | [Formula kernel and derived values](0023-formula-kernel-and-derived-values.md) | Accepted |
