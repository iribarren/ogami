# 0018. NarrativeFlow control flow and cast

- **Status:** Accepted
- **Date:** 2026-10-09

## Context

[ADR 0017](0017-narrativeflow-model.md) settled the NarrativeFlow model and left four questions to feature 6c `flow-model-examples`:
- how a world turn affects the next scene;
- how a `loop` phase ends beyond the player's choice;
- whether an interruption can switch a scene to another Scene Type;
- whether NPC disposition, factions and thread progress need fields.

The 6c prompt added four more: sandbox goals, acts, several player characters per campaign, and any other gap in step kinds, effects or placeholders.

Feature 6c mapped five real play examples to the model, one at a time, as a game manager would author them and a solo player would play them. The worked examples are in [Flow examples](../domain/flow-examples.md):

1. A Vampire: the Masquerade chronicle (sandbox, Session Zero facts, ambitions into goals).
2. A Cyberpunk RED heist one-shot (generic and game-specific Scene Types, a clock).
3. A Mythic-style session (chaos, scene check, altered and interrupted scenes, list updates).
4. A Cyberpunk RED long campaign in acts (gigs, rep, three acts).
5. A West Marches fantasy campaign (roster, party per session, expeditions).

Campaigns pin their release for good ([ADR 0014](0014-campaigns-pinned-to-their-release.md)). Every shape change that is not additive must therefore be in release schema version 2, before feature 7 `play-flow-run` publishes any flow with steps.

This ADR amends ADR 0017. Decision numbers match the [feature doc](../../odd/tasks/flow-model-examples.md).

## Decision

### The eight 6c questions

| # | Question | Answer | Decisions |
|---|---|---|---|
| 1 | How does a world turn affect the next scene? | Three authored strengths, no new concept: colour (quoted through placeholders, ignorable), pressure (a clock or Thread fills if ignored; a `condition` fires the consequence) and forced (`nextScene`) | 1, 3, 7 |
| 2 | How does a `loop` phase end? | By the player's choice (always available) or by a `condition` step plus an `endPhase` effect | 3, 8 |
| 3 | Can an interruption switch the Scene Type? | Yes, in place, through a `switchSceneType` effect; at most one switch per scene through effects | 1, 4, 5, 6 |
| 4 | Do NPC disposition, factions or thread progress need fields? | No. Factions are Campaign facts plus a tagged leader NPC with an agenda; disposition and boons are facts linked to NPCs. Thread progress is an open question | 15, 20 |
| 5 | How does a sandbox player set goals? | Threads in `thread` fact slots (`ambition` long-term, `desire` refilled each session), driven by session hooks | 12, 21 |
| 6 | Acts: a phase, a new level, or deferred? | Acts are phases with an optional `act` label. No new level | 19 |
| 7 | Several player characters per campaign? | Yes, in the model now: zero or more Characters per Campaign, a Session party, a Scene cast, NPC promotion. Built in feature 10 | 22, 23, 24 |
| 8 | Other gaps? | A `condition` step; table and oracle branching; effects on oracle table entries; namespaced placeholders; a `completed` FlowRun; single-type auto-pick and scene titles; session and phase hooks; Session as a sitting; tags and `pick`; hints; counter levels; an M2 economy | 2–5, 9–18, 25 |

### Control flow (schema version 2, feature 7)

1. **Effects are a tagged union:** `tracker` (add | set), `nextScene`, `switchSceneType`, `endPhase`, `sceneTitle`. Later kinds: `createNpc`, `createThread`, `closeThread` (8), `fillFact` (8b), sheet-field effects (an M3 schema version).
2. **Outcome bands are ordered upper bounds.** Each band has an `upTo`, a literal or a tracker reference; the last band catches the rest. No arithmetic is ever needed. Bands apply to `roll`, `condition` and table entries.
3. **Step kind `condition`** compares a tracker (7) or a count (8: NPCs or Characters with a tag, open Threads) to bands. It rolls no dice, writes no journal entry and advances on its own.
   - Compound conditions are chained `condition` steps.
   - A `condition` before a `choice` gates its options, so options need no conditions of their own.
4. **`table` steps branch per rolled entry**, like a roll's bands. **`oracle` steps branch on their answer** (yes, no, exceptional).
5. **Oracle table entries may carry `effects`** beside `sceneType`. They apply when a flow step rolls the entry.
6. **Interruptions switch in place.** `switchSceneType` changes the current Scene's type:
   - the Scene's entries stay;
   - the position jumps to the new type's `setup`, and the scene opening does not run again;
   - the switch is recorded in the FlowRun history;
   - at most one switch per scene through effects.

   In free play the player may switch by hand (recorded). Play offers it when a rolled table entry names a Scene Type.
7. **World turn → next scene** has three authored strengths:

   | Strength | How it is authored | If the player ignores it |
   |---|---|---|
   | Colour | A table or prompt, quoted later through placeholders | Nothing happens |
   | Pressure | A clock or a Thread moves | The clock fills; a `condition` fires the consequence |
   | Forced | A `nextScene` effect | The scene pick offers only that card |

   Authoring rule: a threshold consequence must lower its tracker, or it fires on every turn. Validation warns.
8. **A loop phase ends** by the player's choice, always available, or by `condition` + `endPhase`. There is no separate condition language.
9. **Placeholders are namespaced:** `{tracker:key}`, `{step:key}`, `{answer}` (7), `{picked}` (8), `{fact:key}` (8b). This replaces ADR 0017's `{slot}`.
10. **A FlowRun is `completed`** after its last phase. Play continues unguided.
11. **A phase with one Scene Type picks it automatically**, with no card. A Scene's default title is its Scene Type name, numbered; a `sceneTitle` effect overrides it.

### Hooks

12. Phases gain **`sessionOpening` / `sessionClosing`** and **`phaseOpening` / `phaseClosing`** beside `sceneOpening`, `sceneClosing` and `worldTurn`.
13. **Scene kind is `scene` | `hook`**, with the hook name: `worldTurn`, `sessionOpening`, `sessionClosing`, `phaseOpening` or `phaseClosing`. Each renders its own way, e.g. "The world moves", "Session 3 begins", "Act 2: The big job". This replaces ADR 0017's `world-turn` kind. Scene opening and closing steps run inside their Scene, so they have no hook kind.
14. **A Session is one real-world sitting**, not an in-story night or day. In-story time is authored content, e.g. a `night` counter and a "Dawn" Scene Type. Play gains an **"End session"** action; while guided it is offered only between scenes.

### Content

15. **Tags.** A release declares `tags[] {key, label, hint?}`. Tags apply to NPCs, Characters and Threads, and the player may add free tags. They are not called "roles", which would clash with sheet fields such as Cyberpunk RED's Role.
16. **`pick`** filters by several tags (all must match), with mode `random` | `choose`, a count (`min` / `max`, one by default) and branches `found` / `none`.
17. **Hints.** Trackers (7) and fact slots (8b) have an optional `hint`, shown where the value is shown or filled.
18. **Counters have optional named `levels`**, e.g. lifestyle "Kibble / Prepak / Good prepak / Fresh food".
19. **Acts are phases.** A phase has an optional **`act` label**. Focus mode progress reads `Act › Phase › Scene type › part · step n/m`.
20. **Factions** are Campaign facts plus a tagged leader NPC with an agenda. Disposition and boons are facts linked to NPCs. No new fields.
21. **Sandbox goals** are Threads in `thread` fact slots (`ambition` long-term, `desire` refilled each session), driven by session hooks. No new concept.

### Cast and party

22. **Scene cast:** the NPCs present and the Characters the player controls in a Scene. Picks add to it, and the player edits it. The NPC part arrives in 8, the Character part in 10.
23. **Session party:** the Characters picked in a session opening. A Scene's cast starts as the party (10).
24. **A Campaign has zero or more Characters.** An NPC can be promoted to a Character (10). Checks roll for a Character in the cast (12).

### Economy

25. **Survival economies** (e.g. Cyberpunk RED's rent and lifestyle) use what exists:
    - M2: trackers and release-level Scene Types (Downtime, Night Market, Month's end) with tables and effects.
    - M3: sheet list fields (inventory, cyberware) and effects on sheet fields.
    - Items, inventory and a `shop` step kind are an open question.

### Presets and content

26. **The examples become contract fixtures** in feature 7: schema version 2 JSON that must validate and pass the anti-corruption layer.
27. **9b `preset-guided-sample` becomes a Cyberpunk RED heist** one-shot fan preset (example 2).
28. **Backlog presets:** a VtM night-court sandbox (after 8b), a Cyberpunk RED campaign in acts (after M3 and the economy question), and a West Marches campaign on the 5e SRD or Knave (after 10 and Places).
29. **Fan-content rule.** Ogami is personal and non-commercial, so presets may use fan material under the publishers' fan-content policies or open licenses. The repository is public, so presets carry:
    - no verbatim book text: own words or short summaries, with page references to the books;
    - the publisher's fan-content disclaimer.

    A release credits or license field is an open question, decided when 9b ships.

### Release schema version 2 additions

These extend ADR 0017's sketch. Names are indicative; feature 7 writes the contract.

```
trackers[]   + hint?, levels[]? (counter only)
tags[]       {key, label, hint?}            (feature 8)
factSlots[]  + hint?                        (feature 8b)
phases[]     + act?, sessionOpening[], sessionClosing[], phaseOpening[], phaseClosing[]
step         kinds + condition; table branches per entry; oracle branches on answer
bands        ordered [{upTo: literal | tracker}], last band catches the rest
effects[]    {kind: tracker|nextScene|switchSceneType|endPhase|sceneTitle, …}
             later: createNpc, createThread, closeThread (8), fillFact (8b), sheet fields (M3)
pick         {tags[], mode: random|choose, count {min,max}, branches found|none} (feature 8)
oracle table entry: + optional effects[]
placeholders {tracker:key} {step:key} {answer} (7) · {picked} (8) · {fact:key} (8b)
```

| Must be in schema version 2 (not additive) | May arrive in later, additive schema versions |
|---|---|
| Effects as a tagged union with a `kind`; ADR 0017's `{tracker, add \| set, value}` becomes one kind of it | New effect kinds: `createNpc`, `createThread`, `closeThread` (8), `fillFact` (8b), sheet fields (M3) |
| The band shape: ordered `upTo` bounds, the last band catching the rest | `tags[]` and `pick` (8) |
| The placeholder syntax: namespaced `{ns:key}` | Slot `hint` (8b); new placeholder namespaces in the same syntax |
| Scene kind `scene` \| `hook` with a hook name, so no recorded campaign data uses `world-turn` | M3 step kinds and sheet list fields |

Feature 7 also ships, in schema version 2, the `condition` step kind, table and oracle branching, effects on oracle table entries, the phase hooks and `act` label, and tracker `hint` and `levels`. They are additive in principle, but the five examples need them to run as guided flows.

### Play model changes

| Change | Detail | Feature |
|---|---|---|
| Scene kind | `scene` \| `hook`, with the hook name | 7 |
| Session | One real-world sitting; an "End session" action, offered between scenes while guided | 7 |
| FlowRun | Can be `completed`; records in-place Scene Type switches | 7 |
| Scene title | The Scene Type name, numbered, unless a `sceneTitle` effect sets it | 7 |
| Tags | On NPCs and Threads (8) and Characters (10), plus free tags | 8, 10 |
| Scene cast | NPCs present (8) and Characters controlled (10) | 8, 10 |
| Session party | The Characters picked in a session opening | 10 |
| Characters | Zero or more per Campaign; an NPC can be promoted to a Character | 10 |

### Where it lands

| Feature | Part of this ADR |
|---|---|
| 7 `play-flow-run` | Schema version 2 with decisions 1–14, tracker `hint` (17), counter `levels` (18), the `act` label (19); contract fixtures from the five examples (26); Scene kind `hook`, "End session", FlowRun `completed`, in-place switch, single-type auto-pick, scene titles |
| 8 `play-threads-npcs` | Tags on NPCs and Threads (15); `pick` (16); `createNpc`, `createThread`, `closeThread`; `condition` counts; `{picked}`; the NPC part of the Scene cast (22); tagged faction leaders (20) |
| 8b `play-campaign-facts` | `fillFact`; slot `hint` (17); `{fact:key}`; factions and disposition as facts (20); goals in `thread` slots (21) |
| 9 `preset-mythic-flow` | The Mythic-style flow of example 3; random events from fate-question doubles |
| 9b `preset-guided-sample` | Now a Cyberpunk RED heist one-shot fan preset (27, 29); the release credits or license field |
| 10 `play-characters` | Several Characters per Campaign, NPC promotion (24); the Character part of the Scene cast (22); the Session party (23); tags on Characters (15); sheet list fields (25) |
| 12 `play-checks` | Checks roll for a Character in the cast (24) |
| 13 `preset-real-system` or later | Items, inventory and a `shop` step kind (25); effects on sheet fields arrive in an M3 schema version |
| Backlog | Three fan presets: VtM chronicle, Cyberpunk RED campaign in acts, West Marches (28) |

### Accepted limits

- A scene repeats something (netrun floors, dungeon rooms) only through free play or a `nextScene` chain.
- There is no "for each" over a list; pick one instead.
- Phases only go forward; a failure carries forward.
- A generic Scene Type cannot receive phase-specific steps; author a specific type.
- Scene chains (e.g. a gig) are not shown as a group.
- Every tracker of the flow is shown.
- Odd / even bands are replaced by a second roll.

### Deferred and open questions

| Question | Decide when |
|---|---|
| Reusable procedures (named step lists run from a step or the oracle panel) | When a preset repeats a step list |
| Player-added trackers linked to NPCs, Threads or facts (thread progress, faction power, disposition) | When a preset needs them |
| Places (map, regions, sites) | When a map-focused preset needs them (West Marches) |
| Items, inventory and a `shop` step kind | Feature 13 or a post-M3 feature |
| Random events from fate-question doubles | Feature 9 `preset-mythic-flow` |
| Release credits / license field for fan presets | Feature 9b `preset-guided-sample` |

The [vision](../vision.md#open-questions) tracks them.

## Consequences

- Feature 7 grows: control flow, hooks and the Play changes above. It likely needs one more slice, for control flow.
- ADR 0017's `world-turn` Scene kind becomes the hook `worldTurn`, before any campaign records one.
- NPC roles never exist; tags cover them, so sheet fields such as a Cyberpunk RED Role keep their name.
- The model answers the 6c questions with existing concepts (trackers, facts, Threads, phases) plus a few step kinds, effect kinds and hooks. No new level and no formula language.
- Several Characters per Campaign enter the model now, so feature 10 builds them without a schema break.
- Presets may be fan material under the content rule. Feature 9b becomes a Cyberpunk RED heist, and three fan presets join the backlog.
- The five examples become feature 7's contract fixtures, so the schema is tested against real play from the start.
