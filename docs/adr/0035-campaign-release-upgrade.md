# 0035. Campaign release upgrade

- **Status:** Accepted, amended by [0040](0040-structured-character-creation.md) , [0041](0041-dice-pools-pushes-and-conditions.md), [0042](0042-flow-choices-check-sets-and-scene-tables.md) and [0045](0045-gear-inventories-currency-and-shops.md) (`list` and `inventory` conversions)
- **Date:** 2026-10-18

## Context

A campaign pins one GameSystem release and stays on it ([ADR 0014](0014-campaigns-pinned-to-their-release.md), [ADR 0010](0010-versioned-gamesystem-releases.md)). Both left the upgrade path open. Without it, fixes and new content a game manager publishes never reach running campaigns. Stored Character values are validated against the pinned sheet on every write ([ADR 0022](0022-character-sheets-in-schema-version-2.md)), so simply switching the pin would drop or reject values at the next edit. The upgrade must not lose data silently.

## Decision

- **Latest only.** A player upgrades a campaign to the latest release of its GameSystem, and only when that version is higher than the pinned one. There is no downgrade and no choice of an intermediate release. The GameSystem key never changes. Schema version 1 to 2 is allowed, because Play reads both.
- **Preview, then confirm.** Play first computes an *upgrade plan*, a pure domain value built from the campaign, the pinned snapshot and the target snapshot, in a deterministic order. Nothing changes until the player confirms.
- **Fingerprint confirmation.** The plan has a fingerprint: the SHA-256 of its canonical JSON, including both versions. Confirming sends the target version and the fingerprint. The server recomputes the plan and refuses when the fingerprint differs (the campaign was edited, or a newer release was published), so the confirmed change is exactly the previewed one. A refusal changes nothing.
- **Character values fit the new sheet.** A stored value that is still valid is kept. A new field gets its initial value. A removed field, or one that is now `derived`, loses its value. A `number` or `track` value outside the new bounds is clamped. A `list` over `maxItems` keeps its first items. A value whose type changed, or that is still invalid after fitting, is reset to the new initial value. Derived values are never stored and recompute on read. Fields match by key: a rename reads as a removed field plus an added one.
- **Tags.** Tags that no longer exist in the release are dropped from Characters, NPCs and Threads. Free tags stay.
- **Tracker values.** A removed tracker loses its value, a value outside the new bounds is clamped, a new tracker reads as its initial value (nothing is stored).
- **Campaign facts.** A fact whose slot is gone becomes a free fact. It keeps its text and links, and its label (the old slot's name when it had none).
- **Guidance always restarts.** The FlowRun starts again at the first phase of the Flow. The Flow is the same key when the new release has it, else the release's default Flow, else its first Flow. A free-play campaign stays free, and a release without Flows makes the campaign free play. A paused FlowRun restarts paused. A guided Scene open at upgrade time goes on as a free Scene, by the existing abandon rule. Scenes keep their Scene Type key even if it is gone.
- **No journal entry.** The upgrade writes nothing to the journal.
- **All or nothing.** The upgrade applies completely or not at all, and then re-pins the campaign's key, version, name and Flow. It is a Campaign command, guarded by the same concurrency check as every write.

## Consequences

- Players get new releases on their own terms and see every loss before it happens.
- Some data is lost on purpose (removed fields, clamped values); the plan lists it, and the player decides.
- The pinned fields of `Campaign` stop being read-only. Persistence needs no migration: the columns exist.
- Restarting guidance always is simple and predictable, at the cost of progress in the current Flow.
- The fingerprint ties the preview to the campaign state, so a stale preview fails closed with a conflict.

## Out of scope

- Choosing an intermediate or older release.
- Release notes in the preview.
- Choosing another Flow during the upgrade (`play-campaign-transfer` in the backlog).
- A journal entry recording the upgrade.
- Detecting renamed keys.
- Studio changes.
