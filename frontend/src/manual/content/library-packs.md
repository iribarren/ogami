# Library Packs

A **library pack** holds items that more than one GameSystem could use: a table of names, a tracker for a doom clock, a Scene Type for a rest. You write them once and copy them into the GameSystems that want them.

## What a pack holds

A pack holds five kinds of item, in the order the Library shows them:

- Oracles (oracle tables and Likelihood oracles),
- Trackers,
- Tags,
- Fact slots,
- Scene Types.

It does not hold Flows, a Sheet, Checks, Gear, Credits or a Theme. Everything in a pack refers only to other things in that pack, so a pack Scene Type cannot name a Check or a sheet field. A Scene Type that needs a Check belongs to one GameSystem, not to a pack.

## Making a pack

On the Studio home, **New pack** asks for a key, a name and an optional description. The key names the pack and never changes. The new pack opens for editing with the short rail described in the tour: Overview, Scene Types, Trackers & tags, Oracles and Validation. You edit its items with the same editors as a GameSystem.

A pack is never published and has no versions, so Play never sees it. Anyone who authors in Studio can read every pack and import from it. Only the author can edit or delete a pack; for everyone else the Library shows a read-only page listing its items by section, with what each item uses.

A pack key and a GameSystem key share one set of names, so you cannot use one for both.

## Importing into a draft

Open the **Library** section of a GameSystem and choose **Import from library**.

1. Choose a pack.
2. Tick the items you want. An item that depends on others says "Also brings" and names them.
3. **Preview** shows what will be imported, what is **kept from your draft**, and any conflict.
4. **Import** copies the items.

The import is all or nothing. If the draft already has an item with the key you asked for, that is a conflict: choose another item, or detach or rename the one in the draft first. If an item brings something your draft already has under the same key, your version is kept and the pack's is not copied. That is how a GameSystem overrides a library item.

Importing needs a draft on schema version 2. A schema version 1 draft shows a notice instead.

The copy is an ordinary item of your draft. You can edit it like any other.

## Following the pack

The Library section lists each import with its pack and a status:

| Status  | Meaning                                      |
| ------- | -------------------------------------------- |
| Current | The pack's item is the one you copied        |
| Changed | The pack's author has changed the item since |
| Removed | The pack or the item is gone                 |

An import you have edited since is also marked **Edited locally**. Two actions are offered for each:

- **Update** copies the pack's current item again. If you edited the copy, Studio asks first, because the update replaces your edits. A removed item has nothing to update from.
- **Detach** keeps the item in your draft and stops it following the pack.

Nothing changes in a draft until you update. Packs have no versions: an import remembers the content of the item when you copied it, so a pack's unfinished edits are visible to importers as soon as the author saves them. Update when you want the new text, not before.

## Library items in a release

Publishing copies the imported items into the release, so a release never depends on a pack. The release history records which pack each library item came from, as a "Library:" line under the version. Campaigns see none of this.

## Neither flow imports

Hard Country and Dogwatch were written without packs, so neither draft has imports. A pack would suit what both share: a table of names for NPCs, say, or a generic Rest Scene Type.

## Try it

Look at the oracle tables of your own GameSystem. Find one you could use in a second game, such as a table of names. Write down what a pack for it would hold, and what it would need to bring along.
