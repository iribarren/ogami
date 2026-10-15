# 0028. Studio GameSystem drafts

- **Status:** Accepted
- **Date:** 2026-10-15

## Context

Every release comes from a hand-written JSON file published by `app:gamesystem:publish`. The glossary calls a GameSystem a "mutable draft" and [ADR 0010](0010-versioned-gamesystem-releases.md) says editing changes only the draft, but no draft exists in code. The Studio editors (features 15 to 18b) all edit one, so `studio-gamesystem-drafts` lays the model they build on.

## Decision

- **Owner-only:** a GameSystem draft belongs to the `GAME_MANAGER` who created it. Anyone else gets "not found" (never "forbidden"), so a key's owner is not revealed. Its releases stay public to Play.
- **Starting points:** a blank draft (schema version 2, empty sections) or a copy of a published release (any key, latest or a chosen version) under a new key and name. No JSON import or export.
- **Presets stay file-managed:** a draft key must not be used by another GameSystem or by any existing release. The console publisher refuses a Studio-owned key (later slice).
- **Aggregate:** `GameSystem` keeps id, key, owner user id (a string, no Identity import), the draft document (a release document), timestamps and an optimistic lock version; a concurrent save is refused. Table `studio_gamesystem`, unique key. The key is fixed at creation and follows the contract's `gameSystem.key` rule.
- **Draft content:** stored as given, so a draft may be invalid while work is in progress. Metadata edits (name, description, credits, theme) are checked in the domain against the contract's limits and are all-or-nothing. A schema version 1 draft has neither credits nor theme.
- **Validation:** the draft document goes through `ReleaseContent::fromArray`, the same validator the publisher uses. It stays fail-fast: the first error with its path, plus the authoring warnings when valid.
- **Unpublished changes:** true when the draft has no release, is invalid, or its content hash differs from the latest release's.
- **Publish, history, restore:** publishing goes through the existing release publisher, never a second writer. Optional release notes live on the release row, outside the hash. A release can be restored into the draft; the key and owner stay.
- **Discard:** only a never-published draft can be deleted, since campaigns may pin its releases.
- **API:** `/api/studio/game-systems`, guarded by `ROLE_GAME_MANAGER`.

## Consequences

- Play is untouched: it still reads only releases through its anti-corruption layer.
- Later editors change the draft document through the same aggregate and need no new storage.
- A draft key is permanent, and a preset cannot be edited in Studio; a manager copies it under a new key.
- Two managers cannot hold the same key, even when the other is a draft.
