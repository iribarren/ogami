# 0015. Sequential slice delivery to main

- **Status:** Accepted
- **Date:** 2026-10-07
- **Amends:** [ADR 0011](0011-gentle-ai-workflow.md) (branches, pull requests, delivery strategy)

## Context

Feature 5 `play-campaign-journal` (~14k changed lines) was delivered as a feature-branch chain: a tracker branch, nine slice branches each based on the previous one, and ten PRs open at once. Two problems followed:

- **Native reviews that never ran.** The review stop hook always proposes the whole branch since `main`. On a long-lived feature branch that candidate grew from 1k to 18k lines: it went stale whenever a writer committed in the background, or stopped with `lens_context_budget_exceeded`. Slices bloated by generated files (`openapi.json`, `schema.d.ts`, `routeTree.gen.ts`) hit the same budget. Only the per-slice reviews worked.
- **Confusing merges.** Chained PR bases, `delete_branch_on_merge` off and a broken `gh pr edit --base` meant every PR had to be retargeted by hand in the right order. One slice was merged into the wrong branch and needed a revert and a revert of the revert.

## Decision

| Practice | Rule |
|---|---|
| Delivery strategy | **Sequential slices to `main`** for every feature. Replaces `ask-on-risk`, feature-branch chains, stacked PRs and tracker branches; agents do not ask for a chain strategy |
| Slice plan | Written in the feature doc before the first write: each slice lists its tasks and a size forecast of about **800–1,500 changed lines including generated files** (OpenAPI spec, TS types, route tree) |
| Branches | One branch per slice, from the latest `main`, named `type/<feature>-<n>-<topic>` (e.g. `feat/play-flow-run-1-domain`). The next slice branches only after the previous slice is merged |
| Pull requests | One PR per slice, base `main`, title `type(scope): <feature> <n>/<total> <topic>`. **At most one open PR per feature.** The last slice's PR says `Closes #N`; earlier ones say `Part of #N` |
| Shippable slices | Every slice must be safe on `main` alone: backend slices are unused until their UI lands; unfinished UI stays on an unlinked route |
| Review sequencing | No writer runs while a native review starts or captures. Order per slice: writer finishes and commits → review (assess, consent, capture, acknowledge) → PR → user merges → next slice. Answer the review stop hook only when the working tree is clean and no writer is running |
| Feature doc commits | The writer updates the feature doc (progress, evidence) inside its work-unit commit; review outcomes and PR links go into the next slice's first commit. No standalone doc-only progress commits, no uncommitted doc edits left at the end of a turn |
| Repository setting | `delete_branch_on_merge` is on |

Unchanged from ADR 0011: ODD, issue per feature, merge commits only, work-unit commits, Conventional Commits, RDD on, push/PR/merge stay human decisions.

## Consequences

- "Everything since `main`" is always the current slice, so the stop-hook candidate is the review we want and stays within the reviewer budget.
- One open PR per feature removes merge-order and retargeting mistakes.
- A feature reaches `main` in several merges; `main` must stay releasable after each one.
- Slices are planned up front; a slice that grows past ~1,500 lines is split before review, not after.
