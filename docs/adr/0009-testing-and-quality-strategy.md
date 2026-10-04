# 0009. Testing and quality strategy

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Rules, dice and flows must behave exactly as specified. The ubiquitous language must stay alive in code. ODD asks for test-first work where a runnable test exists.

## Decision

| Area | Tool | Use |
|---|---|---|
| Backend static analysis | PHPStan (level max) | Type safety, architecture rules |
| Backend style / refactoring | PHP-CS-Fixer, Rector | Consistent code, automated upgrades |
| Backend unit / integration | PHPUnit | Domain and application tests |
| Behaviour specs | Behat + Gherkin | Living spec in the [ubiquitous language](../domain/glossary.md) |
| Frontend unit / component | Vitest | Logic and components |
| End to end | Playwright | Critical user journeys against the compose stack |
| Frontend lint / format | ESLint, Prettier | Consistent code |
| CI | GitHub Actions | Runs all QA and tests on every push |

- Test first (RED → GREEN → refactor) when a deterministic runnable test exists.
- Domain code is tested without the framework; randomness is injected so rolls are deterministic in tests.
- Gherkin scenarios use glossary terms only.

## Consequences

- High confidence when rules and flows change.
- Gherkin features document behaviour for humans and agents.
- More tooling to keep green; `make qa` and `make test` wrap it (Feature 2).
