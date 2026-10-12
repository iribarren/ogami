# 0021. Release credits for fan presets

- **Status:** Accepted
- **Date:** 2026-10-13

## Context

The [vision](../vision.md) left one open question for feature `preset-guided-sample`: does a release need a credits or license field for fan presets? [ADR 0018](0018-narrativeflow-control-flow-and-cast.md) decision 29 says fan content must carry the publisher's disclaimer in a noticeable place, and a release has nowhere to put it: `gameSystem` holds only `key`, `name` and `description`.

The first fan preset is a Cyberpunk RED heist one-shot. The R. Talsorian Games Homebrew Content Policy (updated 2021-03-22) allows free fan content that reproduces no RTG text, art or trade dress, as long as it shows a disclaimer in an easily noticeable place: the content is unofficial, provided under the policy, and not approved or endorsed by RTG. The policy names a license-like document and publishes it at a URL.

## Decision

A release may carry optional credits: `gameSystem.credits`.

- **Release (schema version 2, additive):** `credits` is null or an object `{notice, license, url}`. `notice` is required, trimmed 1 to 2000 characters. `license` is a string of at most 200 characters or null. `url` is null or an absolute `https://` URL of at most 500 characters. Unknown fields fail closed. Schema version 1 is not extended.
- **Why structured:** the notice is the text a player must see. The license name and its link stay separate and machine-readable, so Studio can validate them and a future credits page can list licenses without parsing prose.
- **Play:** the anti-corruption layer carries the credits into the snapshot as a small value object. The game system list and the pinned release of a campaign expose `credits` (nullable). The campaign creation form shows the notice under the chosen game system, and the campaign page shows it in an "About this game" area. A release without credits shows nothing.
- **Fan presets:** a fan preset puts the publisher's disclaimer in `notice`, the policy name in `license` and the policy page in `url`.

## Consequences

- Existing releases and presets have no credits and behave as before. Releases stored before this change read as no credits.
- The field is optional: Ogami does not check that a fan preset carries a disclaimer. The author and the reviewer of a preset own that rule.
- Studio authoring can edit the fields later without a contract change.
- A dedicated credits page, or credits on other release parts (a Scene Type or table borrowed from another source), would be new additive fields.
