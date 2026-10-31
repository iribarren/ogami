# 0047. Places

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

The vision left one question open: "How are places modeled (a map, regions, sites)?" Example 5 ([West Marches](../domain/flow-examples.md#5-west-marches)) keeps the town and the sites as free Campaign facts: nothing can pick a site, tag it by region, or create one from a rumor or site table. Exploration also found that `closeThread` only closes the `{picked}` Thread, which is cleared at every scene boundary, so a session closing cannot close the expedition Thread picked in the session opening.

## Decision

- **A Places list beside NPCs and Threads.** Rejected: Campaign facts with a `place` slot (no `pick`, no Tags, no `createPlace`: a fact is a statement, not something a flow draws) and regions with a distance (it duplicates tracker bands, which already choose the encounter table by `away` in example 5).
- **A Place mirrors a Thread.** It has an id, a name (required, at most 200 characters), release Tags and free Tags, a weight (1 to 3), a status `open` or `closed` (only open Places are picked and counted; closed means cleared or lost) and optional `notes` (at most 2,000 characters, blank reads as none). A campaign holds at most 500 Places. There is no delete, as for NPCs and Threads.
- **Distance and regions are not attributes.** Encounter tables by distance use tracker bands. A region is a release Tag on Places (`near`, `deep`...).
- **The contract grows within schema version 2, no version bump.** Fact slot type `place` and fact links of kind `place`; effect `createPlace {name?, tags?}`, like `createThread`; `pick` and the condition `count` accept `of: place`; `tag.appliesTo` accepts `place`; `closeThread` gains an optional `fact`, the key of a `thread` slot: it closes the Thread filling that slot (nothing when the slot is empty), and without `fact` it closes `{picked}` as today. Studio refuses a `fact` naming an unknown or non-`thread` slot.
- **A Place never joins the Scene cast.** The cast lists NPCs; the Place created or picked becomes `{picked}` only.
- **Transfer carries Places** with their ids, as Threads ([ADR 0046](0046-campaign-transfer.md)).
- **The upgrade planner treats Place Tags like Thread Tags**: Tags the new release no longer defines are dropped and reported.
- **Known gap.** A slot whose type changes but keeps its key is not detected by the upgrade planner. It is not fixed here.
- **Delivery.** Slice 1 is the Places list and its API; slice 2 the contract and flows; slice 3 the UI. Until slice 2 no release Tag applies to a Place, so a Place takes free Tags only.

## Consequences

- West Marches can create a site from a table, pick one for an expedition and tag it by region.
- Places are a third list in the campaign row (one more JSONB column) with the same limits and API shape as Threads, so the UI reuses the list panel.
- No map and no adjacency: routes between Places are not modeled.
- `closeThread` by slot lets a session closing close a Thread that an earlier scene picked.

## Out of scope

- A drawn map, adjacency or routes between Places.
- A distance or region attribute on a Place.
- Formulas or effects reading a Place.
