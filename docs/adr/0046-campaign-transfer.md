# 0046. Campaign transfer

- **Status:** Accepted
- **Date:** 2026-10-29

## Context

A campaign's Flow is fixed ([ADR 0017](0017-narrativeflow-model.md) §7): only guidance pause and resume. A one-shot whose story should go on as a campaign (the `night-job` heist that grows into "Making a Name") had to be retyped by hand. [ADR 0035](0035-campaign-release-upgrade.md) left choosing another Flow out of the release upgrade. The data worth keeping is the story state: NPCs, Threads, Campaign facts and some Characters. The rest (sessions, scenes, the journal, tracker values) belongs to the arc that ended.

## Decision

- **A transfer starts a new campaign.** The player continues a campaign in a new one on the same GameSystem, with a name, a Flow of the release or free play (`flowKey` null; the release default is not applied by the server), and the Characters to carry. The new campaign is built as `Campaign::create` builds one (pinned to the latest release, a fresh FlowRun when it has a Flow, no sessions) and then receives the carried lists.
- **The source stays as it is.** It is read, never saved: no version bump, still listed, and its sessions, scenes and journal stay with it. No `transferredFrom` is stored, so there is no origin link and no migration.
- **Trackers reset.** Every Tracker starts at its release `initial` value, as in any new campaign. Tracker values are per-arc state (alarm, objective, gig).
- **Upgrade first.** The source must already play the latest release; otherwise the transfer is refused (409) until the player runs the release upgrade (ADR 0035). The new campaign pins the same, latest release, so nothing is refitted: Characters, Tags, slot facts and NPC Tags stay valid as they are.
- **Characters are a subset.** The player picks the Characters to carry. A carried Character keeps its `promotedFrom`. An NPC promoted to a Character that stays behind keeps its data (still closed) but loses `promotedTo`, so the two sides of a promotion never name a missing Character.
- **NPCs, Threads and facts: all of them.** Open and closed, with weights, agendas, Tags and statuses, and every Campaign fact with its slot, links and subject. Fact links only name NPCs, Threads and facts, which all come along. A target Flow's `fillFact` on an already filled slot overwrites it, as in any campaign.
- **Ids are kept.** NPC, Thread, Fact and Character ids are unique per campaign, and keeping them keeps links and promotions valid with no remapping. The new campaign gets a new `CampaignId`.
- **Any Flow or free play.** The target is any Flow of the release, the source's own Flow included, or none.
- **API.** `POST /api/campaigns/{id}/transfer` with `{name, flowKey, characterIds}` answers 201 with `Location` and the body of `POST /api/campaigns`. A source that is missing or another player's is 404; an outdated source or a generated id already taken is 409; a blank name, an unknown Flow, an unknown or repeated Character id or a malformed body is 422.

## Consequences

- A one-shot can grow into a campaign without retyping its world; nothing about the source is lost or locked.
- Journal entries do not follow the story: the new campaign starts with an empty journal. The player still reads the old one in the source.
- Keeping the ids means the same NPC exists in two campaigns, independent from then on. There is no sync.
- Tracker progress is lost on purpose; a player who wants it sets the values by hand.
- Players with an outdated source take one extra step, the release upgrade, which already shows what it changes.

## Out of scope

- Moving between GameSystems (Characters, Tags and slots would need a mapping).
- Refitting a source on an older release during the transfer.
- A stored link from the new campaign back to its source.
- Copying sessions, scenes or journal entries.
