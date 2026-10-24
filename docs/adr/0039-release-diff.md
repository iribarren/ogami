# 0039. Release diff

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

[ADR 0038](0038-studio-release-view.md) lets a game manager read a release's outline but left a diff against the previous release out (roadmap item B7j). A manager reading release N sees what it holds, not what it changed, and comparing two outlines by eye misses content edits. Every release row stores its full content, so the comparison needs no new persistence.

## Decision

The release detail (`GET /api/studio/game-systems/{key}/releases/{version}`) gains a required, nullable `changes`:

- **`null` when there is no previous release:** version 1, or a missing version N-1. The previous release is always version N-1.
- **Otherwise `{previousVersion, previousSchemaVersion, sections}`.** `sections` is `null` when the previous schema version differs from the release's; otherwise it is keyed by every section name of `sectionCounts`, each `{added, removed, changed, reordered}`.
- **`added` and `removed` are outline items** (`{key, name}`): added in this release's content order, removed in the previous release's.
- **`changed` lists `{key, name, previousName, fields}`** in this release's content order for keys in both releases whose stored items differ. `fields` are the top-level property names whose values differ (a property present in only one item included), sorted alphabetically.
- **`reordered` is true** when the keys present in both releases appear in a different relative order. Moving an item is not a change of the item.
- **Items match by the outline's key.** An item without a string key has key `''`; when a key repeats in a section, its first occurrence counts. Values are compared canonically (map keys sorted recursively, list order kept), so a JSONB key reorder is not a change.
- **Only the outline's sections are compared.** GameSystem metadata and properties outside the sections are out.
- **Computed on read** from the stored contents of the two releases. No new persistence and no migration.
- **Studio never reads Play,** so the diff has no campaign counts.

## Consequences

- The response grows with the changed items only: keys, names and property names, not content.
- A detail request reads two releases.
- The change is additive: existing fields keep their names and meaning.
- A change in the GameSystem's metadata (name, description) is not shown.
- Across a schema change the sections differ, so the view only notes both schema versions.
