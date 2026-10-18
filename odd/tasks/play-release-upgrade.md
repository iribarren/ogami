# Feature: play-release-upgrade

- **Locator:** `odd/tasks/play-release-upgrade.md` · Engram topic `odd/play-release-upgrade/tasks`
- **Issue:** #207 · **Branch:** `feat/play-release-upgrade-2-api` · **Status:** slice 1 merged (PR #208); slice 2 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `admin-users` (PRs #198–#205)

## Objective

Roadmap feature 20 (milestone M5). A player upgrades a campaign to the latest release of its GameSystem. Play first shows a preview of what the upgrade changes (Character values, Tags, tracker values, Campaign facts, guidance); nothing changes until the player confirms, and the confirmed change is exactly the previewed one ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0014](../../docs/adr/0014-campaigns-pinned-to-their-release.md), new ADR 0035).

Out of scope:

- choosing an intermediate or older release (latest only, no downgrade);
- release notes in the preview (they sit outside the Published Language; backlog if wanted);
- choosing another Flow during the upgrade (`play-campaign-transfer` in the backlog);
- a journal entry recording the upgrade;
- detecting renamed keys (a rename reads as a removed field plus a new one, as Studio already warns);
- Studio changes.

## Problem and why

Publishing a release never changes existing campaigns (ADR 0014), so a campaign stays on its pinned release forever. ADR 0010 and ADR 0014 leave the upgrade path to this feature. Without it, fixes and new content a game manager publishes never reach running campaigns. The risk is silent data loss: stored Character values are validated against the pinned sheet on every write (ADR 0022), so a changed sheet would otherwise drop or reject values at the next edit.

## Model summary (today)

- `Campaign` pins `gameSystemKey`, `releaseVersion`, `gameSystemName` and `flowKey` as `private readonly` fields (`backend/src/Play/Domain/Campaign/Campaign.php:135-141`); `assertPinned()` guards every release-taking command. Columns `game_system_key`, `release_version`, `game_system_name`, `flow_key` in `play_campaign`; optimistic lock `version` → `CampaignModifiedConcurrently` → 409.
- Play reads releases only through `PublishedGameSystemReleases` (`get(key, ?version)`, `latest()`), translated to `GameSystemSnapshot` (schema versions 1 and 2).
- Release-bound state in a campaign: Character `values` (by sheet field key) and `tags`; NPC and Thread `tags`; `trackerValues` (by tracker key, missing reads as initial); Fact `slot` (nullable: free facts exist, one fact per slot); `flowKey` and the `FlowRun` (phase index, stage, Scene Type and step keys, answers…); Scene `sceneType`. Journal entries keep their labels and need nothing. Derived values are never stored (ADR 0023).
- Sheet field types: `number`, `text`, `list`, `track`, `derived`. No select type, no required flag; a missing value reads as the field's initial value (`CharacterValues::initial`).
- The SPA already knows the latest version of every GameSystem (`useGameSystems`, `GET /api/play/game-systems`), so the campaign page can offer the upgrade without a new endpoint.

## Decisions

| Question | Decision |
|---|---|
| Target release | The latest release of the campaign's GameSystem key. Offered only when its version is higher than the pinned one. The GameSystem key never changes. Schema version 1 → 2 is allowed (Play reads both) |
| Character values | Per stored field: same key and type, value valid → kept. New field → its initial value (added). Field removed, or now `derived` → value removed. `number`/`track` out of the new bounds → clamped. `list` over `maxItems` → first items kept (trimmed). Type changed, or still invalid after fitting (e.g. text over `maxLength`) → reset to the new initial value. Derived values recompute on read |
| Tags | Tags no longer in the release are dropped from Characters, NPCs and Threads (free tags untouched) |
| Tracker values | Removed tracker → value removed. Out of the new bounds → clamped. New tracker → initial value (nothing stored) |
| Campaign facts | A fact whose slot is gone becomes a free fact (no slot), keeping its label (the old slot's name when it had none), text and links |
| Guidance | Always restart: the FlowRun starts again at the first phase of the Flow (user decision, 2026-10-18). Flow: same key if the new release has it, else its default Flow, else its first Flow; a free-play campaign stays free; a release without Flows makes it free play. A paused FlowRun restarts paused. A guided Scene open at upgrade time goes on as a free Scene (existing abandon rule). Scenes keep their Scene Type key even if it is gone |
| Preview | A pure domain `ReleaseUpgradePlan` computed from the campaign, the pinned snapshot and the target snapshot. Deterministic order (Characters in campaign order, fields in the new sheet's order) |
| Confirmation | The plan has a `fingerprint` (SHA-256 of its canonical JSON, including from/to versions). The apply sends the target version and the fingerprint; the server recomputes the plan and refuses with `ReleaseUpgradeChanged` (409) when it differs (campaign edited or a newer release published since). Nothing changes on refusal. Concurrent save → 409 as everywhere |
| Already on latest | Preview answers "up to date"; apply refuses with `CampaignUpToDate` (409) |
| Journal | No journal entry for the upgrade (user decision, 2026-10-18) |
| Endpoints | `GET /api/campaigns/{id}/release-upgrade` (preview) and `POST /api/campaigns/{id}/release-upgrade` (`{toVersion, fingerprint}`) |

## Design

### Backend (slices 1–2)

- `Play/Domain/Campaign/Upgrade/`: `ReleaseUpgradePlan` (from/to `PinnedRelease`, `CharacterUpgrade` list with `FieldChange` entries of kind `added | removed | clamped | trimmed | reset` and old/new values, dropped tags per Character/NPC/Thread, `TrackerChange` list, unslotted facts, flow change `{fromFlowKey, toFlowKey, restarted}`), `fingerprint()`; a pure planner that builds it.
- `Campaign::upgradeRelease(GameSystemSnapshot $pinned, GameSystemSnapshot $target, string $fingerprint, \DateTimeImmutable $at)`: `assertPinned($pinned)`, same key, higher version, recompute and compare the fingerprint, apply all or nothing (copy-on-write JSONB), re-pin. The pinned fields stop being `readonly`. No migration: the columns exist.
- Application: `PreviewReleaseUpgrade` query (owner-scoped, 404 for another player's campaign) → view or up to date; `UpgradeCampaignRelease` command.
- HTTP: `CampaignReleaseUpgradeController`; errors mapped 404/409/422; `make api` committed on its own.

### Frontend (slice 3)

- Campaign header: "Upgrade to vN" next to the release label when `useGameSystems` has a newer version.
- Upgrade dialog: loads the preview; sections Characters, Tags, Trackers, Campaign facts, Guidance, each listing old → new; "Nothing else changes"; Confirm posts `{toVersion, fingerprint}`; on 409 `ReleaseUpgradeChanged` it reloads the preview and says why; on success invalidates the campaign queries.

## Constraints

- Context boundaries: Play reads releases only through its anti-corruption layer; no Studio import.
- Malformed or unknown input fails closed (422/404), never a 500; every domain error has an API test.
- Legacy campaigns (schema version 1 releases, campaigns without characters or FlowRun) upgrade sensibly and are tested.
- Persistence round trip compares the whole aggregate after an upgrade.

## Slice plan

Forecasts include tests, docs and generated files. The hard ceiling is about 1,700 lines per slice; the reviewer refuses about 1,850. Each slice's first commit carries the previous slice's review fixes. A slice with API changes commits the regenerated `openapi.json` / `schema.d.ts` on their own.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-release-upgrade-1-upgrade-plan` | `feat(play): play-release-upgrade 1/3 upgrade plan` | T1, T2, T3 | ~1,600 |
| 2 | `feat/play-release-upgrade-2-api` | `feat(play): play-release-upgrade 2/3 preview and upgrade API` | T4, T5, T6 | ~1,600 incl. ~400 generated |
| 3 | `feat/play-release-upgrade-3-upgrade-dialog` | `feat(play): play-release-upgrade 3/3 upgrade dialog` | T7, T8 | ~1,300 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc (parent); ADR 0035 "Campaign release upgrade" and index row; ADR 0010 and 0014 point to it; glossary Release upgrade, Upgrade plan; context map Play note; contract "How Play consumes it" upgrade paragraph | delegated writer (slice 1; feature doc inline by parent) | [x] | b5bfc1f |
| T2 | 1 | Domain plan: `ReleaseUpgradePlan`, planner and `fingerprint()` with every rule of the Decisions table; unit tests per rule | delegated writer (slice 1) | [x] | 2879a47 |
| T3 | 1 | Apply: `Campaign::upgradeRelease` (mutable pin, fingerprint check, `ReleaseUpgradeChanged`, `CampaignUpToDate`, all-or-nothing, FlowRun restart keeping paused); unit tests incl. schema v1 → v2 and derived values | delegated writer (slice 1) | [x] | f4380b9 |
| T4 | 2 | Slice 1 review fixes: R3-001 compare a fitted track normalized (reordered JSONB keys are unchanged, not clamped) with a test; R3-002 test that an open guided Scene goes on as a free Scene consistent with the restarted FlowRun | delegated writer (slice 2) | [x] | |
| T5 | 2 | Application: `PreviewReleaseUpgrade`, `UpgradeCampaignRelease`; persistence round trip; Behat scenarios | delegated writer (slice 2) | [x] | |
| T6 | 2 | HTTP: preview and upgrade endpoints, API tests for every status, `make api` | delegated writer (slice 2) | [ ] | |
| T7 | 3 | Slice 2 review fixes | delegated writer (slice 3) | [ ] | |
| T8 | 3 | Frontend: upgrade action and dialog, hooks, tests, stories; roadmap status | delegated writer (slice 3) | [ ] | |

## Progress

- 2026-10-18: T5 done: `PreviewReleaseUpgrade` (view = domain plan + current version), `UpgradeCampaignRelease` (latest only: unknown or older-than-latest target is `ReleaseUpgradeChanged`, not newer is `CampaignUpToDate`); Doctrine round trip of an upgraded campaign; `release-upgrade.feature` (5 scenarios). Handlers were written before their Behat scenarios (no RED).
- 2026-10-18: T4 done: R3-001 planner compares fitted values key-normalized (no RED reachable: `Character::of` already normalizes track key order, so the test is a regression guard); R3-002 test that an open guided Scene goes on free after the upgrade and guidance picks the next Scene (no bug found).
- 2026-10-18: T1 done: ADR 0035, index, ADR 0010/0014 pointers, glossary, context map, contract paragraph.
- 2026-10-18: T2 done: `Campaign\Upgrade` plan, planner, fingerprint, `Campaign::planReleaseUpgrade`; 36 unit tests (RED: undefined method, then GREEN).
- 2026-10-18: T3 done: `Campaign::upgradeRelease` (all or nothing on a copy, re-pin, FlowRun restart keeping paused), `CampaignUpToDate`, `ReleaseUpgradeChanged`, `UpgradeToAnotherGameSystem`; 10 unit tests (RED: undefined method, then GREEN). Slice 1 verification: `make qa`, `make test`.
- 2026-10-18: exploration done; decisions taken with the user (fit values, always restart guidance, latest only, no journal entry); issue #207; slice 1 started.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-72bd6dd5daf0f1cb` | b5bfc1f, 2879a47, f4380b9 | Approved and acknowledged; warnings R3-001 (reordered track reported as clamped), R3-002 (no test for an open guided Scene on upgrade), fixed in slice 2 (T4) | [#208](https://github.com/iribarren/ogami/pull/208) |

## Next step

Slice 2: T4 (slice 1 review fixes), T5 (application + persistence + Behat), T6 (HTTP + `make api`); then review and PR.
