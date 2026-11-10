# Releases, Restore and Themes

A **release** is a published version of a GameSystem. This chapter covers publishing one, reading the history, restoring an old version, and the Theme and Credits that go with a release.

## Draft and release

Everything you edit in Studio is the **draft**. Players never see it. **Publish** turns the saved draft into a numbered release that campaigns can play. A release is immutable: once published, version 3 stays exactly as it is. To change it, edit the draft and publish version 4.

## Publishing

The **Publish** button is in the header of the GameSystem page. Its dialog shows:

- the version it will create, such as v2 → v3,
- the result of Validation, with any warnings,
- an optional field for **release notes**, up to 2,000 characters.

Notes are for you and your co-authors: write what changed. They are kept with the release, listed in the history, and are not part of its content. **Publish version N** creates it, and the page says "Published version N." Only the saved draft is published, so save your Overview edits first.

A publish can be refused. If nothing changed since the latest release, the dialog says so and asks you to edit first. If another release was published meanwhile, it asks you to reload and try again.

## The Releases section

**Releases** in the Ship group lists every version, newest first, with its schema version, date, a short content hash, its notes and, for library items, the packs they came from. A GameSystem that was never published says "Never published". The rail shows the latest version as a pill.

**View** opens one release, read-only:

- **Changes since the previous version**: for each section that changed, the items added, removed and changed (with which properties differ), and whether the order changed. Sections with no change are only counted. The first release has nothing to compare, and two releases of different schema versions are not compared.
- **The outline**: each section with its items by name and key.

Moving an item is not changing it, and a change to the name or description of the GameSystem is not shown in Changes.

## Restore

**Restore into the draft** on a release replaces the content of the draft with that version. The key and owner stay. A confirmation says what it costs: edits to the Overview that you have not saved are lost, and so is any draft content that was never published. Restore changes only the draft. No release is created or removed, and no campaign is touched.

Use it to go back after a bad run of edits, or to start version 5 from version 3. Publish a draft you want to keep first.

## Themes

The **Overview** has a **Theme** field. A Theme is one of five curated looks that Play applies to the whole screen while a campaign on that release is open:

| Theme     | Mode  |
| --------- | ----- |
| parchment | light |
| starship  | light |
| neon      | dark  |
| noir      | dark  |
| eldritch  | dark  |

The choice is made for the player: inside a themed campaign the theme's mode wins over the player's Light, Dark or System choice. "None" uses the default app theme. New campaigns on the GameSystem start with the theme of their release. Hard Country uses parchment and Dogwatch uses starship.

The list is fixed, and the palettes live in the app, not in the release. A theme is only on a schema version 2 draft and is not offered to library packs.

## Credits

The Overview also holds the Credits (notice, license, link and badge), covered in the chapter on licensing. They are part of the release, so each version carries its own.

## Try it

Publish your GameSystem with notes that name the last three changes. Change a table, publish again, open View on the new version and check that the change appears under Changes since the previous version.
