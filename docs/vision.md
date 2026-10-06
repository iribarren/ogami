# Vision

Ogami helps a solo player run tabletop RPG campaigns in any game system: a guided narrative flow gives ideas, oracles keep outcomes uncertain, and character sheets and checks follow each system's rules. Game managers author all of that game content in **Studio**, with a UX as rich as Play.

## At a glance

| Question | Answer |
|---|---|
| What | Web app for solo TTRPG play across many game systems |
| For whom | 1 to a few people: solo players, game managers, one owner |
| Core value | A clear, guided play flow that keeps stories uncertain and fun |
| Why build it | Use it to play, and learn the gentle-ai methodology and DDD along the way |

## Problem

Solo TTRPG play needs more than dice. A solo player must juggle:

- the rules and character sheets of the chosen game system;
- oracles and random tables that answer questions no game master is there to answer;
- a play procedure (scene setup, questions, twists, journaling) that keeps the story moving;
- notes on threads, NPCs and past scenes.

Today this means paper, PDFs, spreadsheets and several single-purpose apps. Each system has its own sheets and checks, and generic tools rarely guide the *flow* of play.

## Users and roles

| Role | Works in | Does |
|---|---|---|
| `SOLO_PLAYER` | Play | Creates campaigns and characters, follows the narrative flow, asks oracles, makes checks, writes the journal |
| `GAME_MANAGER` | Studio | Authors game systems: sheet templates, derived values, checks, narrative flows, oracles; publishes releases. Game and oracle topics only |
| `OWNER` | Admin | Manages users, roles and app settings, everything outside game content |

Play and Studio are both shared with other users, so both are product surfaces, not internal tools.

## Goals

1. **Play solo in any supported system** with the right sheets and structured checks.
2. **Guide the story**: a configurable narrative flow that suggests the next step and helps the player find ideas.
3. **Keep outcomes uncertain** with oracles (tables, likelihood questions) and dice.
4. **Author game content without code** in Studio, with rich editors (flow editor, sheet builder, formula/dice editor).
5. **Learn by building**: apply gentle-ai (ODD) and DDD on a real product.

## Non-goals (for now)

| Non-goal | Why |
|---|---|
| Enterprise scaling, multi-region, high availability | Target is 1 to a few users; features and usability come first |
| Multiplayer or shared live sessions | The product is about solo play |
| Player-created content (homebrew systems, custom oracles by players) | Later; the model stays open for it |
| AI-generated narrative | Later; only the Narrative-assist port is designed now |
| Native mobile apps | A responsive web SPA is enough |

## Product pillars

| Pillar | Means |
|---|---|
| Guided flow | Play is driven by a `NarrativeFlow` configured per game system. Mythic-style and free-journal flows ship as presets for generic or unsupported systems. How the flow is presented is key and will be refined iteratively |
| Uncertainty via oracles | Oracle tables, likelihood oracles and dice expressions give answers the player cannot predict |
| System-agnostic | Sheets, derived values and checks are data defined in Studio, not code per system |
| Rich authoring UX | Studio is a first-class product surface, not an admin panel. Its UX must match Play |

## Success criteria

| Criterion | Signal |
|---|---|
| A full solo session is playable end to end | Create campaign → character → run flow steps → oracle answers and checks → journal, without leaving the app |
| A new game system usually needs no code | A game manager defines sheet, checks, flow and oracles in Studio and publishes a release; systems that need more get code-backed extensions, analyzed case by case ([ADR 0013](adr/0013-studio-is-a-core-context.md)) |
| Rule edits are safe | Publishing a new GameSystem release never breaks a running campaign |
| The flow feels clear | The player always knows the next step; validated by playing real sessions |
| The method is learned | Each feature follows ODD with a feature doc, work-unit commits and tests |

## Open questions

Policy: decide at the last responsible moment. Each question is answered when the first feature that needs it starts. The ODD exploration step checks this list, asks one focused question if a feature is blocked, and records the answer as an ADR.

| Question | Decide when | How |
|---|---|---|
| How far do structured checks go before they need a scripting language? | Randomness / Check feature | Start with dice + formulas + outcome bands; extend only on a real gap |
| What does the Narrative-assist port need as input and output? | When AI enters scope | Shape it from the real flow; only the port exists until then |
| When does player-created content enter scope, and who moderates it? | Later | Explicitly out of scope for now |

Resolved: boundary enforcement ([ADR 0012](adr/0012-phpat-boundary-enforcement.md)), the RDD timing ([ADR 0011](adr/0011-gentle-ai-workflow.md)) and how the flow is presented in Play ([ADR 0016](adr/0016-flow-presentation-journal-with-focus-mode.md)). Context-level questions live in the [context map](domain/context-map.md#open-questions).

## Related

- [Roadmap](roadmap.md)
- [Context map](domain/context-map.md)
- [Glossary](domain/glossary.md)
- [Architecture decisions](adr/README.md)
