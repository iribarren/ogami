# Releases, Restore and Themes

A **release** is a published version of a GameSystem, and it works much like a print run of a book, whose copies stay as they came off the press while a correction has to wait for the next print run. This chapter shows how to publish a release, read the history and restore an old version, and then turns to the Theme and the Credits that go with a release.

## Draft and release

Everything you edit in Studio is the **draft**, which players never see. When you press **Publish**, the saved draft becomes a numbered release that campaigns can play. A release is immutable, so once version 3 is published it stays exactly as it is, and if you want to change it, you edit the draft and publish version 4.

## Publishing

The **Publish** button is in the header of the GameSystem page. Its dialog shows the version it will create, such as v2 → v3, together with the result of Validation and any warnings it found, and it has an optional field for **release notes** of up to 2,000 characters.

The notes are for you, so write in them what changed. They are kept with the release and listed in the history, although they are not part of its content. **Publish version N** creates the release, and the page then says "Published version N." Since only the saved draft is published, save your Overview edits before you publish.

A publish can be refused. If nothing has changed since the latest release, the dialog says so and asks you to edit the draft first, and if another release was published in the meantime, it asks you to reload and try again.

## The Releases section

**Releases**, in the Ship group, lists every version with the newest first. Each one shows its schema version, its date, a short content hash and its notes, and for library items it also shows the packs they came from. A GameSystem that was never published says "Never published", and once it has a release, the rail shows the latest version as a pill.

**View** opens one release, read-only, in two parts:

- **Changes since version N** lists, for each section that changed, the items that were added, removed and changed, with the properties that differ for a changed one, and says whether the order changed. Sections with no change are only counted. The first release has nothing to compare with, and two releases of different schema versions are not compared.
- Below the changes, the release is laid out section by section, with each item by its name and key.

Moving an item does not count as changing it, and a change to the name or the description of the GameSystem is not shown in Changes.

## Restore

**Restore into the draft** on a release replaces the content of the draft with that version, while the key and the owner stay as they are. A confirmation tells you what this costs before you go ahead, because edits to the Overview that you have not saved are lost, and so is any draft content that was never published. Restore changes only the draft, which means that no release is created or removed and no campaign is touched.

You can use it to go back after a bad run of edits, or to start version 5 from version 3. If the draft holds work you want to keep, publish it first.

## Themes

When you open a printed game, its cover and its paper set a mood before you read a word of the rules, and a Theme does the same for a GameSystem on screen. The **Overview** has a **Theme** field, and a Theme is one of five curated looks that Play applies to the whole screen while a campaign on that release is open:

| Theme     | Mode  |
| --------- | ----- |
| parchment | light |
| starship  | light |
| neon      | dark  |
| noir      | dark  |
| eldritch  | dark  |

The choice is made for the player, since inside a themed campaign the theme's mode wins over the player's Light, Dark or System choice. "None" uses the default app theme. New campaigns on the GameSystem start with the theme of their release, which is parchment for Hard Country and starship for Dogwatch.

The list is fixed, and the palettes live in the app rather than in the release. A theme can only be set on a schema version 2 draft, and it is not offered to library packs.

## Credits

The Overview also holds the Credits, which are the notice, the license, the link and the badge that the chapter on licensing covers. They are part of the release, so each version carries its own.

## Try it

Publish your GameSystem with notes that name the last three changes. Then change a table, publish again, open View on the new version and check that the change appears under Changes since version N.
