# 0011. gentle-ai development workflow

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

Learning the gentle-ai methodology is a project goal. Work is done by a human and AI agents across sessions, so progress must be recoverable and reviewable.

## Decision

| Practice | Rule |
|---|---|
| ODD | Every feature follows Organic Driven Development. Substantial work gets a feature doc in `odd/tasks/<feature>.md`, mirrored in Engram (`odd/<feature>/tasks`) |
| Branches | One branch per feature named `type/description` (gentle-ai `branch-pr` pattern); never commit features directly to `main` |
| Issues | Lightweight issue-first: one GitHub issue per feature; no `status:approved` gate or blocking Action for now |
| Pull requests | One PR per feature, body says `Closes #N`, exactly one type label. A PR over ~400 changed lines is split per the delivery strategy; passive documentation may take an explicit size exception |
| Merge method | Merge commit. No squash or rebase: work-unit commits and the hashes recorded in feature docs must survive |
| Work-unit commits | Each task closes with at least one commit that is a coherent unit (docs and tests with the change) |
| Commit messages | Conventional Commits |
| Delivery strategy | `ask-on-risk`: when a feature exceeds about 400 authored lines, decide how to slice PRs |
| RDD (native review) | **Enabled for this clone right after Feature 2 `bootstrap-monorepo`**, before the first domain feature (`gentle-ai review mode enable --scope clone`). The bootstrap is built with ODD and ordinary checks only |
| Push, PR, merge | Human decisions only; agents never push or open PRs without the user |

## Consequences

- Any session can resume a feature from its doc and Engram mirror.
- Commit history reads as a story of work units.
- During the bootstrap, review relies on tests, QA tools and the human.
- From the first domain feature on, each work-unit commit gets a native risk assessment; medium/high candidates ask for consent before a review.
