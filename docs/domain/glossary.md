# Glossary

The ubiquitous language of Ogami. Use these terms, spelled this way, in code, tests (Gherkin), docs and UI. Context names follow the [context map](context-map.md).

## Product areas and roles

| Term | Definition | Context |
|---|---|---|
| Play | The product area where a solo player runs campaigns | Play |
| Studio | The product area where game managers author game systems. Never call it "backoffice" or "admin" | Studio |
| Admin | The product area where the owner manages users and app settings | Admin |
| `SOLO_PLAYER` | Role that plays campaigns in Play | Identity & Access |
| `GAME_MANAGER` | Role that authors game content in Studio. Game and oracle topics only | Identity & Access |
| `OWNER` | Role that manages the app in Admin: users, roles, settings | Identity & Access |
| User | A person with an account and one or more roles | Identity & Access |

## Game authoring

| Term | Definition | Context |
|---|---|---|
| GameSystem | A TTRPG system as authored in Studio: its sheet templates, checks, narrative flow and oracles. Mutable draft | Studio |
| GameSystem release | An immutable, versioned snapshot of a GameSystem, published by a game manager. The only form Play consumes. Its release version counts up per GameSystem key (1, 2, …) | Studio (published), Play (snapshot) |
| SheetTemplate | The definition of a character sheet for a GameSystem: its fields, layout and derived values | Studio |
| Field | One input on a sheet template (e.g. a number, text, choice, track) | Studio |
| DerivedValue | A sheet value computed by a formula from fields or other derived values (e.g. a modifier) | Studio |
| Check | A named rule that rolls a dice expression against sheet values and maps the result to outcome bands | Studio (defined), Play (performed) |
| Outcome band | A range of results with a meaning (e.g. "miss", "weak hit", "strong hit"). Bands are ordered upper bounds (`upTo`, a literal or a tracker reference); the last band catches the rest. Used by checks, `roll` and `condition` steps and table entries ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| NarrativeFlow | A guided procedure of play for a GameSystem: ordered Phases of Scenes picked from Scene Types. A release has zero or more flows ([ADR 0017](../adr/0017-narrativeflow-model.md)) | Studio |
| Phase | An ordered part of a NarrativeFlow (e.g. Session Zero, Adventure, Epilogue) that runs `once` or `loop`s. It has a scene selection rule, hooks and an optional act label | Studio |
| Act | An optional label that groups phases (e.g. "Act 2: The big job"). Not a level of the flow ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Hook | Steps a phase runs at a boundary: session opening and closing, phase opening and closing, scene opening and closing, and the world turn. Except scene opening and closing, a hook is recorded in Play as a Scene of kind `hook` ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio (defined), Play (recorded) |
| Session Zero | By convention, a flow's first phase: character creation and worldbuilding | Studio |
| Scene Type | A reusable kind of scene declared by a release (e.g. Social, Exploration), with a purpose, tips, `setup` / `play` / `closing` steps and oracle shortcuts | Studio (defined), Play (a Scene has one) |
| Scene selection | A phase's rule for picking the next Scene Type: `sequence`, `player` or `oracle` | Studio |
| World turn | A hook between scenes where the world moves: NPC agendas, clocks, encounters. It colours, pressures or forces the next scene. Recorded in Play as a Scene of kind `hook` (`worldTurn`). Avoid "Interlude" | Studio (defined), Play (recorded) |
| Encounter | An oracle table entry describing an unplanned event, usually rolled in a world turn. Not its own entity | Studio |
| Flow step | One step of a Scene Type part or a phase hook. Kinds: `prompt`, `oracle`, `table`, `roll`, `choice`, `pick`, `condition` (M3 adds `character` and `check`). `roll` and `condition` branch on outcome bands, `table` per rolled entry, `oracle` on its answer. **Suggested** (the player may skip it) by default, or **mandatory** (a hard gate) | Studio |
| Condition step | A flow step that compares a tracker or a count (tagged NPCs or Characters, open Threads) to outcome bands. No dice, no journal entry; it advances on its own ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Effect | A typed change a step outcome or an oracle table entry applies. Kinds: `tracker` (add or set), `nextScene`, `switchSceneType`, `endPhase`, `sceneTitle`; later `createNpc`, `createThread`, `closeThread`, `fillFact` and sheet-field effects ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Tracker | A campaign number declared by the release: a `counter` (e.g. chaos factor) or a `clock` (segments), with an optional `hint`. A counter may name its `levels` (e.g. lifestyle "Kibble / Prepak"). Its value lives on the Campaign | Studio (declared), Play (value) |
| Fact slot | A named, typed place for a Campaign fact, declared by the release: `text`, `npc` or `thread`, with an optional `hint` | Studio |
| Tag | A label on NPCs, Characters and Threads (e.g. `leader`, `crew`). A release declares tags (key, label, hint); the player may add free tags. `pick` steps and `condition` counts filter by tags. Never "role" ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio (declared), Play (applied) |
| Library | Studio-only generic content (oracles, Scene Types, trackers, fact slots), copied into a release at publish. A GameSystem overrides a library item by key | Studio |
| Theme | One of a few app-defined visual themes a release may name (e.g. `parchment`, `neon`) | Studio → Play |
| Preset | A hand-authored GameSystem release file shipped with Ogami (e.g. Free journal, Mythic-style) and published by `make presets`. A preset is a whole release: oracles, Scene Types, trackers, flows, sheet and checks | Studio |
| Schema version | The version of the GameSystem release contract a release file follows (`schemaVersion`). Distinct from the release version | Studio → Play |
| Oracle | Anything that answers a question with uncertainty: an oracle table or a likelihood oracle | Studio (defined), Randomness (resolved) |

## Play

| Term | Definition | Context |
|---|---|---|
| Campaign | A solo player's ongoing game, pinned to one GameSystem release. Only its owner sees it | Play |
| Pinned release | The GameSystem release a campaign uses: the latest release of the chosen GameSystem when the campaign is created, stored as key and release version. Newer releases never change it ([ADR 0014](../adr/0014-campaigns-pinned-to-their-release.md)) | Play |
| Character | A sheet instance: a sheet template filled in for one campaign. A Campaign has zero or more; an NPC can be promoted to a Character | Play |
| Session | One real-world sitting of play within a campaign, numbered from 1; not an in-story night or day. It has a party and ends with "End session", offered between scenes while guided. The latest session is the current session | Play |
| Party | A Session's party: the Characters picked in a session opening. A Scene's cast starts as the party | Play |
| Scene | A unit of story within a session, with a title, numbered from 1 within its session. Its kind is `scene` or `hook` (with the hook name); in a guided flow it has a Scene Type, which an interruption may switch in place. It has a cast. The latest scene of the current session is the current scene | Play |
| Scene cast | The NPCs present and the Characters the player controls in a Scene. Picks add to it; the player edits it | Play |
| Guidance | Whether a campaign follows a NarrativeFlow. "Play freely" means no FlowRun; turning guidance off pauses the FlowRun | Play |
| FlowRun | A campaign's progress through its NarrativeFlow: current phase, scene, part and step, and a history that includes skips, tracker edits and Scene Type switches. It can be paused, and is `completed` after its last phase | Play |
| JournalEntry | An immutable record in a campaign's journal, recorded in the current scene. Kinds: `note` (written text), `roll` (a dice roll result), `oracle-table` (an oracle table result), `likelihood` (a likelihood oracle answer) | Play |
| Thread | An open story line or goal the player tracks. It may carry tags and fill a `thread` fact slot (e.g. a sandbox ambition) | Play |
| NPC | A non-player character the player tracks in a campaign. It may have an agenda and tags (e.g. a faction `leader`) | Play |
| Campaign fact | A statement about a campaign's world (e.g. "The Prince is Mithras"). It fills a fact slot or is free, and may link to NPCs, Threads or other facts | Play |

## Randomness

| Term | Definition | Context |
|---|---|---|
| DiceExpression | A parsed dice notation such as `2d6+1` or `1d100`, evaluable to a roll | Randomness |
| Roll | The result of evaluating a dice expression: individual dice and total | Randomness |
| OracleTable | A table that maps a roll to an answer (e.g. a d100 table of events) | Randomness |
| Likelihood oracle | An oracle that answers a yes/no question given a likelihood (e.g. "unlikely"), possibly with qualifiers ("yes, and…") | Randomness |

## Architecture terms

| Term | Definition | Context |
|---|---|---|
| Published Language | The shared, versioned contract Studio publishes (a GameSystem release); see the [release contract](../contracts/gamesystem-release.md) | Studio → Play |
| Anti-corruption layer (ACL) | Play's translation from a release snapshot into Play's own model | Play |
| GameSystemSnapshot | Play's own model of a GameSystem release, produced by the anti-corruption layer. Holds no Studio types | Play |
| Shared kernel | Code shared by agreement between contexts; here, Randomness | Randomness |
| Narrative assist | A port for AI-assisted ideas during play; implementation later | Play |
