# Feature: preset-guided-sample

- **Locator:** `odd/tasks/preset-guided-sample.md` · Engram topic `odd/preset-guided-sample/tasks`
- **Issue:** #92 · **Branch:** `feat/preset-guided-sample-1-credits` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-mythic-flow` (PRs #89–#91)

## Objective

Hand-author a Cyberpunk RED heist one-shot fan preset for novice players (roadmap feature 9b, milestone M2), following example 2 of [Flow examples](../../docs/domain/flow-examples.md#2-cyberpunk-red-heist-one-shot), that proves the NarrativeFlow model of [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) and [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) without Studio. Settle the release credits / license field (vision open question). Play a session as a novice, fix the gaps it reveals, log UX findings as follow-up tasks. This completes M2.

## Problem and why

- The flow is already a validated fixture (`backend/tests/Fixtures/Studio/releases/examples/cpr-heist.json`, short tables, no tips or introduction), so schema version 2 expresses the heist. What is missing is a playable preset with original content for a novice.
- A fan preset must carry the publisher's disclaimer in a noticeable place (ADR 0018 decision 29), and the release has nowhere to put it: `gameSystem` holds only `key`, `name`, `description`.
- Play receives the flow `introduction` (`FlowSummaryResponse`) and Scene Type `tips` but shows neither; a novice gets no orientation.

## Publisher policy (R. Talsorian Games Homebrew Content Policy, updated 2021-03-22)

Source: https://rtalsoriangames.com/homebrew-content-policy/ (read 2026-10-13).

- Free only. No RTG game, book or trademark titles in our title; allowed in a descriptive subtitle ("… for use with Cyberpunk RED").
- No reproduced text (rules, stat blocks, descriptions). Short summaries with page references are fine ("see CPR pg. 359"); tables only when needed, never so complete that the book is unnecessary.
- No RTG logos, art, colors, stylized fonts or trade dress.
- A disclaimer in an easily noticeable place: "<Title> is unofficial content provided under the Homebrew Content Policy of R. Talsorian Games and is not approved or endorsed by RTG. This content references materials that are the property of R. Talsorian Games and its licensees."

## Decisions (2026-10-13, user)

| Question | Decision |
|---|---|
| Credits / license field | **Structured, optional** `gameSystem.credits: {notice, license, url}` in schema version 2 (additive). Play shows the notice where a game system is picked and on the campaign page |
| Page references | **Chapter names**, e.g. "see the CPR core rulebook, Netrunning chapter". No page numbers (unverifiable here); the user may add them later |
| Name | **Night Job**, key `night-job`; description subtitle "a heist one-shot for use with Cyberpunk RED" |
| Playtest | Claude in Chrome after slice 2 merges (as in `preset-mythic-flow`); the user reviews the findings |

## Design

### Release credits

- Contract (schema version 2, additive; never edit `v1.schema.json`): `gameSystem.credits` optional, null or an object with `notice` (required, trimmed, 1–2000), `license` (optional string ≤ 200, null), `url` (optional, null or an absolute `https://` URL ≤ 500). `additionalProperties: false`. The Version2 validator and the JSON Schema agree (`ReleaseSchemaAgreementTest` fixtures: valid with all fields, valid with notice only, invalid: empty notice, unknown field, non-https url).
- Studio domain carries it in the release; Play reads it through the ACL translator into the pinned game system snapshot; the game system list (campaign creation) and the campaign view expose `credits` (nullable object). `make api`.
- UI: `NewCampaignForm` shows the chosen game system's notice (and a license link when `url` is set) below the pickers; the campaign page shows it in a small "About this game" footer or panel. Releases without credits show nothing.
- Docs: ADR 0021 "Release credits for fan presets" (the field, why structured, where Play shows it); vision open question resolved; contract doc `docs/contracts/gamesystem-release.md`; glossary "Credits"; ADR index.

### Novice support

- `NewCampaignForm`: the chosen flow's `introduction` under the flow picker (paragraphs kept).
- Guided play: the introduction is reachable during play (e.g. a collapsible "How this flow works" in the guided panel), open by default before the first session.
- Scene Type `tips`: shown on the scene pick card and in the current scene's guide (collapsible).

### Preset `night-job` v1 (slice 2)

- Start from `cpr-heist.json` (flow, trackers, fact slots, tags, Scene Types). Every text original; references by book and chapter in tips.
- `gameSystem`: name "Night Job", description opening "A heist one-shot for use with Cyberpunk RED, for players new to solo play.", `credits` = the RTG disclaimer with "Night Job" as title, license "R. Talsorian Games Homebrew Content Policy", url the policy page.
- Flow `heist`: `defaultView: focus`, an introduction for novices (what solo play is, how the guide works: mandatory vs suggested steps, the alarm clock, Move on), tips on most steps and every Scene Type, mandatory crew and plan steps, suggested others.
- Phases as example 2: the job (Crew → Briefing filling `client`, `target`, `payout`, `plan`), legwork (loop, player, Social / Exploration / Netrun; edge and the "word gets around" world turn), the heist (loop, Infiltration / Netrun / Grab; alarm conditions, Infiltration → Firefight switch, Grab → `endPhase`, complications moving the alarm, forced Firefight world turn), escape (Getaway, switch to Chase, `pick NPC [crew, nomad]`), epilogue (Payday quoting `{fact:payout}`, double-cross roll).
- Oracles: a likelihood `fate` question plus the tables of example 2 with 10–20 original entries each (`npc-name` street names). The flow's `oracles` is a heist subset; at least one release oracle stays for free play only.
- A curated theme that fits (pick from the existing themes).

## Constraints

- Schema version 2 extended additively; JSON Schema and Version2 validator agree. Play reads releases only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- `make api` after response changes; commit `openapi.json` and `schema.d.ts`.
- Fan-content rule (ADR 0018 decision 29) and the RTG policy above.
- Every slice safe on `main` alone: slice 1's field is optional and no preset sets it yet.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-guided-sample-1-credits` | `feat(play): preset-guided-sample 1/3 credits` | T1, T2, T3 | ~1,500 |
| 2 | `feat/preset-guided-sample-2-preset` | `feat(play): preset-guided-sample 2/3 preset` | T4, T5 | ~1,500 |
| 3 | `fix/preset-guided-sample-3-playtest` | `fix(play): preset-guided-sample 3/3 playtest` | T6, T7, T8 | ~1,000 |

- **Slice 1** adds an optional field no preset sets and shows texts Play already receives.
- **Slice 2** adds a new preset under a new key; existing campaigns are untouched.
- **Slice 3** fixes playtest findings and closes the feature and M2.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. ADR 0021 (release credits), vision open question resolved, glossary "Credits", ADR index | delegated writer (slice 1) | [x] | |
| T2 | 1 | Contract and pipeline: `credits` in `v2.schema.json` and the Version2 validator, agreement fixtures, Studio domain, ACL translator and snapshot, game system list and campaign views, API tests, `make api`, contract doc | delegated writer (slice 1) | [x] | |
| T3 | 1 | UI: credits notice in `NewCampaignForm` and on the campaign page; flow introduction in `NewCampaignForm` and in guided play; Scene Type tips on the pick card and the current scene; Vitest tests; fixtures | delegated writer (slice 1) | [x] | |
| T4 | 2 | Preset `night-job` v1 per Design; `PresetsTest` and a `NightJobPresetFlowTest` (validates, passes the ACL, briefing fills the facts, the alarm conditions, the Infiltration → Firefight switch, Grab ends the phase, Getaway → Chase with both pick branches, Payday quotes the payout, the FlowRun completes) | | [ ] | |
| T5 | 2 | e2e: create a Night Job campaign, see the disclaimer and introduction, complete the mandatory crew step; `make e2e` | | [ ] | |
| T6 | 3 | Playtest as a novice in Chrome: every phase, the alarm rising, a switch, the escape, Payday; findings logged below | parent (browser) | [ ] | — |
| T7 | 3 | Fix the blocking playtest findings; the rest go to the backlog | | [ ] | |
| T8 | 3 | Wrap-up: roadmap 9b done, M2 complete, flow example 2 notes, feature doc closed | | [ ] | |

## Playtest findings

(T6)

## Progress

- 2026-10-13: feature started. Explored the example fixture, the v2 contract (no credits field; introduction and tips exist but Play shows neither) and the RTG Homebrew Content Policy. Credits shape, page references and name decided with the user. Doc and slice plan written; issue #92.
- 2026-10-13: T1 done. Feature doc committed; ADR 0021 (release credits), ADR index, vision question resolved, glossary "Credits". Docs only, no RED/GREEN. Route: delegated writer.
- 2026-10-13: T2 done. `gameSystem.credits` in schema v2, Version2 validator, Credits value object, ACL translator, `credits` on the game system list and `pinnedRelease.credits`, `make api`, contract doc. RED: agreement cases (11 failures) and translator test (class missing) before implementation; API and handler tests written after the code, no separate RED run. GREEN: ReleaseSchemaAgreementTest 238 OK, translator credits 12 OK, Play suites 1546 OK. Route: delegated writer.
- 2026-10-13: T3 done. The API now sends Scene Type `tips` (campaign `sceneTypes` and scene pick cards) and the Flow `introduction` in the game system catalog. `NewCampaignForm` shows the credits notice (license link) and the Flow introduction; campaign page "About this game"; guided play "How this flow works" (open before the first session, folded after) and "Tips for <Scene Type>", tips on the pick card. RED: `CampaignFlowsApiTest` expectations (2 failures) before the API change; Vitest tests written with the code, no separate RED run. GREEN: `src/play` Vitest 237 OK. Route: delegated writer.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1 written (T1–T3): parent runs the native review, then pushes and opens the PR.
