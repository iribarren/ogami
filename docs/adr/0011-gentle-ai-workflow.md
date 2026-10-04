# 0011. gentle-ai development workflow

- **Status:** Proposed (RDD native review decision deferred; the rest is in use)
- **Date:** 2026-10-05

## Context

Learning the gentle-ai methodology is a project goal. Work is done by a human and AI agents across sessions, so progress must be recoverable and reviewable.

## Decision

| Practice | Rule |
|---|---|
| ODD | Every feature follows Organic Driven Development. Substantial work gets a feature doc in `odd/tasks/<feature>.md`, mirrored in Engram (`odd/<feature>/tasks`) |
| Branches | One branch per feature; never commit features directly to `main` |
| Work-unit commits | Each task closes with at least one commit that is a coherent unit (docs and tests with the change) |
| Commit messages | Conventional Commits |
| Delivery strategy | `ask-on-risk`: when a feature exceeds about 400 authored lines, decide how to slice PRs |
| RDD (native review) | **Deferred.** Currently globally off; revisit once the skeleton exists |
| Push, PR, merge | Human decisions only; agents never push or open PRs without the user |

## Consequences

- Any session can resume a feature from its doc and Engram mirror.
- Commit history reads as a story of work units.
- Without RDD, review relies on tests, QA tools and the human.
- This ADR becomes Accepted once the RDD decision is made (or is superseded by one that records it).
