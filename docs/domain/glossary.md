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
| SheetTemplate | The definition of a character sheet for a GameSystem: its fields, grouped for layout, and derived values ([ADR 0022](../adr/0022-character-sheets-in-schema-version-2.md)) | Studio |
| Field | One input on a sheet template. Types: `number` (bounded integer), `text`, `list` (strings, e.g. inventory or cyberware) and `track` (a resource track) | Studio |
| Resource track | A `track` field's value `{current, max}`, e.g. hit points; it starts full | Studio (defined), Play (held) |
| DerivedValue | A `derived` sheet field whose formula reads fields, other derived values and trackers. Never stored, computed live (e.g. a modifier) ([ADR 0023](../adr/0023-formula-kernel-and-derived-values.md)) | Studio (defined), Play (computed) |
| Formula | An integer expression over sheet values and trackers (`+ - * /`, `min`, `max`, braced references), evaluated by the Formula kernel ([ADR 0023](../adr/0023-formula-kernel-and-derived-values.md)) | Studio (authored), Play (evaluated) |
| Formula reference | A braced name in a formula: `{key}`, `{key.max}`, `{tracker:key}` or `{check:key}` (read only by a check's band formulas, e.g. `{check:difficulty}`) | Studio (authored), Play (evaluated) |
| Check | A named rule: a dice expression plus an optional formula modifier over sheet values and an optional difficulty, whose total maps to outcome bands ([ADR 0024](../adr/0024-structured-checks-without-scripting.md)) | Studio (defined), Play (performed) |
| Difficulty | A check's player-set target, declared with a label, default and optional range; band formulas read it as `{check:difficulty}` ([ADR 0024](../adr/0024-structured-checks-without-scripting.md)) | Studio (declared), Play (set at roll time) |
| Situational modifier | The player's adjustment from −100 to 100 added to a check total at roll time (default 0) | Play |
| Outcome band | A range of results with a meaning (e.g. "miss", "weak hit", "strong hit"). Bands are ordered upper bounds (`upTo`, a literal, a tracker reference or a formula); the last band catches the rest. Checks name their bands with a key and a label. Used by checks, `roll` and `condition` steps and table entries ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| NarrativeFlow | A guided procedure of play for a GameSystem: ordered Phases of Scenes picked from Scene Types. A release has zero or more flows ([ADR 0017](../adr/0017-narrativeflow-model.md)) | Studio |
| Phase | An ordered part of a NarrativeFlow (e.g. Session Zero, Adventure, Epilogue) that runs `once` or `loop`s. It has a scene selection rule, hooks and an optional act label | Studio |
| Act | An optional label that groups phases (e.g. "Act 2: The big job"). Not a level of the flow ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Hook | Steps a phase runs at a boundary: session opening and closing, phase opening and closing, scene opening and closing, and the world turn. Except scene opening and closing, a hook is recorded in Play as a Scene of kind `hook` ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio (defined), Play (recorded) |
| Session Zero | By convention, a flow's first phase: character creation and worldbuilding | Studio |
| Scene Type | A reusable kind of scene declared by a release (e.g. Social, Exploration), with a purpose, tips, `setup` / `play` / `closing` steps and oracle shortcuts | Studio (defined), Play (a Scene has one) |
| Scene selection | A phase's rule for picking the next Scene Type: `sequence`, `player` or `oracle` | Studio |
| World turn | A hook between scenes where the world moves: NPC agendas, clocks, encounters. It colours, pressures or forces the next scene. Recorded in Play as a Scene of kind `hook` (`worldTurn`). Avoid "Interlude" | Studio (defined), Play (recorded) |
| Encounter | An oracle table entry describing an unplanned event, usually rolled in a world turn. Not its own entity | Studio |
| Flow step | One step of a Scene Type part or a phase hook. Kinds: `prompt`, `oracle`, `table`, `roll`, `choice`, `pick`, `condition`, `character`, `check`. `roll` and `condition` branch on outcome bands, `table` per rolled entry, `oracle` on its answer. **Suggested** (the player may skip it) by default, or **mandatory** (a hard gate) | Studio |
| Pick step | A flow step (`pick`) that picks open NPCs or Threads carrying all its tags: `random` (the server draws, weighted) or `choose` (the player selects between `min` and `max`). The outcome is `found` or `none`; fewer candidates than `min` is "No match". Journal entry kind `pick` | Studio (defined), Play (played) |
| `{picked}` | Placeholder for the names of the items most recently picked or created in the current Scene; empty before any pick and when a new Scene starts | Studio (defined), Play (read) |
| Condition step | A flow step that compares a tracker or a count (tagged NPCs or Characters, open Threads) to outcome bands. No dice, no journal entry; it advances on its own ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Effect | A typed change a step outcome or an oracle table entry applies. Kinds: `tracker` (add or set; the value may be a formula), `nextScene`, `switchSceneType`, `endPhase`, `sceneTitle`, `createNpc`, `createThread`, `closeThread`, `fillFact`; later sheet-field effects ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio |
| Tracker | A campaign number declared by the release: a `counter` (e.g. chaos factor) or a `clock` (segments), with an optional `hint`. A counter may name its `levels` (e.g. lifestyle "Kibble / Prepak"). Its value lives on the Campaign | Studio (declared), Play (value) |
| Fact slot | A named, typed place for a Campaign fact, declared by the release: `text`, `npc` or `thread`, with an optional `hint` | Studio |
| `fillFact` | Effect that fills a Fact slot: a `text` slot with the effect's `text` (default `{answer}`), an `npc` or `thread` slot with the first item of that kind in `{picked}`. A filled slot is replaced and keeps its links; an empty text or nothing picked changes nothing | Studio (defined), Play (applied) |
| `{fact:key}` | Placeholder for the value of the Fact slot `key` (its text, or its subject's current name), read when rendered; empty while the slot is unfilled. A key the release does not declare fails | Studio (defined), Play (read) |
| Tag | A label on NPCs, Characters and Threads (e.g. `leader`, `crew`). A release declares tags (key, label, hint); the player may add free tags. `pick` steps and `condition` counts filter by tags. Never "role" ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) | Studio (declared), Play (applied) |
| Library | Studio-only generic content (oracles, Scene Types, trackers, fact slots), copied into a release at publish. A GameSystem overrides a library item by key | Studio |
| Theme | One of five app-defined visual themes a release may name with its optional `theme`: `parchment`, `neon`, `noir`, `eldritch`, `starship`. Each has a fixed palette, heading font and color mode (`parchment` and `starship` light, the others dark), and inside a Campaign pinned to such a release it overrides the player's Light / Dark / System choice. A release without one uses the default app theme | Studio → Play |
| Credits | The optional notice, license and link a release carries in `gameSystem.credits` ([ADR 0021](../adr/0021-release-credits-for-fan-presets.md)). Fan presets put the publisher's disclaimer in the notice. Play shows it where a game system is chosen and on the campaign page | Studio (published), Play (shown) |
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
| Scene cast | The NPCs present and the Characters the player controls in a Scene. NPCs picked or created by a flow join it; the player edits the NPC part of the current Scene's cast (any NPC, open or closed); past scenes are read-only. The Character part comes with feature 10 (slice 4 of `play-characters`) | Play |
| Guidance | Whether a campaign follows a NarrativeFlow. "Play freely" means no FlowRun; turning guidance off pauses the FlowRun | Play |
| FlowRun | A campaign's progress through its NarrativeFlow: current phase, scene, part and step, and a history that includes skips, tracker edits and Scene Type switches. It can be paused, and is `completed` after its last phase | Play |
| Focus mode | A Play view of a FlowRun that shows only the current step (or scene pick), the progress breadcrumb, the next step and a compact summary of the current scene, as opposed to the journal view. Each Flow sets its `defaultView` ([ADR 0016](../adr/0016-flow-presentation-journal-with-focus-mode.md), [ADR 0017](../adr/0017-narrativeflow-model.md)) | Play |
| JournalEntry | An immutable record in a campaign's journal, recorded in the current scene. Kinds: `note` (written text), `roll` (a dice roll result), `oracle-table` (an oracle table result), `likelihood` (a likelihood oracle answer) | Play |
| Thread | An open story line or goal the player tracks, `open` or `closed`, with a weight (1–3, as Mythic lists it up to three times). It may carry release tags and free tags and fill a `thread` fact slot (e.g. a sandbox ambition). Flows create and close Threads; the player adds, edits, closes and reopens them | Play |
| NPC | A non-player character the player tracks in a campaign, `open` or `closed`, with a weight (1–3). It may have an agenda, release tags and free tags (e.g. a faction `leader`). Flows create NPCs; the player adds, edits, closes and reopens them | Play |
| Campaign fact | A statement about a campaign's world (e.g. "The Prince is Mithras"). It fills a fact slot or is free, and may link to NPCs, Threads or other facts | Play |
| Free fact | A Campaign fact the player writes with a label and a text, outside any Fact slot. It may be edited, and linked like any fact | Play |
| Fact link | A reference from a Campaign fact to an NPC, a Thread or another fact, by id (so a rename shows everywhere). At most 20 per fact, each once, never the fact itself | Play |

## Randomness

| Term | Definition | Context |
|---|---|---|
| DiceExpression | A parsed dice notation such as `2d6+1` or `1d100`, evaluable to a roll | Randomness |
| Roll | The result of evaluating a dice expression: individual dice and total | Randomness |
| OracleTable | A table that maps a roll to an answer (e.g. a d100 table of events) | Randomness |
| Likelihood oracle | An oracle that answers a yes/no question given a likelihood (e.g. "unlikely"), possibly with qualifiers ("yes, and…") | Randomness |
| Random event | An unexpected turn from the random event focus table, triggered by an interrupted scene or by a fate-question double whose digit is at most the chaos factor (a likelihood oracle's `randomEvent` rule, [ADR 0019](../adr/0019-random-events-from-fate-question-doubles.md)) | Randomness, Play |

## Architecture terms

| Term | Definition | Context |
|---|---|---|
| Published Language | The shared, versioned contract Studio publishes (a GameSystem release); see the [release contract](../contracts/gamesystem-release.md) | Studio → Play |
| Anti-corruption layer (ACL) | Play's translation from a release snapshot into Play's own model | Play |
| GameSystemSnapshot | Play's own model of a GameSystem release, produced by the anti-corruption layer. Holds no Studio types | Play |
| Shared kernel | Code shared by agreement between contexts; here, Randomness and Formula | Randomness |
| Narrative assist | A port for AI-assisted ideas during play; implementation later | Play |
