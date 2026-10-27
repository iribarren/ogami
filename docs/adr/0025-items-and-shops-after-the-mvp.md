# 0025. Items and shops after the MVP

- **Status:** Accepted, settled by [0045](0045-gear-inventories-currency-and-shops.md): gear, inventories, a currency and shops
- **Date:** 2026-10-14

## Context

[ADR 0018](0018-narrativeflow-control-flow-and-cast.md) decision 25 left one question open: are items, inventory and a `shop` step kind needed? It was to be settled by `preset-real-system` (feature 13) or a post-M3 feature.

Feature 13 builds the MVP's real-system preset, Percentile Horror: a 1920s investigation game on an open-licensed percentile rules base. Its investigators carry gear as narrative color (a lantern, a revolver, a notebook); nothing in play depends on a price, a quantity or a slot. A `shop` step kind has no use there, and designing items without a preset that exercises them would guess at the shape.

## Decision

Items, inventory, currency and a `shop` step kind move to a post-M3 backlog feature, `play-items-economy`, to be started when a preset needs them (for example `preset-cpr-campaign`).

- **Until then:** gear is a flat sheet `list` field ([ADR 0022](0022-character-sheets-in-schema-version-2.md)). Money, if a preset has any, is a tracker or a `number` sheet field.
- **`play-items-economy` scope (not decided here):** structured items with quantity, cost and slots, a currency, and a `shop` step kind. Its design gets its own ADR.
- **ADR 0018:** the open question on items, inventory and `shop` is settled by this ADR.

## Consequences

- The MVP completes without an item model. The release contract stays as it is for gear: lists of strings that formulas cannot read.
- A preset that needs priced or counted gear (Cyberpunk RED rent and lifestyle, a shopping scene) waits for `play-items-economy`.
- Because the question is settled by deferral, nothing in the contract blocks a later additive change: a new field type or step kind would be additive in schema version 2, or a new schema version.
