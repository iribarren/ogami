# 0022. Character sheets in schema version 2

- **Status:** Accepted, amended by [0023](0023-formula-kernel-and-derived-values.md) and [0024](0024-structured-checks-without-scripting.md)
- **Date:** 2026-10-13

## Context

Roadmap feature 10 (milestone M3) gives Play Characters built from a game system's sheet. The release contract reserves `sheet` as `{}` in schema versions 1 and 2, and [ADR 0017](0017-narrativeflow-model.md) said the M3 step kinds `character` and `check` would arrive as later schema versions. [ADR 0021](0021-release-credits-for-fan-presets.md) showed that an optional, null-safe field can extend schema version 2 without touching any existing release.

A sheet template is the same kind of change: no existing release uses it, `{}` stays valid, and the canonical form of an empty sheet does not change.

## Decision

- **Additive in schema version 2** (user decision, 2026-10-13). `sheet` gains an optional `fields` list, the `character` step kind joins the flow steps, and `pick` / `condition` `of` gain `character`. Every release valid before stays valid with the same content hash. `{}` and `{"fields": []}` both canonicalize to `{}`.
- **Schema version 3 is for what changes meaning:** sheet effects. Derived values ([ADR 0023](0023-formula-kernel-and-derived-values.md)) and checks ([ADR 0024](0024-structured-checks-without-scripting.md)) were later settled as additive in version 2.
- **Four field types**, each with its own closed set of properties (unknown ones fail closed):
  - `number`: optional `min`, `max`, `default` (integers, −1,000,000 to 1,000,000; `min ≤ default ≤ max`).
  - `text`: optional `multiline`, `maxLength` (1–2000).
  - `list`: optional `itemLabel`, `maxItems` (1–100). Items are trimmed strings. Lists cover inventory and cyberware.
  - `track`: a resource track `{current, max}`; optional fixed `max` (1–999), otherwise each Character sets its own. It starts full.
- **Fields** have a unique `key`, a `label`, an optional `hint` and an optional `group` that lays consecutive fields out together. At most 100 per sheet.
- **Characters are value objects in the Campaign aggregate**, like NPCs: a JSONB column with its own Doctrine type, no table, at most 100 per Campaign.
- **Values are validated against the pinned release's sheet** on every write (unknown key, wrong type, out of bounds fail). A missing key reads as the field default. A release without fields gives Characters a name and tags only.
- **NPC promotion** creates a Character with the NPC's name, release tags and free tags. The NPC is closed and records `promotedTo`; it cannot be reopened or promoted again. In the current Scene's cast the Character replaces the NPC.
- **Party:** a Session's party starts as the previous Session's party (empty for Session 1) and the player edits it. A Character created or promoted while a Session runs joins its party and the current Scene's cast. A `pick of: character` in a session hook adds to the party; in a scene part it adds to the cast.
- **`character` step:** completes with the id of an existing Character the player picks (created inline from the step if none exists); it fails without one. `{step:key}` reads the Character's name.

## Consequences

- Existing releases and presets keep their canonical form and hash.
- The contract's "new capability means a new schema version" rule is relaxed: additive extensions are allowed when every valid release stays valid with the same hash (`credits`, `sheet.fields`).
- Play's translator reads the sheet into the snapshot, re-checking every limit. Until the `character` step lands in Play, it fails closed on the `character` step kind and on `of: character`.
- Sheet fields cannot be changed by effects yet, and nothing computes from them; both wait for schema version 3.
- Deleting Characters, and migrating Character values when a Campaign moves to a newer release, are out of scope.
