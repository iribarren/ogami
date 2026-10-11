# Feature: play-threads-npcs

- **Locator:** `odd/tasks/play-threads-npcs.md` · Engram topic `odd/play-threads-npcs/tasks`
- **Issue:** #63 · **Current branch:** `feat/play-threads-npcs-4-npcs-http` (from `main` `dd3defc`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-flow-run` (PR #62)

## Objective

Per-campaign Threads and NPCs, tags, the `pick` step, the content effects and the NPC part of the Scene cast, as decided by [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) and [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) (decisions 3, 9, 15, 16, 20, 22). Roadmap feature 8 (M2).

## Problem and why

Solo play tracks open story lines and the people in them (Mythic's Threads and Characters lists). Flows need them too: world turns pick an NPC by tag and prompt what they do, random events pick or create NPCs and Threads, and conditions count them. Feature 7 left these parts out of the contract and the example fixtures; schema version 2 gains them now, additively, before any flow using them is published.

## Scope

- **In:** release `tags[]`; step kind `pick`; effects `createNpc`, `createThread`, `closeThread`; `condition` counts; `{picked}`; the example fixture parts of feature 8; Play ACL; Campaign NPCs and Threads (add, edit, close, reopen, weight, release and free tags) with persistence, HTTP and panels beside the journal; FlowRun support for the new step, effects and counts; the NPC part of the Scene cast (picks and created NPCs join it, the player edits it); journal entry kind `pick`; focus mode shows the cast.
- **Out:** relationship graphs; player-added trackers for thread progress, faction power or disposition (vision open question); Campaign facts, `fillFact`, `{fact:key}` (8b); Characters, party, the Character part of the cast, tags on Characters (10); deleting NPCs or Threads; editing the cast of past scenes.
- **Factions and disposition** need no fields (ADR 0018 decision 20): a faction is a Campaign fact (8b) plus an NPC tagged e.g. `leader` with an agenda; disposition is a fact linked to an NPC. This feature covers the tagged NPC with its agenda.

## Constraints

- Schema version 2 is extended **additively**: every release valid today stays valid and reads the same. Never edit `v1.schema.json`.
- Dependency rule and PHPat ([ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)): NPCs, Threads and the cast live in framework-free `Play\Domain`; the translator in `Play\Infrastructure\GameSystem`; Studio validation in `Studio\Domain\Release\Version2`.
- ADR 0007: NPCs and Threads are JSONB columns on the Campaign, under its optimistic lock (version → 409); the cast lives in the `sessions` JSONB with its Scene.
- Randomness (random picks) runs on the server with the injected `RandomNumberGenerator`.
- Glossary terms in code, UI and docs: Thread, NPC, Agenda, Tag, Scene cast, `pick` step, Effect, Condition step.
- Every slice is safe on `main` alone: backend unused until its UI lands.

## Contract: schema version 2 additions

Same conventions as the rest of v2: unknown properties rejected, optional fields may be omitted or `null`, one `InvalidReleaseContent` with a path prefix, key rule `^[a-z0-9-]{1,64}$`. The JSON Schema and the domain validator stay in agreement (`ReleaseSchemaAgreementTest`).

```text
tags         [Tag]                                   new top-level, optional (empty by default)
Step kind    pick                                    new
condition    {tracker} | {count}                     count new
Effect kinds createNpc · createThread · closeThread  new
Placeholder  {picked}                                new
```

| Part | Shape | Rules |
|---|---|---|
| Tag | `{key, label (1–50), hint? (≤500)}` | At most 50; keys unique (own namespace). One list for NPCs and Threads (Characters in 10) |
| `pick` | `of: npc \| thread`, `tags?: [key]` (≤5), `mode: random \| choose`, `count?: {min, max}`, `branches?: {found?, none?}` | Tags exist and are unique; `1 ≤ min ≤ max ≤ 10`, default `{min: 1, max: 1}`; branches are `{next?, effects?}` with the forward-only `next` rule |
| `condition` | exactly one of `tracker` or `count: {of: npc \| thread, tags?: [key] (≤5)}`, plus `bands` (1–20) | Tracker or tags exist. The decision-7 authoring warning applies to tracker conditions only |
| `createNpc` | `{kind: createNpc, name? (1–100), agenda? (≤2000), tags?: [key] (≤5)}` | Tags exist and are unique. `name` and `agenda` are placeholder text |
| `createThread` | `{kind: createThread, name? (1–200), tags?: [key] (≤5)}` | Same; `name` is placeholder text |
| `closeThread` | `{kind: closeThread}` | — |
| `{picked}` | Any placeholder text (step `title`, `prompt`, `tip`; effect text: `sceneTitle` title, `createNpc` name and agenda, `createThread` name) | No argument; `{picked:x}` fails |

Effects keep the limit of 10 per list and may appear wherever effects do today (step `effects`, bands, branches, options, `otherwise`, table entries). Canonical form: an empty `tags` list (top level, `pick`, `count`, effects) and a default `count` `{1, 1}` are omitted.

## Play rules

### NPCs and Threads (Campaign state)

| Field | NPC | Thread |
|---|---|---|
| `id` | UUID | UUID |
| `name` | 1–100 | 1–200 |
| `agenda` | optional, ≤2000 | — |
| `tags` | release tag keys of the pinned release, unique, ≤10 | same |
| `freeTags` | player labels, 1–50 chars, unique case-insensitively, ≤10 | same |
| `weight` | 1–3, default 1 (Mythic: an entry listed up to three times) | same |
| `status` | `open` \| `closed` | same |

- At most 500 NPCs and 500 Threads per campaign. Open items list first, in creation order; closed items list after them.
- The player adds, edits (name, agenda, tags, free tags, weight), closes and reopens items at any time, guided or free. Unknown release tag keys fail with 422.
- `pick` and `count` match **open** items carrying **all** listed release tags; free tags never match (the release cannot name them).
- Tags show with their release `label` (and `hint` as a tooltip where tags are chosen); free tags show as typed.

### Flow behaviour

- **`pick` step:** candidates are the open items of its kind matching all its tags.
  - `random`: the player presses Pick; the server draws `min(max, candidates)` items, weighted by `weight`, without replacement. Fewer candidates than `min` → `none`.
  - `choose`: the step lists the candidates; the player selects between `min` and `max` of them (ids sent with the answer; an id that is not a candidate fails with 422). Fewer candidates than `min` → the step shows "No match" and completes as `none`.
  - Outcome `found` or `none`; effect order as for other steps (step effects, then the outcome's). A skipped `pick` changes nothing.
  - Result: journal entry kind `pick` `{of, tags, picked: [{id, name}]}` (empty on `none`). `{answer}` is the picked names joined with ", " (empty on `none`).
- **`{picked}`:** the names of the items most recently picked or created in the current Scene (scene or hook Scene), joined with ", ", read when rendered (a rename shows). Empty before any pick in that Scene; reset when a new Scene starts.
- **`createNpc` / `createThread`:** create an open item with weight 1 and the effect's tags. `name` rendered; when omitted or empty: `New <label of the first tag>` or `New NPC` / `New Thread`. The created item becomes `{picked}`. A created NPC joins the current Scene's cast.
- **`closeThread`:** closes the open Threads in the current `{picked}` when it holds Threads; otherwise nothing happens.
- **`condition` count:** the number of open items of its kind matching all its tags, compared to the bands like a tracker value.
- **Scene cast (NPC part):** an ordered, unique list of NPC ids on each Scene. NPCs picked by a `pick` step or created by `createNpc` join the current Scene's cast. The player edits the current Scene's cast (add any NPC, remove any). Closing an NPC leaves past casts unchanged. Scenes stored before this feature read with an empty cast.

## Slice plan

Forecasts include tests, docs and generated files (OpenAPI spec, TS types). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-threads-npcs-1-contract` | `feat(studio): play-threads-npcs 1/9 contract` | T1 | ~1,500 |
| 2 | `feat/play-threads-npcs-2-acl` | `feat(play): play-threads-npcs 2/9 acl` | T2, T3 | ~1,400 |
| 3 | `feat/play-threads-npcs-3-lists` | `feat(play): play-threads-npcs 3/9 lists` | T4 | ~1,400 |
| 4 | `feat/play-threads-npcs-4-npcs-http` | `feat(play): play-threads-npcs 4/9 npcs-http` | T5 | ~1,600 |
| 5 | `feat/play-threads-npcs-5-threads-http` | `feat(play): play-threads-npcs 5/9 threads-http` | T5b | ~1,000 |
| 6 | `feat/play-threads-npcs-6-lists-ui` | `feat(play): play-threads-npcs 6/9 lists-ui` | T6 | ~1,400 |
| 7 | `feat/play-threads-npcs-7-flow` | `feat(play): play-threads-npcs 7/9 flow` | T7 | ~1,700 |
| 8 | `feat/play-threads-npcs-8-flow-http` | `feat(play): play-threads-npcs 8/9 flow-http` | T8 | ~1,600 |
| 9 | `feat/play-threads-npcs-9-flow-ui` | `feat(play): play-threads-npcs 9/9 flow-ui` | T9, T10 | ~1,500 |

Slices 1–3 shipped with `n/8` titles; the plan grew to 9 when slice 4 (forecast ~2,100 with both lists) was split into NPCs and Threads.

Safe on `main`: slice 1 lets Studio accept the new parts before Play reads them; releases are published only from `backend/presets/`, none uses the new parts, and slice 2 adds the Play side next. Slice 2 reads the new parts into the snapshot; FlowRun evaluates a `count` condition as 0 until slice 3 (Campaigns hold no NPCs or Threads yet) and does not play `pick` steps or the new effects until slice 7, so presets must not use them before then. Slices 3–5 add unused backend (the campaign response gains `tags`, `npcs`, `threads`, ignored by the SPA); 6 shows the lists; 7–8 add unused flow support; 9 shows it.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. `v2.schema.json`: `tags`, `pick`, `condition` count, `createNpc` / `createThread` / `closeThread`. Studio `Version2`: tag catalog, step `pick`, condition count, new effects, `{picked}`; decision-7 warning ignores count conditions. Contract doc v2 additions. Valid and invalid fixtures; unit tests | delegated writer (slice 1, 4+ non-trivial files) | [x] | `7e40f00` |
| T2 | 2 | The five example fixtures gain their feature-8 parts (examples README); warnings re-pinned | delegated writer (slice 2, 4+ non-trivial files) | [x] | `69fe4c5` |
| T3 | 2 | Play ACL: tags in the snapshot, `PickStep`, condition count, the three effects, typed and framework-free; translator tests | delegated writer (slice 2, 4+ non-trivial files) | [x] | `d89996a` |
| T4 | 3 | Campaign NPCs and Threads (domain: add, edit, close, reopen, weight, tags, free tags, limits); JSONB columns, Doctrine types, migration; repository round trip incl. legacy rows | delegated writer (slice 3, 4+ non-trivial files) | [x] | `b3451f0`, `2a9b710` |
| T5 | 4 | Campaign response gains `tags`, `npcs`, `threads`; commands, handlers and endpoints for NPCs (add, edit, close, reopen); API tests (400/404/409/422), Behat; `make api` | | [ ] | |
| T5b | 5 | Commands, handlers and endpoints for Threads (add, edit, close, reopen); API tests, Behat; `make api` | | [ ] | |
| T6 | 6 | NPCs and Threads panels beside the journal (list, add, edit, close/reopen, weight, tags with hints, free tags); stories and tests | | [ ] | |
| T7 | 7 | FlowRun: `pick` (random weighted, choose), effects, condition counts, `{picked}`, the Scene cast (domain and `sessions` JSONB, legacy scenes), journal entry kind `pick`; unit tests | | [ ] | |
| T8 | 8 | HTTP: step view for `pick` (mode, count, candidates), answer with picked ids, cast in the scene view, `PUT` current scene cast, `pick` journal entry; API tests, Behat; `make api` | | [ ] | |
| T9 | 9 | UI: `pick` step card, cast in the journal scene header and focus mode, `pick` journal entry; tests and stories | | [ ] | |
| T10 | 9 | Glossary and contract doc wrap-up; e2e smoke for the lists | | [ ] | |

## Progress

- 2026-10-11: feature started; doc and slice plan written; issue #63.
- 2026-10-11: slice 1 — T1 done: schema version 2 gains `tags`, `pick`, condition `count`, `createNpc` / `createThread` / `closeThread` and `{picked}` (schema, Studio validation, contract doc, fixtures, tests); `make qa` and `make test` green.
- 2026-10-11: slice 2 — T3 done: the Play ACL reads `tags`, `pick` steps (`PickStep`, `ItemKind`, `PickMode`), condition `count` (`ItemCount`) and the `createNpc` / `createThread` / `closeThread` effects, failing closed with a path; FlowRun evaluates a count condition as 0 and ignores the new effects until slice 6; slice 1 review fix covered by two `pick` tests in `AuthoringWarningsTest`.
- 2026-10-11: slice 2 — T2 done: the five example releases gain their feature-8 parts (tags, `pick` steps, the three effects, `{picked}`, the crew count condition); examples README updated, warnings unchanged and re-pinned; Play reads all five.
- 2026-10-12: slice 3 — T4 done: Campaign keeps NPCs and Threads (`Play\Domain\Campaign\Lists`: add, edit, close, reopen, weight, release and free tags, 500 limit, all-or-nothing, open items first); JSONB columns `npcs` / `threads` (default `[]`, strict Doctrine types, migration `Version20261012120000`, legacy rows read empty); `FlowRunContext::openItemCount()` counts real open items, so a count condition takes the band of the real count (tested); slice 2 review fix: `ExampleReleasesTest` walks the decoded release.
- 2026-10-12: slice 4 — slice 3 review fixes: both Doctrine readers fail closed on duplicate ids and on more than 500 items, `Campaign::reconstitute()` rejects duplicate ids, Thread reader malformed-data tests at parity with NPC ones, write-side `InvalidType` checks and the unpinned-release refusal (`editNpc`, `addThread`, `editThread`) tested.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-718dfb084ffc7d2d` | `7e40f00` | Approved. One non-blocking finding, fixed in slice 2: the authoring-warning walk treats a `pick` with both `found` and `none` branches as exhaustive (`AuthoringWarnings.php:160`) and no test covers it | [#64](https://github.com/iribarren/ogami/pull/64) |
| 2 | `review-6f606bfcc7c3e14f` | `9ce8501`, `d89996a`, `69fe4c5` | Approved. Non-blocking findings: `pick` steps load in Play while FlowRun cannot play them and no FlowRun test pins the behaviour at a `PickStep` (slice 6); `openItemCount` always returns 0, so the `cpr-campaign-in-acts` crew-size condition takes its lowest band (wired in slice 3, played in slice 6); `ExampleReleasesTest.php:74` matches raw JSON with a whitespace-sensitive regex (fixed in slice 3) | [#65](https://github.com/iribarren/ogami/pull/65) |
| 3 | `review-c97841621e9e0f4e` (domain), `review-f2d1b45657573dd2` (persistence) | `79fb04f`, `b3451f0`, `2a9b710` | Approved (two candidates; +1,892 lines, size exception accepted). Non-blocking findings, fixed in slice 4: the Doctrine readers and `reconstitute()` accept a stored list with duplicate ids (`CampaignNpcsType.php:74`, `CampaignThreadsType.php:73`); Thread reader malformed-data tests cover fewer branches than NPC ones; write-side `InvalidType` checks untested; unpinned-release refusal tested only through `addNpc` | [#66](https://github.com/iribarren/ogami/pull/66) |

## Next step

Slice 4 (T5): slice 3 review fixes, campaign response with `tags`, `npcs`, `threads`, and the NPC endpoints. Next slice 5 (T5b): the Thread endpoints.
