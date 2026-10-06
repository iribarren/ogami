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
| Outcome band | A range of check results with a meaning (e.g. "miss", "weak hit", "strong hit") | Studio |
| NarrativeFlow | The configurable procedure of play for a GameSystem: an ordered or branching set of flow steps | Studio |
| Flow step | One step of a narrative flow (e.g. set the scene, ask the oracle, make a check, write a journal entry) | Studio |
| Flow preset | A ready-made narrative flow (Mythic-style, free journal) usable for generic or unsupported systems | Studio |
| Preset | A hand-authored GameSystem release file shipped with Ogami (e.g. Free journal, Mythic-style) and published by `make presets`. A preset may carry a flow preset, but it is a whole release: oracles, flow, sheet and checks | Studio |
| Schema version | The version of the GameSystem release contract a release file follows (`schemaVersion`). Distinct from the release version | Studio → Play |
| Oracle | Anything that answers a question with uncertainty: an oracle table or a likelihood oracle | Studio (defined), Randomness (resolved) |

## Play

| Term | Definition | Context |
|---|---|---|
| Campaign | A solo player's ongoing game, pinned to one GameSystem release. Only its owner sees it | Play |
| Pinned release | The GameSystem release a campaign uses: the latest release of the chosen GameSystem when the campaign is created, stored as key and release version. Newer releases never change it ([ADR 0014](../adr/0014-campaigns-pinned-to-their-release.md)) | Play |
| Character | A sheet instance: a sheet template filled in for one campaign | Play |
| Session | One sitting of play within a campaign, numbered from 1. The latest session is the current session | Play |
| Scene | A unit of story within a session, with a title, numbered from 1 within its session. The latest scene of the current session is the current scene | Play |
| FlowRun | The live progress of a campaign through its narrative flow: current step and history | Play |
| JournalEntry | An immutable record in a campaign's journal, recorded in the current scene. Kinds: `note` (written text), `roll` (a dice roll result), `oracle-table` (an oracle table result), `likelihood` (a likelihood oracle answer) | Play |
| Thread | An open story line or goal the player tracks | Play |
| NPC | A non-player character the player tracks in a campaign | Play |

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
