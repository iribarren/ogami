# 0038. Studio release view

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

[ADR 0028](0028-studio-gamesystem-drafts.md) lets a game manager view a published release read-only, and the view shows only section counts. The release detail endpoint returns the name, the schema version and `sectionCounts`, but not the `librarySources` that the list row has. A manager cannot see what a release contains without restoring it into the draft.

## Decision

- **The release detail returns every field of the summary plus an `outline`.** It gains `librarySources` (same shape as the summary).
- **`outline` is keyed by section name, like `sectionCounts`.** Each section is an ordered list of `{key, name}` in content order; `name` is the item's display name, or null when the item has none. `sectionCounts` stays, and each outline length equals its count.
- **The outline is computed from the stored release content,** for schema versions 1 and 2. No new persistence.
- **Studio's release view lists the outline** instead of only counts.
- **Out: a campaign count per release.** Studio never reads Play; the dependency points the other way.
- **Out: an HTTP download.** [ADR 0036](0036-export-releases-as-preset-files.md) keeps export console-only.
- **Out: a diff against the previous release.** It becomes the roadmap item `release-diff` (B7j).

## Consequences

- The detail response grows with the number of items, not with their content: keys and names only.
- The change is additive: existing fields keep their names and meaning.
- A release with an item that has no display name shows its key.
