# Feature: preset-cpr-campaign

- **Locator:** `odd/tasks/preset-cpr-campaign.md` · Engram topic `odd/preset-cpr-campaign/tasks`
- **Issue:** [#322](https://github.com/iribarren/ogami/issues/322) · **Branch:** `feat/preset-cpr-campaign-1-release` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-items-economy` (PRs #312–#320)

## Objective

Roadmap backlog B12. A Cyberpunk RED campaign in three acts, following [example 4](../../docs/domain/flow-examples.md#4-cyberpunk-red-campaign-in-acts): Act 1 making a name (gigs as `nextScene` chains, rep and heat, recruiting a crew), Act 2 the big job, Act 3 the twist and conclusion; acts as phases with an `act` label; a survival economy from `play-items-economy` ([ADR 0045](../../docs/adr/0045-gear-inventories-currency-and-shops.md)): lifestyle and housing levels, Downtime, Night Market, Month's end.

Out of scope: carrying a heist campaign into the new flow (B13 `play-campaign-transfer`); contract or Play changes (exploration found no gap); flow effects on gear (ADR 0045).

## Problem and why

- No shipped preset has two flows or uses the economy (currency, inventories, gear catalog, `shop`). Only the e2e fixture `backend/tests/Fixtures/E2e/corner-shop.json` does. The economy needs a real user before more presets lean on it.
- B13 needs a GameSystem with a one-shot flow and a campaign flow.

## Decisions

| Question | Decision |
|---|---|
| Where the campaign lives (Claude, exploration) | A second flow `campaign` "Making a Name" in `backend/presets/night-job.json`, published as release v2 by `make presets` (content hash, no version field). The contract allows up to 20 flows, at most one `default`; Play's New campaign form offers a Flow select. `heist` keeps `default: true` and stays unchanged; `campaign` has no `default`. GameSystem key and name stay; the description covers both flows. Credits unchanged |
| Heist and existing campaigns (Claude) | The heist flow, its phases and the heist Scene Types are not edited. The only shared change is the sheet: `gear` and `cyberware` become `inventory` fields. Campaigns pinned to v1 stay on it (ADR 0014); an explicit upgrade converts their list entries through the `converted` change (ADR 0045) and restarts guidance |
| Sheet and economy (Claude, from example 4) | `eddies` number (min 0, default 500), `sheet.currency {field: eddies, unit: "eb"}`, in the Kit group. `gear` inventory, capacity 20 slots, categories Gear and Food. `cyberware` inventory, maxStacks 10, category Chrome. Other fields unchanged |
| Gear catalog (Claude) | Original names; costs on Cyberpunk RED's price categories (10, 20, 50, 100, 500, 1,000). Gear: used pistol 50 (1 slot), armor jacket 100 (2), burner agent 50 (1), climbing gear 100 (2), medkit 100 (1). Food: kibble 10 (1), real coffee 20 (1). Chrome: cheap cyberoptic 100, neural link 500, interface plugs 100 (no slots: `maxStacks` limits Chrome) |
| Trackers (Claude, from example 4) | New: `rep` counter 0–10; `heat` counter 0–10, hint "At 8+ someone sends a hit squad"; `lifestyle` counter 0–3 starting at 1, levels Kibble / Prepak / Good prepak / Fresh food; `housing` counter 0–3 starting at 0, levels Cube hotel / Cargo container / Studio apartment / Two-room conapt; `month` clock 4. Reused: `alarm`, `edge`, `objective` |
| Tags, fact slots (example 4) | Tags added: `fixer`, `netrunner`, `solo`, `rival`, `corp` (`crew`, `client`, `nomad` exist). Fact slots added: `turf`, `crew-name`, `big-job`, `twist` (text); `fixer`, `nemesis` (npc) |
| Tracker values shared by both flows (Claude) | Tracker values live on the Campaign, not per flow. The campaign resets `alarm` at each Gig offer and `alarm`, `edge`, `objective` in `planning`'s `phaseOpening`, so a transferred campaign (B13) never starts a job dirty |
| Gig chain (example 4) | New Scene Types `gig-offer` → `legwork` → `job` → `gig-payday`, chained by `nextScene` as in example 4. `job` is a compressed heist: an oracle question (`fate`), the `complications` table (its entries carry alarm effects) and an edge spend tip. `gig-payday`: the alarm condition gives rep +2 (alarm ≤ 3) or +1, then a `choice` "Collect the pay" whose option adds eddies `{formula: "200 + 50 * {tracker:rep}"}` (the Character is picked by the player; never in `setup`), then the loose-ends prompt (`createThread`). The heist `payday` is not reused (it reads `objective` and ends the heist) |
| Act 2 legwork (Claude) | `planning` selects the heist `social` and `exploration` types, `recruit` and `gear-up`, not the Act 1 `legwork`, whose closing chains into a gig `job` |
| The big job (Claude) | `the-job` (loop, player) selects the heist `infiltration`, `netrun` and `grab` (Firefight and Chase stay reachable through their switches). `grab`'s closing sets `objective` 1 and ends the phase, which here moves to `fallout`. `the-job` copies the heist's `the-heist` hooks (alarm lockdown ends the phase at 6). `fallout` (once, sequence) is `getaway` → `fallout`, an amendment to example 4 (which lists Fallout only): the getaway belongs after the grab. Fallout: `objective` 1 → rep +3 and a "Collect the score" choice (eddies `{formula: "1000 + 100 * {tracker:rep}"}`); 0 → heat +3; then "Who knows it was you?" (`createThread`) |
| Month's end (Claude, amends example 4) | Downtime closing: `month` +1, then month ≥ 4 → `nextScene` Month's end. Month's end `play`: a housing condition with one band per level, each to a "Pay the rent?" choice: pay → eddies −500 / −1,000 / −1,500 / −2,500 (refused with `insufficient_funds` when the Character cannot pay, so the player skips); skip → `lifestyle` −1 and a pick of NPC [fixer] → `createThread` "Debt to {picked}". **Both options set `month` 0** (example 4 resets only on pay, which would bring Month's end back after every Downtime). Then "Eat better?" (yes → `lifestyle` +1, eddies −300) and "Move up?" (yes → `housing` +1) |
| Night Market (example 4) | Reached by `nextScene` from Downtime ("Hit the Night Market?") and Gear up. `play`: a `shop` "What do you buy or sell?" offering categories Gear, Food and Chrome, stock `{dice: "1d4+2"}`, sellRate 50 |
| Authoring route (Claude) | The writer authors the JSON by hand, in the file's style, not in Studio: the Studio editors were exercised in `play-items-economy`. A Chrome play pass (slice 2) replaces a separate playtest |
| Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)) | Original wording only, no book text, rules summaries in own words; names that are common Cyberpunk RED terms (eddies, Night Market, Kibble, Prepak, fixer, netrunner) are used as game terms; the RTG Homebrew Content Policy notice already in the credits covers the release |

## Design

### Flow `campaign` "Making a Name"

`defaultView: focus`; an introduction in the heist's style (solo play in one paragraph, the three acts, rep and heat, eddies and Month's end, rules come from the book). `flow.trackers`: rep, heat, lifestyle, housing, month, alarm, edge, objective. `flow.oracles`: every table the flow and its reachable Scene Types roll or shortcut.

| Act | Phase | Mode | Selection | Scene Types |
|---|---|---|---|---|
| — | `street-zero` | once | sequence | `edgerunner` (character step) → `turf` (fillFact turf, crew-name) → `fixer` (createNpc [fixer], fill fact `fixer`) |
| Act 1: Making a name | `making-a-name` | loop | player | `gig-offer`, `downtime`, `recruit`, `social` |
| Act 2: The big job | `planning` | loop | player | `social`, `exploration`, `recruit`, `gear-up` |
| Act 2: The big job | `the-job` | loop | player | `infiltration`, `netrun`, `grab` |
| Act 2: The big job | `fallout` | once | sequence | `getaway` → `fallout` |
| Act 3: The twist and conclusion | `finale` | once | sequence | `betrayal` → `showdown` → `epilogue` |

- `making-a-name` hooks as example 4: `worldTurn` (1d6; 5–6 → `street-events` with per-entry branches: rival crew, gang war, corp sweep heat +1, special gig → `gig-offer`, grudge → createNpc [rival] then fill `nemesis`; then heat ≥ 8 → `hit-squad`, whose closing sets heat 4); `sceneClosing` gate (rep ≥ 5, then NPC [crew] count ≥ 2, then "Go big?" yes → `endPhase`).
- `recruit`: who joins (createNpc [crew] plus a specialty tag through a choice: netrunner, solo, nomad).
- `downtime`: a prompt with the Night Market tables as shortcut oracles, "Hit the Night Market?" → `night-market`, then the month tick.
- `planning` `phaseOpening`: prompt "Act 2: The big job. What is it?" → fillFact `big-job`; alarm, edge and objective set 0.
- `betrayal`: pick NPC [fixer], `none` → pick NPC [client]; table `twists` → fillFact `twist` (entries in the "{picked} sold you to {fact:nemesis}" vein). `showdown` and `epilogue` read `{fact:nemesis}`, `{fact:twist}`, `{fact:big-job}`, `{fact:crew-name}`.
- New tables: `gigs` (inspiration for Gig offer), `street-events`, `twists`, `night-market-stalls` and `night-market-finds` (Downtime's shortcuts).
- Publishing prints no new warning (e.g. a threshold consequence that does not lower its tracker).

## Constraints

- Schema version 2 (the economy is additive in it); no contract, Play, Studio or API change. If the writer finds the contract refuses part of this design, they stop and report instead of changing code.
- Existing heist tests stay meaningful: `PresetsTest` and `NightJobPresetFlowTest` hard-code release version 1, `ListField` gear and the tracker list; update the assertions, do not drop them.
- Every slice safe on `main` alone: slice 1 publishes a complete, tested campaign flow; slice 2 adds an e2e, play-pass fixes and docs.

## Slice plan

Forecasts include tests and docs (no generated files: no API change). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-cpr-campaign-1-release` | `feat(presets): preset-cpr-campaign 1/2 night job campaign release` | T1, T2, T3 | ~1,500 (JSON ~900, tests ~450, docs ~150) |
| 2 | `feat/preset-cpr-campaign-2-play-pass` | `feat(presets): preset-cpr-campaign 2/2 play pass` | T4, T5, T6 | ~600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; example 4 in `docs/domain/flow-examples.md` amended (Month's end resets `month` on both options; `fallout` is Getaway → Fallout; `planning` reuses Social and Exploration; gig payday pays eddies) | delegated writer (slice 1) | [x] | |
| T2 | 1 | Release v2 base in `night-job.json`: description, tags, fact slots, trackers, sheet economy (eddies, currency, `gear` and `cyberware` inventories), gear catalog; heist untouched; `PresetsTest` (v2, inventories, currency, catalog) and `NightJobPresetFlowTest` updated | delegated writer (slice 1) | [x] | |
| T3 | 1 | Flow `campaign` with its Scene Types and tables; `PresetsTest` campaign assertions (non-default flow, acts, phases, trackers); `NightJobCampaignPresetFlowTest` unit test playing street zero, a gig chain, a world turn hit squad, the act gate, a Month's end (rent refused when broke, debt thread) and the finale's betrayal fallback | | [ ] | |
| T4 | 2 | Slice 1 review fixes; e2e `frontend/e2e/play-night-job-campaign.spec.ts`: start the campaign flow, play street zero, buy at the Night Market | | [ ] | |
| T5 | 2 | Chrome play pass of Act 1 and a Month's end; fixes | | [ ] | |
| T6 | 2 | Closing: Reviews, roadmap `Done:` line under B12 | | [ ] | |

## Progress

- 2026-10-28: Exploration (contract support, multi-flow, publishing, tests): every element of example 4 exists in schema version 2; no blocker. Decided a second flow in `night-job`.
- 2026-10-28: T1 done: feature doc added; example 4 amended (planning, fallout, gig payday, Night Market entry points, month reset on both Month's end options).

- 2026-10-28: T2 done. RED: 3 PresetsTest failures (trackers, sheet fields, economy test); GREEN after the JSON: `phpunit --filter 'NightJob|nightJob'` 33 tests OK, `PresetsTest` 11 OK. `PresetsTest` publishes into an empty test database, so `releaseVersion()` stays 1 there (v2 only exists in a database that already holds v1). Heist flow and phases untouched; only the hard-coded fact list of `NightJobPresetFlowTest` changed.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T1–T3 on `feat/preset-cpr-campaign-1-release`.
