# What Publishing Does to Running Campaigns

Publishing never changes a campaign that is already running. Once you have published a GameSystem, other people may be in the middle of playing it, and this chapter is about what happens to them and to their campaigns when you publish again.

## A campaign is pinned

Think of a group that bought the first print run of a rulebook. When a revised print run comes out, the copy on their shelf does not change, and they go on playing from it until they choose to buy the new one. A campaign works the same way. When a player creates a campaign they choose a GameSystem, and the campaign **pins its latest release** at that moment. For the whole life of the campaign Play reads that release and only that release, which means its oracles, Flow, Sheet and Checks, and because releases are immutable, what the campaign reads cannot move under it.

So editing the draft changes nothing for anyone, since players never see the draft. Publishing a new release changes nothing for existing campaigns either, because they stay on the version they pinned, and only a campaign created after the publish starts on the new version. That is why a release is safe to publish, since you cannot break a game in progress by publishing one.

## How a campaign moves to a new release

The player decides, one campaign at a time. When the GameSystem has a newer release than the one the campaign pins, the campaign page offers **Upgrade to vN**, and Studio never moves a campaign on its own. A campaign transfer also reaches the new release, since it starts a new campaign from an existing one on the same GameSystem, pinned to the latest release, and it is refused until the existing campaign plays that release, so an older campaign is upgraded first.

The upgrade dialog shows a **preview** first, and nothing changes until the player confirms it. The preview lists what the new release changes in this campaign, such as Character fields that change, are reset or are removed, Tags that go, and Trackers that change. The player then chooses **Confirm upgrade**. The confirmation carries a fingerprint of the previewed plan, and the server refuses it if anything has changed since, so the player applies exactly what they saw.

An upgrade always goes to the latest release, never to an older or a middle one, and it is all or nothing. It follows these rules:

- **Character values fit the new Sheet.** A value that is still valid is kept, and a new field starts at its initial value. A removed field, or one that is now derived, loses its value. A number or track outside the new bounds is clamped, a list over its limit keeps its first items, and a conditions field loses the marks of the options it no longer declares. When a list becomes an inventory, or an inventory becomes a list, the value is converted and keeps what it can, as does an inventory stack whose Gear the new release no longer accepts. Any other value that no longer fits, such as one whose type changed, is reset to its new initial value.
- **Tags** that no longer exist are dropped from Characters, NPCs, Threads and Places. Free Tags stay.
- **Trackers**: a removed Tracker loses its value, and a value outside the new bounds is clamped.
- **Facts** whose slot is gone, or whose slot now has another type, become free Facts, keeping their text and links.
- **Guidance restarts.** The FlowRun starts again at the first Phase of the Flow, and a Scene that is open at that moment goes on as a free Scene.

## What this asks of an author

Because keys carry across an upgrade, **a key is the identity of an item**. If you rename a key, the upgrade sees one item removed and another added, and the player's value is lost.

Before you publish a change that a campaign will meet:

- Keep the keys that have to survive. You can rename a display name freely, but not a key.
- Treat removing a Sheet field or a Tracker as a loss, because the player loses the value and sees that in the preview.
- Expect the Flow to restart. If your update changes the middle of The Long Carry, a campaign upgraded during **The Road** starts again at **Session Zero**. Note it in the release notes, and tell players that between stories is a good time to upgrade.
- Write release notes you can read later. Players never see them: they are only in Studio's history, not in Play or the upgrade preview.

## What publishing leaves alone

Campaigns on older versions keep playing them, and Play keeps supporting the schema versions of the releases that campaigns still pin. Releases are never deleted or changed, and a GameSystem with releases cannot be discarded, because a campaign may pin one of them. Restoring an old release into the draft changes only the draft.

## Try it

Write down three changes you might make to your GameSystem in its next release. For each one, say what a campaign pinned to the previous version would lose or keep if its player upgraded.
