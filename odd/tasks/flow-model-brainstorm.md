# Feature: flow-model-brainstorm

- **Locator:** `odd/tasks/flow-model-brainstorm.md` · Engram topic `odd/flow-model-brainstorm/tasks`
- **Issue:** #35 · **PR:** — · **Branch:** `docs/flow-model-brainstorm-1-model` (from `main` `1d8b9c2`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `flow-presentation-spike` (ADR 0016)

## Objective

Settle the NarrativeFlow model before feature 7 `play-flow-run`, because campaigns pin published releases for good ([ADR 0014](../../docs/adr/0014-campaigns-pinned-to-their-release.md)). Roadmap feature 6b.

## Problem and why

The current plan suits experienced solo players. Novice players need guided flows that a GAME_MANAGER authors for a specific game: Session Zero, sequences of scene types, suggested or mandatory oracle rolls, world changes between scenes. Some content is generic, some game-specific. The release's `flow` is still a flat `{steps: []}` and both presets leave it empty, so its shape can change for free now.

## Scope

- **In:** a brainstorm over 13 topics (done in session, read-only), then documentation only: ADR 0017, ADR 0016 amendment note, ADR 0010 note, ADR index, glossary, context map, roadmap (M2–M4), vision open questions.
- **Out:** code, release schema implementation, Studio editor UX, presets.

## Decisions (approved by the user, 2026-10-08)

1. **Structure:** Flow → Phases (ordered, `once` | `loop`) → Scenes picked from Scene Types → parts `setup` / `play` / `closing` → Steps. Branches only inside a part and forward-only; loops only at phase level. Next Scene Type picked by a per-phase rule: `sequence`, `player` or `oracle` (table entries point to scene types). A `loop` phase ends when the player moves on at a scene boundary (tracker conditions deferred to 6c).
2. **Scene Type:** key, name, purpose, tips, three step lists, oracle shortcuts (subset of the flow's oracles). Phases carry `sceneOpening` / `sceneClosing` step lists that wrap every scene. Mythic's scene check is an ordinary `roll` step with outcome bands whose bounds may reference trackers. The `play` part is guided, then open: its steps run in order, then free play until the player ends the scene. Switching Scene Type mid-scene is deferred.
3. **Mandatory vs suggested:** steps are suggested (Skip) by default; mandatory is a hard gate (no Skip). A branching step is mandatory or names its skip branch. Skips are recorded in the FlowRun history, not the journal. Turning guidance off is the escape hatch.
4. **World turns:** a phase's optional `worldTurn` step list runs between scenes; results are recorded in their own Scene of kind `world-turn`. Encounters are oracle tables, not an entity. Feature 8: NPC `agenda`, `pick` step; disposition, factions and thread progress deferred. How a world turn affects the player's next scene (reacting, consequences of ignoring it, GAME_MANAGER-defined) is explored in a follow-up brainstorm 6c mapping real examples.
5. **Trackers:** `counter` and `clock`, declared at release level; flows list the ones they use; values live on the Campaign. Step outcomes carry `effects` (`add` / `set`). Band bounds and effect values are a literal or a tracker reference; a likelihood oracle's chaos is bound to a tracker. The player may edit values by hand (recorded). Feature 11's formulas must read trackers.
6. **Session Zero:** M2 uses prompt and table steps; M3 adds a `character` step kind. **Campaign facts:** label + text, an optional release **fact slot** typed `text` | `npc` | `thread`, free facts allowed, links to NPCs, Threads or facts by reference. Ships with or after feature 8.
7. **Several flows per release:** `flows[]` with `introduction`, Scene Type `tips`, step `tip`. Free play is no FlowRun, not an authored flow. Flow chosen at campaign creation, or "Play freely". Mid-campaign: **pause/resume only**, no switching. A transfer to a new campaign with another flow is a backlog item after every roadmap feature.
8. **Presentation (amends ADR 0016):** each flow sets `defaultView: focus | journal`; the player toggles and the choice is remembered per campaign. Scene-type cards per selection rule; the scene pick is a mandatory step. The next step is always named across boundaries. Scene headers show the Scene Type; world-turn scenes render as "The world moves". Focus progress `Phase › Scene type › part · step n/m`.
9. **Studio:** Play first stays. List/outline flow editor, no graph library. Scene Types and trackers are release-level and reused across flows. M4 order: drafts → oracle editor → flow editor → sheet builder → check editor, then the library.
10. **Glossary:** Phase, Session Zero, Scene Type, Scene selection, World turn (not "Interlude"), Encounter, mandatory / suggested step, Effect, Tracker, Campaign fact, Fact slot, Guidance, Library, Theme; NarrativeFlow, Flow step, FlowRun and Scene changed; Flow preset dropped.
11. **Narrative assist (note):** Scene Type, current step, facts, NPC agendas, Threads, trackers and recent entries are its structured input. Nothing designed.
12. **Generic content:** a Studio-only Library (M4) whose items are **copied into a release at publish**; overrides by key; any GAME_MANAGER authors it. Releases stay self-contained; pinning and the ACL are unchanged. M2 presets duplicate shared tables; optional provenance later. Each flow selects `oracles[]` and `trackers[]`; Play shows scene shortcuts, then the flow's selection, then the release's other oracles behind "More oracles". Generic flows deferred.
13. **Visual design:** `design-foundation` after the features that add NPCs, Threads and facts, before the M2 playtest presets. Curated per-GameSystem themes (an optional release `theme` naming an app-defined, contrast-checked theme), added in that feature.

## Slice plan

One slice (`docs/flow-model-brainstorm-1-model`, PR `docs(flow): flow-model-brainstorm 1/1 model`). Forecast ~600–900 changed lines, no generated files: T1 ~250, T2 ~120, T3 ~350, T4 ~40.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Feature doc; ADR 0017 NarrativeFlow model (decisions, schema v2 sketch, M2 scope, deferred list); ADR 0016 "amended by 0017" note; ADR 0010 library note; ADR index | delegated writer (T1–T4, 5+ doc files) | [x] | `2e1df4e` |
| T2 | Glossary and context map | delegated writer | [x] | `fa94d5d` |
| T3 | Roadmap: rows and prompts for 6c, 7, 8, 8b, 8c, 9, 9b, 10–12 notes, 18 (moved), 18b, backlog `play-campaign-transfer`, M2 exit signal | delegated writer | [x] | `bac33a7` |
| T4 | Vision open questions: flow model resolved by ADR 0017; new questions with "decide when" | delegated writer | [x] | this commit |

## Acceptance criteria

- ADR 0017 records all 13 decisions, the schema v2 shape, what M2 implements and what is deferred.
- Glossary, context map, roadmap and vision use the same terms as ADR 0017.
- Every deferred item has a "decide when" in the vision or a roadmap feature.

## Checks

- Passive documentation: no runnable RED. Structural readback of each file; relative links resolve.

## Progress

- Brainstorm done in session; deliverables approved as proposed. Issue #35, branch created.
- T1: ADR 0017 `docs/adr/0017-narrativeflow-model.md` records decisions 1–13, the schema v2 sketch, M2 scope and the deferred list; ADR 0016 status notes the amendment; ADR 0010 notes library content is copied at publish; ADR index row added. Structural readback done.
- T2: glossary adds Phase, Session Zero, Scene Type, Scene selection, World turn, Encounter, Effect, Tracker, Fact slot, Library, Theme, Guidance, Campaign fact; changes NarrativeFlow, Flow step, Scene, FlowRun, Preset; drops Flow preset. Context map: Play and Studio concepts, schema v2 and library notes on the Published Language, Narrative assist input. Structural readback done.
- T3: roadmap strategy row (flow model before flows ship), M2 exit signal adds the guided sample played as a novice; rows 6c, 8b, 8c, 9b, 18b and backlog `play-campaign-transfer`; feature 7 depends on 6c, 9 on 8c, 18 on 15 and 9b and moves third in M4; prompts for 6c, 7, 8, 8b, 8c, 9, 9b, 18, 18b, transfer; notes in 10–12; 6b marked done. Structural readback done.
- T4: vision resolves the flow-model question (ADR 0017) and adds seven open questions with "decide when" (6c ×4, 8c, 18b, backlog transfer); Narrative-assist "how" names its structured input. Structural readback done.
