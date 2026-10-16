# 0029. Studio oracle editor

- **Status:** Accepted
- **Date:** 2026-10-16
- **Amended by:** [ADR 0030](0030-studio-flow-editor.md) (entry `sceneType` and `effects` and `chaos.tracker` are now edited)

## Context

A GameSystem draft ([ADR 0028](0028-studio-gamesystem-drafts.md)) can only get oracles from a copied or restored release. The `studio-oracle-editor` feature lets a `GAME_MANAGER` create, edit and delete oracle tables and likelihood oracles in a draft and try them before publishing. Oracles are referenced from Scene Types, flows, steps and other oracles, and the draft API has no client concurrency token, so item editors need rules the metadata editor never did.

## Decision

- **Per-item commands:** save a table (create, or replace by its original key), delete a table, save a likelihood oracle, delete a likelihood oracle. Each is all-or-nothing on the `GameSystem` aggregate; a replaced item keeps its position and a new one is appended.
- **Expected version:** every oracle command carries the `expectedVersion` the client read; a mismatch is a conflict (409). The oracles read model exposes the version and each command returns the new one. Metadata editing is unchanged.
- **Section invariant:** after every command the `oracles` section is valid on its own for the draft's schema version: shape, field lists, dice coverage, unique keys across tables and likelihood oracles, caps, nesting depth, `randomEvent.table`, unique entry keys. The publisher runs the same code (one validator, extracted, not copied). Checks that need other sections (entry `sceneType`, `effects`, `chaos.tracker`) stay in the full draft validation. An invalid item is refused with its path (422).
- **Key collision:** a key used by another table or likelihood oracle is a conflict (409).
- **References:** a domain service reads the draft document defensively, since the rest may be invalid, and lists where an oracle key is used: Scene Type and flow `oracles`, `oracle` selection, `oracle`, `table` and `prompt` `inspiration` steps, `randomEvent.table` and nested entry `table`. A `table` step branch also references an entry key.
- **Rename and delete:** refused (422) while the key is referenced, with the reference paths; so is removing or renaming an entry key a branch uses. Unreferenced oracles change freely.
- **Schema version 1:** a v1 draft edits v1 fields only; v2-only fields are refused.
- **Preserved extensions:** entry `sceneType` and `effects` and `chaos.tracker` are edited by later features. The editor sends them back unchanged and shows them read-only.
- **Test rolls:** a Studio endpoint rolls a table or asks a likelihood oracle of the **saved** draft by key, through the Randomness application queries; nested tables resolve. A test roll never changes the draft and is recorded nowhere.

## Consequences

- Oracles never become invalid through the editor, so publish does not trip on them.
- Renaming a referenced oracle means editing its users first; the error tells where they are.
- Studio depends on Randomness only through its shared kernel; Play is untouched.
- Unsaved edits are saved before a test roll, which keeps the roll server-side and the draft the single source of truth.
