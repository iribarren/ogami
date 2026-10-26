# 0030. Studio flow editor

- **Status:** Accepted, amended by [0042](0042-flow-choices-check-sets-and-scene-tables.md)
- **Date:** 2026-10-16

## Context

A schema 2 GameSystem draft ([ADR 0028](0028-studio-gamesystem-drafts.md)) can only get its narrative sections (`trackers`, `tags`, `factSlots`, `sceneTypes`, `flows`, [ADR 0017](0017-narrativeflow-model.md) as amended by [ADR 0018](0018-narrativeflow-control-flow-and-cast.md)) from a copied or restored release. The `studio-flow-editor` feature lets a `GAME_MANAGER` author them. Unlike oracles ([ADR 0029](0029-studio-oracle-editor.md)), Scene Type and flow steps are validated against a catalog built from every other section, and flows add flow-wide coherence: oracle-selection entries need a Scene Type, the trackers a flow reaches must be in `flow.trackers`, the oracles of its Scene Types must be in `flow.oracles`, and its text placeholders must resolve. An author builds a flow item by item, so these flow-wide checks cannot hold after every save.

## Decision

- **Per-item commands:** save (create, or replace by its original key) and delete a tracker, tag, fact slot, Scene Type (with its three step lists) or flow (with its phases, hooks and steps). Each is all-or-nothing on the `GameSystem` aggregate and carries the `expectedVersion` the client read; a mismatch is a conflict (409). A replaced item keeps its position and a new one is appended. Saving a flow with `default: true` clears the flag on the others.
- **Item rules and flow coherence:** the publisher's schema 2 validation is split, not duplicated. The **item rules** are every check of an item and its references (shapes, caps, keys, catalog lookups, release-scope placeholders); **flow coherence** is `FlowReferences` and the per-flow placeholder pass. Publishing runs the item rules then coherence; every editor command runs the item rules on the whole draft. An item that breaks them, or that breaks the items referring to it, is refused (422) with the path. Coherence issues are listed in the Validation tab and block only publishing.
- **Rename and delete:** renaming or deleting a tracker, tag, fact slot or Scene Type whose key the draft references is refused (422) with the reference paths. A defensive draft reference scan, generalised from the oracle one, finds them; it never throws on a malformed section. Flow keys are not referenced inside a draft and rename freely; phase and step keys are internal to their flow or Scene Type.
- **Schema version 1:** a v1 draft has no narrative sections; the read model says so and every narrative command is refused (422).
- **API:** one generic resource per section under `/api/studio/game-systems/{key}/narrative`: `GET`, `POST /{section}`, `PUT` and `DELETE /{section}/{item}`, with `section` in `trackers`, `tags`, `fact-slots`, `scene-types`, `flows`. The item is a free-form object validated by the publisher, like an oracle definition. Each command returns the refreshed read model: the sections as stored, each item's `usedBy`, and a catalog for pickers.
- **Outline editor:** step lists are ordered, collapsible step cards with a `next` picker; flows are an outline of phases with their hooks. No node graph ([ADR 0004](0004-react-vite-spa-ui-toolkit.md)). Client checks only guide (a backward `next` is flagged); the server is the source of the rules.
- **Oracle extensions edited:** table entry `sceneType` and `effects` and likelihood `chaos.tracker` get editors in this feature. The oracle commands validate them by the item rules instead of preserving them. This amends the "preserved extensions" decision of [ADR 0029](0029-studio-oracle-editor.md).

## Consequences

- A draft built in the editor always passes the item rules; publishing can only trip on flow coherence, which the Validation tab already shows.
- Changing a tracker or check that a step uses is refused with that step's path, so an author fixes the users first.
- One validator serves the publisher and the editor, at the cost of revalidating the whole draft on each save (a few hundred items at most).
- The generic section API keeps the generated client small; the per-section shapes live in the publisher contract, not in OpenAPI.
- Play is untouched: it still reads only published releases ([ADR 0010](0010-versioned-gamesystem-releases.md)).
