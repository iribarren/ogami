# 0033. Studio library packs

- **Status:** Accepted
- **Date:** 2026-10-18

## Context

[ADR 0017](0017-narrativeflow-model.md) §12 puts generic content (oracle tables, likelihood oracles, Scene Types, trackers, fact slots) in a Studio-only Library: any game manager authors it, a GameSystem draft imports it, publishing copies it into the release, a GameSystem overrides an item by key, and every referenced key must resolve at publish. It left open how the library is organised, what an import keeps, whether flows can be generic and whether a release records provenance.

The draft's item rules already resolve every reference in the document ([ADR 0030](0030-studio-flow-editor.md)): a Scene Type that names `npc-name` is refused unless the draft has an `npc-name` oracle. Release keys are plain (`[a-z0-9-]`), and the contract rejects unknown properties.

## Decision

- **Library packs.** The library is made of packs. A pack is a document with its own key space, so two managers can each have an `npc-name` table. It holds oracle tables, likelihood oracles, trackers, tags, fact slots and Scene Types, and no flows, sheet, checks or theme. Its references resolve inside the pack, so a pack Scene Type cannot name a check or a sheet field.
- **Who:** any `GAME_MANAGER` creates packs. Every `GAME_MANAGER` can browse every pack and import from it. Only the author edits or deletes a pack. Anyone else who tries to edit it gets "not found", as with drafts ([ADR 0028](0028-studio-gamesystem-drafts.md)).
- **Aggregate:** a pack reuses the GameSystem draft aggregate and its release-shaped document as a draft of kind *library pack*. The oracle and narrative editors (trackers, tags, fact slots, Scene Types) and their item rules work on it unchanged. A pack refuses flows, sheet, checks, theme, credits, publishing, releases and restore (409). It starts blank and is never published, so Play never sees it. Its key shares the GameSystem key namespace.
- **Import copies, pinned.** Importing selected pack items copies each item, with every pack item it references (closure), into a schema version 2 draft:
  - a referenced key the draft already has is kept: that is the **override by key**;
  - a requested key the draft already has is a conflict;
  - a reference that resolves neither in the draft nor in the pack fails the item rules with its path.

  The import is all-or-nothing. The draft records a **library import** for every copied item: section, key, pack and the item's content hash. A pack imports nothing.
- **Changed in the library.** An import is *current*, *changed* (the pack item's hash differs from the recorded one) or *removed* (the pack or the item is gone). It is also *edited locally* when the draft's item no longer matches the recorded hash. Drafts with changed or removed imports are flagged in Studio.
  - **Update from library** re-copies the item and any newly referenced pack items, with the same override rule, after the editor confirms over local edits.
  - **Detach** forgets the import and keeps the item.
  - Deleting or renaming an imported item drops its import.
- **No generic flows.** Flows stay per GameSystem: they tie phases to a system's checks, sheet and Scene selection. Copying a preset release (e.g. Mythic-style) is how a manager starts from a generic flow. This closes the vision's open question until real use asks again.
- **Provenance stays in Studio.** The release contract is unchanged. At publish, the draft's library imports (section, key, pack, hash, edited locally) are stored on the release row outside the content hash, like release notes, and shown in Studio's release history. Play is untouched and every hash stays the same.

## Consequences

- Generic content reuses the existing editors and the publisher's item rules, so a pack is valid by the same rules as a release fragment.
- Importing changes nothing downstream. Imported items are ordinary draft items, so publishing, validation and Play need no change.
- A library change reaches a draft only through an explicit update, a release only through a publish, and a campaign only through an upgrade (pinning, [ADR 0014](0014-campaigns-pinned-to-their-release.md)).
- A pack key cannot be reused as a GameSystem key, and the other way round.
- A pack Scene Type cannot carry check steps or sheet effects. Content like that belongs to one GameSystem.
- Packs have no versions. An import pins a content hash, not a pack release, so a pack's unfinished edits are visible to importers as soon as they are saved.
