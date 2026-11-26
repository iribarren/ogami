# Library Packs

A **library pack** holds items that more than one GameSystem could use, such as a table of names, a Tracker for a doom clock or a Scene Type for a rest. You write them once in the pack, and then copy them into the GameSystems that want them.

## What a pack holds

A pack holds five kinds of item, listed here in the order the Library shows them:

- Oracles (oracle tables and Likelihood oracles)
- Trackers
- Tags
- Fact slots
- Scene Types

It holds no Flows, Sheet, Checks, Gear, Credits or Theme. Since everything in a pack refers only to other things in the same pack, a pack Scene Type cannot name a Check or a sheet field, and a Scene Type that needs a Check therefore belongs to one GameSystem rather than to a pack.

## Making a pack

On the Studio home, the **New pack** button asks for a key, a name and an optional description. As with a GameSystem, the key names the pack and never changes, and because pack keys and GameSystem keys share one set of names, you cannot use one key for both. The new pack opens for editing with the short rail the tour described (Overview, Scene Types, Trackers & tags, Oracles and Validation), and you edit its items with the same editors a GameSystem uses.

A pack is never published and has no versions, so Play never sees it. Anyone who authors in Studio can read every pack and import from it, but only its author can edit or delete it. Everyone else gets a read-only page in the Library, which lists the pack's items by section, with what each item uses.

## Importing into a draft

Open the **Library** section of a GameSystem and choose **Import from library**.

1. Choose a pack.
2. Tick the items you want. An item that depends on others says "Also brings" and names them.
3. **Preview** shows what will be imported, what is **Kept from your draft**, and any conflict.
4. **Import** copies the items.

The import is all or nothing. When the draft already has an item with a key you asked for, that is a conflict, and you either choose another item or detach or rename the one in the draft first. When something an item brings along is already in your draft under the same key, though, your version is kept and the pack's is not copied, which is how a GameSystem overrides a library item.

The copy is an ordinary item of your draft, and you can edit it like any other. Importing needs a draft on schema version 2, so a schema version 1 draft shows a notice instead.

## Following the pack

The Library section lists each import with its pack and a status:

| Status  | Meaning                                      |
| ------- | -------------------------------------------- |
| Current | The pack's item is the one you copied        |
| Changed | The pack's author has changed the item since |
| Removed | The pack or the item is gone                 |

An import you have edited since is also marked **Edited locally**, and each import offers two actions:

- **Update** copies the pack's current item again. If you edited the copy, Studio asks first, because the update replaces your edits. A removed item has nothing to update from.
- **Detach** keeps the item in your draft and stops it following the pack.

Nothing changes in a draft until you update. Because packs have no versions, an import remembers the content the item had when you copied it, and so a pack's unfinished edits are visible to importers as soon as the author saves them. Update when you want the new text, not before.

## Library items in a release

Publishing copies the imported items into the release, so a release never depends on a pack. The release history records which pack each library item came from, as a "Library:" line under the version, and campaigns see none of this.

## Neither flow imports

Hard Country and Dogwatch were written without packs, so neither draft has imports. A pack would suit what the two share, though, such as a table of names for NPCs or a generic Rest Scene Type.

## Try it

Look at the oracle tables of your own GameSystem and find one you could use in a second game, such as a table of names. Write down what a pack for it would hold, and what it would need to bring along.
