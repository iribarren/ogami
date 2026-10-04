# 0012. Enforce DDD boundaries with PHPat

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

[ADR 0002](0002-modular-monolith-hexagonal-ddd.md) splits the backend into one module per bounded context, each with Domain, Application and Infrastructure layers. A rule enforced only by convention erodes as soon as a shortcut is convenient, especially when AI agents write code across sessions. Adding a check is cheapest while the codebase is empty. PHPStan is already part of the quality baseline ([ADR 0009](0009-testing-and-quality-strategy.md)); Deptrac was considered and not chosen, to avoid a second tool.

## Decision

Use **PHPat** (a PHPStan extension). Architecture rules live as PHP test classes under `backend/tests/Architecture/` and run inside the normal PHPStan step, locally and in CI.

Rules enforced from day one:

| Rule | Check |
|---|---|
| Layer rule | `Domain` depends only on itself and the shared kernel (`Randomness`, `Shared`) Domain; `Application` depends only on `Domain`; `Infrastructure` may depend on both |
| Framework-free Domain | `Domain` must not use `Symfony\`, `Doctrine\` or any other vendor namespace |
| Context isolation | A context must not use another context's `Domain` or `Infrastructure`; it may use only that context's published `Application` contracts. The shared kernel is the explicit exception |

Not enforced yet (convention only): shared kernel purity, meaning `Randomness` and `Shared` must not depend on any other context. Add it as a rule if it is ever broken.

## Consequences

- A boundary violation fails `make qa` and CI, not a code review.
- The rules are PHP code: refactor-safe and easy for agents to read.
- Every new context needs to be registered in the rule set; the bootstrap provides the pattern.
- No dependency graph visualization (a Deptrac feature); acceptable for now.
