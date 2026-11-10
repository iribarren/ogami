# What Publishing Does to Running Campaigns

Publishing never changes a campaign that is already running. This chapter is for the person who has published a GameSystem and wonders what happens to the people playing it.

## A campaign is pinned

When a player creates a campaign they choose a GameSystem, and the campaign **pins its latest release** at that moment. Play reads that release, and only that release, for the campaign's whole life: its oracles, Flow, Sheet and Checks. Releases are immutable, so what the campaign reads cannot move under it.

So:

- Editing the draft changes nothing for anyone. Players never see the draft.
- Publishing a new release changes nothing for existing campaigns. They stay on the version they pinned.
- Only a campaign created after the publish starts on the new version.

This is why a release is safe to publish: you cannot break a game in progress by publishing.

## How a campaign moves to a new release

A player decides, one campaign at a time. When the GameSystem has a newer release than the one the campaign pins, the campaign page offers **Upgrade to vN**. Studio never moves a campaign.

The upgrade dialog shows a **preview** first: nothing changes until the player confirms. It lists what the new release changes in this campaign, such as Character fields that change, are reset or are removed, Tags that go, and Trackers that change. The player then chooses **Confirm upgrade**. The confirmation carries a fingerprint of the previewed plan, and the server refuses it if anything changed since, so the player applies exactly what they saw.

An upgrade goes to the latest release, never an older or middle one, and is all or nothing. The rules it follows:

- **Character values fit the new Sheet.** A value still valid is kept. A new field starts at its initial value. A removed field, or one that is now derived, loses its value. A number or track outside the new bounds is clamped, and a list over its limit keeps its first items. A value whose type changed is reset.
- **Tags** that no longer exist are dropped from Characters, NPCs, Threads and Places. Free Tags stay.
- **Trackers**: a removed Tracker loses its value, and a value outside the new bounds is clamped.
- **Facts** whose slot is gone become free Facts, keeping their text and links.
- **Guidance restarts.** The FlowRun starts again at the first phase of the Flow. A scene open at that moment goes on as a free scene.

## What this asks of an author

Because keys carry across an upgrade, **a key is the identity of an item**. Renaming a key looks to the upgrade like removing one item and adding another, and the player's value is lost.

Before publishing a change a campaign will meet:

- Keep keys that have to survive. Rename a display name freely, not a key.
- Treat removing a Sheet field or a Tracker as a loss: the player loses the value and sees it in the preview.
- Expect a restart of the Flow. If your update changes the middle of The Long Carry, a campaign upgraded in the Road starts again at Session Zero. Say so in the release notes, and tell players it is a good time to upgrade between stories.
- Write release notes a player could read. The notes are in Studio's history, but not in the upgrade preview.

## What publishing leaves alone

- Campaigns on older versions keep playing them, and Play keeps supporting the schema versions of releases that campaigns still pin.
- Releases are never deleted or changed. A GameSystem with releases cannot be discarded, because a campaign may pin one.
- Restoring an old release into the draft changes only the draft.

## Try it

Write down three changes you might make to your GameSystem in its next release. For each, say what a campaign pinned to the previous version would lose or keep if its player upgraded.
