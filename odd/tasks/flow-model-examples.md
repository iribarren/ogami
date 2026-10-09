# Feature: flow-model-examples

- **Locator:** `odd/tasks/flow-model-examples.md` · Engram topic `odd/flow-model-examples/tasks`
- **Issue:** #37 · **PR:** #38 · **Branch:** `docs/flow-model-examples-1-model` (from `main` `b97b0c5`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `flow-model-brainstorm` (ADR 0017)

## Objective

Map five real play examples to the NarrativeFlow model of [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) and find its limits before feature 7 `play-flow-run` implements release schema version 2. Roadmap feature 6c.

## Problem and why

ADR 0017 left four questions to 6c (world-turn consequences, loop end, interruptions, NPC disposition / factions / thread progress), and the 6c prompt added four more (sandbox goals, acts, several player characters, other gaps). Campaigns pin published releases for good ([ADR 0014](../../docs/adr/0014-campaigns-pinned-to-their-release.md)), so every shape change must be known before schema version 2 ships.

## Scope

- **In:** a brainstorm over five examples (done in session, read-only), then documentation only: ADR 0018, ADR 0017 status note, ADR index, glossary, context map, vision open questions, roadmap, `docs/domain/flow-examples.md`.
- **Out:** code, schema implementation, presets, Studio UX.

## Examples mapped

1. Vampire: the Masquerade chronicle (sandbox, Session Zero facts, ambitions into goals).
2. Cyberpunk RED heist one-shot (generic and game-specific Scene Types, a clock).
3. Mythic-style session (chaos, scene check, altered / interrupted scenes, list updates).
4. Cyberpunk RED long campaign in acts (gigs, rep, three acts).
5. West Marches fantasy campaign (roster, party per session, expeditions).

## Decisions (approved by the user, 2026-10-09)

**Control flow (schema version 2 shape, feature 7)**

1. **Effects are a tagged union:** `tracker` (add | set), `nextScene`, `switchSceneType`, `endPhase`, `sceneTitle`. Later: `createNpc`, `createThread`, `closeThread` (8), `fillFact` (8b), sheet-field effects (M3 schema version).
2. **Outcome bands are ordered upper bounds** (`upTo`: a literal or a tracker reference); the last band catches the rest. No arithmetic is ever needed. Applies to `roll`, `condition` and table entries.
3. **Step kind `condition`:** compares a tracker (7) or a count (8: NPCs or Characters with a tag, open Threads) to bands; no dice, no journal entry, auto-advances. Compound conditions are chained `condition` steps. A `condition` before a `choice` gates options, so options need no conditions of their own.
4. **`table` steps branch per rolled entry**, like a roll's bands. **`oracle` steps branch on their answer** (yes, no, exceptional).
5. **Oracle table entries may carry `effects`** beside `sceneType`, applied when a flow step rolls them.
6. **Interruptions switch in place:** `switchSceneType` changes the current Scene's type; entries stay, the position jumps to the new type's `setup`, the scene opening does not re-run, the switch is recorded in the FlowRun history. At most one switch per scene through effects. In free play the player may switch by hand (recorded), offered when a rolled table entry names a Scene Type.
7. **World turn → next scene**, three authored strengths, no new concept: colour (quoted through placeholders, ignorable), pressure (a clock or Thread; ignoring it lets the clock fill; a `condition` fires the consequence), forced (`nextScene`; the scene pick offers only that card). Authoring rule: a threshold consequence must lower its tracker, or it fires on every turn (validation warns).
8. **A loop phase ends** by the player's choice (always available) or by `condition` + `endPhase`. No separate condition language.
9. **Placeholders are namespaced:** `{tracker:key}`, `{step:key}`, `{answer}` (7), `{picked}` (8), `{fact:key}` (8b).
10. **A FlowRun is `completed`** after its last phase; play continues unguided.
11. **A phase with one Scene Type** picks it automatically, with no card. A Scene's default title is its Scene Type name, numbered; `sceneTitle` overrides it.

**Hooks**

12. Phases gain **`sessionOpening` / `sessionClosing`** and **`phaseOpening` / `phaseClosing`** beside `sceneOpening`, `sceneClosing` and `worldTurn`.
13. **Scene kind is `scene` | `hook`**, with the hook name (`worldTurn`, `sessionOpening`, `sessionClosing`, `phaseOpening`, `phaseClosing`), each rendered its own way ("The world moves", "Session 3 begins", "Act 2: The big job"). Amends ADR 0017's `world-turn` kind.
14. A **Session is one real-world sitting**, not an in-story night or day. In-story time is authored content (e.g. a `night` counter and a "Dawn" Scene Type). Play gains an **"End session"** action; while guided it is offered only between scenes.

**Content**

15. **Tags:** a release declares `tags[] {key, label, hint?}`, applying to NPCs, Characters and Threads; the player may add free tags. Not "roles", which would clash with sheet fields such as Cyberpunk RED's Role.
16. **`pick`:** filters by several tags (all must match), mode `random` | `choose`, a count (`min` / `max`, one by default), branches `found` / `none`.
17. **Hints:** optional `hint` on trackers (7) and fact slots (8b), shown where the value is shown or filled.
18. **Counters have optional named `levels`** (e.g. lifestyle "Kibble / Prepak / Good prepak / Fresh food").
19. **Acts are phases.** Phases have an optional **`act` label**; progress reads `Act › Phase › Scene type › part · step n/m`. No new level.
20. **Factions** are Campaign facts plus a tagged leader NPC with an agenda; disposition and boons are facts linked to NPCs. No new fields.
21. **Sandbox goals** are Threads in `thread` fact slots (`ambition` long-term, `desire` refilled each session), driven by session hooks. No new concept.

**Cast and party**

22. **Scene cast:** the NPCs present and the Characters the player controls in a Scene. Picks add to it; the player edits it. NPC part in 8, Character part in 10.
23. **Session party:** the Characters picked in a session opening; a Scene's cast starts as the party (10).
24. **A Campaign has zero or more Characters**; an NPC can be promoted to a Character (10). Checks roll for a Character in the cast (12).

**Economy (Cyberpunk RED survival)**

25. M2: trackers and release-level Scene Types (Downtime, Night Market, Month's end) with tables and effects. M3: sheet list fields (inventory, cyberware) and effects on sheet fields. Items, inventory and a `shop` step kind become an open question.

**Presets and content**

26. Examples become **contract fixtures** in feature 7 (schema version 2 JSON that must validate and pass the ACL).
27. **9b `preset-guided-sample` becomes a Cyberpunk RED heist** one-shot fan preset.
28. **Backlog presets:** VtM night-court sandbox (after 8b), Cyberpunk RED campaign in acts (after M3 and economy), West Marches on the 5e SRD or Knave (after 10 and Places).
29. **Fan-content rule:** Ogami is personal and non-commercial; presets may use fan material under the publishers' fan-content policies or open licenses. The repository is public, so presets carry no verbatim book text (own words or short summaries, page references to the books) and include the publisher's fan-content disclaimer. A release credits / license field is an open question decided when 9b ships.

**Accepted limits**

- A scene repeats something (netrun floors, dungeon rooms) only through free play or a `nextScene` chain.
- No "for each" over a list; pick one instead.
- Phases only go forward; a failure carries forward.
- A generic Scene Type cannot receive phase-specific steps; author a specific type.
- Scene chains (a gig) are not shown as a group.
- Every tracker of the flow is shown.
- Odd / even bands are replaced by a second roll.

**New open questions**

| Question | Decide when |
|---|---|
| Reusable procedures (named step lists run from a step or the oracle panel) | When a preset repeats a step list |
| Player-added trackers linked to NPCs, Threads or facts (thread progress, faction power, disposition) | When a preset needs them |
| Places (map, regions, sites) | When a map-focused preset needs them |
| Items, inventory and a `shop` step kind | Feature 13 or a post-M3 feature |
| Random events from fate-question doubles | Feature 9 |
| Release credits / license field | Feature 9b |

## Slice plan

One slice (`docs/flow-model-examples-1-model`, PR `docs(flow): flow-model-examples 1/1 model`). Forecast ~900–1,200 changed lines, no generated files: T1 ~350, T2 ~120, T3 ~180, T4 ~400.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Feature doc; ADR 0018 "NarrativeFlow control flow and cast" (decisions, schema v2 additions, where each lands, accepted limits, deferred); ADR 0017 status note; ADR index | delegated writer (T1–T4, 7+ doc files) | [x] | `e50b9be` |
| T2 | Glossary (new: Hook, Condition step, Act, Tag, Scene cast, Party; updated: Effect, Scene, World turn, Flow step, Character, NPC, Thread, FlowRun, Session, Tracker, Fact slot, Outcome band) and context map | delegated writer | [x] | `f6bc14f` |
| T3 | Vision (resolve the four 6c questions, add six open questions) and roadmap (6c done, feature 7 prompt, notes in 8, 8b, 9, 9b, 10, 12, 13, backlog presets) | delegated writer | [x] | `e809e93` |
| T4 | `docs/domain/flow-examples.md`: the five worked examples on the amended model | delegated writer | [x] | `2a10ec7` |

## Acceptance criteria

- ADR 0018 records decisions 1–29, the schema version 2 additions, where each lands, accepted limits and open questions; ADR 0017 points to it.
- Glossary, context map, vision, roadmap and the examples doc use the same terms as ADR 0018 (tags, not roles; Scene kind `hook`).
- Every 6c question in the vision is resolved or moved; every new open question has a "decide when".

## Checks

- Passive documentation: no runnable RED. Structural readback of each file; relative links resolve.

## Progress

- Brainstorm done in session, one example at a time; deliverables approved. Issue #37, branch created.
- T1: ADR 0018 `docs/adr/0018-narrativeflow-control-flow-and-cast.md` records decisions 1–29 grouped as in this doc, answers the eight 6c questions in a table, sketches the schema version 2 additions (not-additive vs later-additive parts), the Play model changes, where each part lands, accepted limits and open questions; ADR 0017 status notes the amendment; ADR index row added. Its link to `docs/domain/flow-examples.md` resolves once T4 lands. Structural readback done.
- T2: glossary adds Act, Hook, Condition step, Tag, Party and Scene cast; updates Outcome band, Phase, World turn (a hook), Flow step, Effect, Tracker, Fact slot, Character, Session, Scene, FlowRun, Thread and NPC, linking ADR 0018. Context map: schema version 2 additions on the Published Language, Play concepts (Characters, party, Scene kind `hook`, cast, tags) and Studio concepts (hooks, act label, Effect, Tag). Structural readback done.
- T3: vision resolves the four 6c questions (ADR 0018 in the "Resolved:" sentence; thread progress moves to the player-added trackers question) and adds six open questions with "decide when". Roadmap: 6c marked done; feature 7 prompt rewritten for ADR 0018 (schema version 2 with decisions 1–14, 17–19, contract fixtures, control-flow slice); prompts 8, 8b, 9, 9b (now a Cyberpunk RED heist fan preset), 10, 12 and 13 updated; "Settles" for 9, 9b and 13; three backlog fan presets with prompts. Links to `docs/domain/flow-examples.md` resolve once T4 lands. Structural readback done.
- T4: `docs/domain/flow-examples.md` maps the five examples on ADR 0017 as amended by ADR 0018, each with its release (trackers with hints, fact slots, tags, Scene Types, tables), flow (phases, hooks and key steps) and "What it shows" (decisions exercised); a notation table and the fan-content note lead. Links from ADR 0018 and the roadmap (including example anchors) now resolve. Structural readback done.
- Fix (`38464a6`), from the writer's report: the feature 7 prompt says parts of the examples that need features 8, 8b or 10 join the contract fixtures when those features land; the feature 18 prompt cites ADR 0018. The T3 table row's missing cell separator is fixed in the review commit.
- Review: native review rated T1–T4 and the fix (`38464a6`, lineage `review-34f2f9ab06204bd6`) low risk (non-executable only); approved and acknowledged with no lenses.
- Delivered: issue #37, PR #38 (`docs(flow): flow-model-examples 1/1 model`, closes #37). Feature complete once #38 is merged; next feature is 7 `play-flow-run`.
