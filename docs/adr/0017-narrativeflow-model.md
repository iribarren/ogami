# 0017. The NarrativeFlow model

- **Status:** Accepted. Amended by [ADR 0018](0018-narrativeflow-control-flow-and-cast.md): control flow (typed effects, `condition` steps, bands, in-place switches), session and phase hooks with Scene kind `hook`, tags, and the Scene cast and Session party
- **Date:** 2026-10-08

## Context

The current plan suits solo players who already know TTRPGs and solo play. Novice players need **guided flows** that a game manager authors for a specific game:
- a Session Zero for character creation and worldbuilding;
- sequences of scene types (e.g. Social → Exploration);
- suggested or mandatory oracle rolls per scene;
- world changes between player scenes (NPCs acting, encounters).

The solo player may choose such a flow or play freely.

Some content is generic and some belongs to one game system. A netrunning scene makes sense in Cyberpunk RED but not in Call of Cthulhu. A name table differs between a fantasy setting and a samurai setting.

Today the release's `flow` is a flat `{steps: [{key, title, prompt?}]}`, and both presets leave it empty, so its shape can still change for free. Once `play-flow-run` publishes flows with steps, campaigns pin those releases for good ([ADR 0014](0014-campaigns-pinned-to-their-release.md)), and Play's anti-corruption layer must support that shape forever.

Feature 6b `flow-model-brainstorm` settled the model in one session, topic by topic. This ADR records the outcome. The Studio editor UX is out of scope.

## Decision

### 1. Structure: phases, scene types, steps

```
Flow
 └─ Phases (ordered, e.g. session-zero → adventure → epilogue)
     ├─ mode: once | loop
     ├─ selection: how the next Scene Type is picked
     ├─ sceneOpening / sceneClosing: steps that wrap every scene of the phase
     ├─ worldTurn: steps between scenes
     └─ Scene Types allowed in the phase
         └─ parts: setup → play → closing
             └─ Steps (branches only inside a part, forward-only)
```

- **A Scene in Play is an instance of a Scene Type.** Phases are not Sessions: Session Zero may take two sittings, and an adventure loop spans many.
- **Branches stay inside a part and only go forward**, so a scene always ends. Repetition happens only through a `loop` phase.
- **A FlowRun's position is small and stable:** phase, scene, Scene Type, part, step.
- **Scene selection is a per-phase rule** set by the game manager:

  | Rule | Meaning |
  |---|---|
  | `sequence` | Scene Types in a fixed order (e.g. Character → World → Opening scene) |
  | `player` | The player picks one of the phase's Scene Types |
  | `oracle` | An oracle table whose entries point to Scene Types picks it |

- **A `loop` phase ends when the player chooses to move on**, at a scene boundary. Ending on a tracker condition is left to feature 6c.

### 2. Scene Type

- A Scene Type has a key, name, one-line **purpose**, optional **tips**, three step lists (`setup`, `play`, `closing`) and **oracle shortcuts**. The shortcuts must be a subset of the flow's oracles (decision 12).
- **The `play` part is guided, then open.** Its steps run in order. Then the scene stays open for free play (notes, oracles, rolls) until the player chooses "End scene", which starts `closing`.
- **Phase hooks wrap every scene:** `sceneOpening → setup → play → closing → sceneClosing`.
- **Mythic's scene check is not a special concept.** It is an ordinary `roll` step in `sceneOpening` with outcome bands, and a band bound may reference a tracker: "1d10 ≤ chaos → twist" branches to a table step "altered / interrupted". The same mechanism covers checks like "1d6, on 1 → encounter".

### 3. Mandatory and suggested steps

- Steps are **suggested** by default: the card shows Skip, and a skip follows the step's default `next`.
- A **mandatory** step is a hard gate: there is no Skip, and the flow waits. Free notes, oracles and rolls stay available beside it.
- A branching step is either mandatory or names the branch a skip follows.
- **Skips are recorded in the FlowRun history, not in the journal**, because the journal is the story.
- **Turning guidance off** (decision 7) is the escape hatch, so a gate never traps the player.

### 4. World turns

- A phase may have a `worldTurn` step list. It runs after `sceneClosing` and before the next scene is picked. Conditions use the same roll-with-bands branching.
- **World-turn results are recorded in a Scene of their own**, of kind `world-turn`. This keeps the invariant that every journal entry belongs to a Scene.
- **Encounters are oracle tables**, not an entity. A table entry may point to a Scene Type, so an encounter can become the next scene through the `oracle` selection rule.
- **NPC agendas:** a step picks an NPC and prompts what they do toward their `agenda`.
- **Faction clocks:** clocks are trackers (decision 5).
- **Threads advancing:** a prompt on a picked Thread.

### 5. Trackers

- A **tracker** is a campaign number declared **at release level**:
  - `counter`: min, max and initial value, e.g. a chaos factor 1–9 starting at 5;
  - `clock`: a number of segments, e.g. "The ritual" 0/6.
- Each flow lists the trackers it uses. **Values live on the Campaign**, so they survive turning guidance off, and free play still has a chaos factor.
- Step outcomes (a choice option, a roll band) carry **effects**: `{tracker, add | set, value}`.
- A band bound or an effect value is a **literal or a tracker reference**. There is no formula language in M2.
- A likelihood oracle's chaos input is bound to a tracker instead of being typed by hand.
- The player, who is also the GM, can edit a tracker by hand. The edit is recorded in the FlowRun history.
- Feature 11's formula language must be able to read trackers. A tracker reference is a trivial formula, so M2 releases need no schema break when formulas arrive.

### 6. Session Zero and Campaign facts

- **Session Zero** is, by convention, a flow's first phase. In M2 a "Character" Scene Type uses prompt and table steps. M3 adds a `character` step kind that completes when a Character exists from the sheet template.
- **Campaign facts** hold worldbuilding output so it does not get buried in the journal:
  - A fact has a label and text.
  - It may fill a **fact slot** declared by the release. Slots are typed `text`, `npc` or `thread`. An `npc` slot is filled by picking or creating an NPC.
  - The player may also add **free facts**, with no slot.
  - Any fact may **link** to NPCs, Threads or other facts by reference. A rename shows everywhere.
  - Facts are shown in a panel beside the journal, and the player can edit them. Prompts can use `{slot}` placeholders.
- Example: "The Prince is Mithras" is slot `prince` (`npc`) → NPC Mithras. "The Prince is a Ventrue" is a free fact linked to Mithras.

### 7. Several flows per release, guidance and tips

- A release has **`flows: [...]`**, zero or more. Examples: a quick one-shot, a campaign with Session Zero.
- **Tip text** sits at three levels: flow `introduction`, Scene Type `tips`, and step `tip`.
- **Free play is not a flow.** "Play freely" means the campaign has no FlowRun.
- **Creating a campaign:** the player picks one of the pinned release's flows, or "Play freely". The release may mark a default flow.
- **Mid-campaign the flow is fixed.** Turning guidance off pauses the FlowRun, which resumes where it stopped. Switching to another flow is not supported.
- Starting a new campaign with another flow while transferring facts, NPCs and Threads is a backlog item.

### 8. Presentation (amends ADR 0016)

- Each flow sets **`defaultView: focus | journal`**. The player can toggle at any time, and the choice is remembered per campaign.
- **Scene-type cards** depend on the selection rule:
  - `player`: one card per allowed Scene Type;
  - `sequence`: "Next: …";
  - `oracle`: the rolled card, recorded as a journal entry.

  The scene pick is a mandatory step.
- **The next step is always named, also across boundaries**, e.g. "Closing: What changed?", "World turn", "Next scene: choose a scene type", "Session Zero complete → Adventure".
- Scene headers show their Scene Type and purpose. World-turn scenes render as a distinct "The world moves" block.
- Skips appear only in focus mode's summary.
- Focus mode progress reads `Phase › Scene type › part · step n/m`.

### 9. Studio

- Play first still holds: hand-authored presets prove the model before an editor exists.
- The flow editor is a **list/outline editor**, so no node-graph library is needed ([ADR 0004](0004-react-vite-spa-ui-toolkit.md)).
- Scene Types and trackers are declared at release level and reused by every flow of the GameSystem.
- M4 order: drafts → oracle editor → **flow editor** → sheet builder → check editor → library.

### 10. Ubiquitous language

New terms are Phase, Session Zero, Scene Type, Scene selection, World turn (not "Interlude"), Encounter, mandatory and suggested step, Effect, Tracker, Campaign fact, Fact slot, Guidance, Library and Theme. NarrativeFlow, Flow step, FlowRun and Scene change meaning, and "Flow preset" is dropped. See the [glossary](../domain/glossary.md).

### 11. Narrative assist (note only)

The model gives the port structured input: Scene Type and purpose, the current step, Campaign facts, NPC agendas, open Threads, tracker values and recent journal entries. Nothing is designed until AI enters scope.

### 12. Generic and game-specific content

- Oracle tables, likelihood oracles, Scene Types, tracker definitions and fact slots can be generic. Generic flows are deferred.
- Generic content lives in a **Studio-only Library** (M4), authored by any game manager.
- Library items are imported into a GameSystem draft and **copied into the release at publish**. Releases stay self-contained and immutable ([ADR 0010](0010-versioned-gamesystem-releases.md)).
- Pinning is unaffected ([ADR 0014](0014-campaigns-pinned-to-their-release.md)). A library change reaches a GameSystem only when it is republished, and a campaign only through an upgrade.
- **Overrides go by key.** A generic Scene Type refers to `npc-name`, and a samurai GameSystem supplies its own `npc-name` table. At publish, every referenced key must resolve inside the release.
- The release schema has **no library concept**. An optional provenance field can be added later without a break.
- Each flow selects the **`oracles[]` and `trackers[]`** it makes available. Play's oracle panel shows, in order:
  1. the scene's shortcuts;
  2. the flow's selection;
  3. the release's other oracles, behind a collapsed **"More oracles"** section.

### 13. Visual design

- A `design-foundation` feature comes after the features that add NPCs, Threads and Campaign facts, and before the M2 playtest presets. By then every Play region exists.
- A release may name one **curated theme** (e.g. `parchment`, `neon`, `noir`). Themes are app-defined and contrast-checked.
- The optional `theme` field is added by `design-foundation`, not by the schema change below.

### Release schema version 2

`play-flow-run` replaces `flow` with the following, as release schema version 2 (names are indicative; the contract is written in that feature):

```
trackers[]   {key, name, kind: counter|clock, min/max/initial | segments}
factSlots[]  {key, label, type: text|npc|thread}
sceneTypes[] {key, name, purpose, tips?, oracles[], setup[], play[], closing[]}
flows[]      {key, name, description?, introduction?, default?, defaultView: focus|journal,
              oracles[], trackers[],
              phases[] {key, name, mode: once|loop,
                        selection {rule: sequence|player|oracle, sceneTypes[] | table},
                        sceneOpening[], sceneClosing[], worldTurn[]}}
step         {key, kind, title, prompt?, tip?, mandatory?, kind fields, next | branches, effects?}
oracle table entry: + optional sceneType
```

- **M2 step kinds:** `prompt`, `oracle` (likelihood question), `table`, `roll` (dice, optional outcome bands), `choice`, `pick` (Thread or NPC).
- **M3 step kinds:** `character`, `check`. They arrive as later schema versions ([ADR 0013](0013-studio-is-a-core-context.md)).
- **Schema version 1 releases stay readable.** The anti-corruption layer maps their empty `flow` to "no flows".

### What M2 implements

| Feature | Part of the model |
|---|---|
| 7 `play-flow-run` | Schema version 2, validation and the anti-corruption layer; FlowRun across phases, scenes and parts; M2 step kinds; mandatory and suggested steps; trackers and effects; Scene Types and world-turn scenes; flow choice at campaign creation; guidance pause and resume; `defaultView` and focus mode |
| 8 `play-threads-npcs` | NPC `agenda`, the `pick` step, effects that create an NPC or a Thread |
| 8b `play-campaign-facts` | Fact slots, free facts, links, the facts panel, `{slot}` placeholders |
| 8c `design-foundation` | Design tokens, Play layout, curated themes |
| 9 `preset-mythic-flow` | The Mythic-style flow on this model |
| 9b `preset-guided-sample` | A guided novice flow that proves the model without Studio |

### Deferred

| Item | Decided in |
|---|---|
| How a world turn affects the next scene, and the consequences of ignoring it | 6c `flow-model-examples` |
| How a `loop` phase ends beyond the player's choice | 6c `flow-model-examples` |
| An interruption that switches to another Scene Type mid-scene | 6c `flow-model-examples` |
| NPC disposition, factions, thread progress | When a preset needs them, starting at 6c |
| `character` and `check` step kinds | 10 `play-characters`, 12 `play-checks` |
| The curated theme set | 8c `design-foundation` |
| Studio library and provenance | 18b `studio-library` |
| Generic flows | 18b `studio-library` |
| Moving play to another flow | Backlog `play-campaign-transfer` |
| Journal mentions and backlinks | Later; facts cover them for now |

## Consequences

- The release contract gets a schema version 2 before any flow with steps is published, so no campaign ever pins the flat `{steps: []}` shape with content.
- Play's model grows: FlowRun, Scene kind and Scene Type, tracker values and Campaign facts on the Campaign.
- The hierarchy keeps FlowRun state small and keeps a list editor possible in Studio.
- Generic content never crosses the Studio → Play boundary as a separate artifact. Releases duplicate it, which is acceptable at this scale ([ADR 0010](0010-versioned-gamesystem-releases.md)).
- Two new M2 features (8b, 8c) and two presets (9, 9b) extend M2, so the MVP arrives later.
- [ADR 0016](0016-flow-presentation-journal-with-focus-mode.md) is amended: each flow sets its default view.
