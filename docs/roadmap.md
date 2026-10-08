# Roadmap

Ogami ships Play first. Game content comes from hand-authored JSON preset releases, published through the same release format Studio will use later. The MVP is a full solo session in a real game system: campaign, character, guided flow, oracles, checks and journal.

Terms are defined in the [glossary](domain/glossary.md). Contexts are described in the [context map](domain/context-map.md).

## Strategy

| Decision | Why |
|---|---|
| Play before Studio | Play needs a GameSystem release to run anything. Building Studio editors first would delay the first playable session by months |
| Release format first | Feature 4 defines the versioned release schema (the Published Language). Presets are JSON files imported by a Studio console command; Play reads them through its anti-corruption layer ([ADR 0010](adr/0010-versioned-gamesystem-releases.md)) |
| Studio arrives as a second producer | When Studio editors ship, they publish the same release format, so Play does not change |
| Sign-in first | Every Play feature needs an owning user |
| Flow model before flows ship | The NarrativeFlow model ([ADR 0017](adr/0017-narrativeflow-model.md)) is settled before any flow with steps is published, because campaigns pin their release for good. Guided flows are proven with hand-authored presets before Studio's flow editor |
| Open questions at the last responsible moment | Each open question from the [vision](vision.md#open-questions) and [context map](domain/context-map.md#open-questions) is settled by the feature listed below, recorded as an ADR |

## Milestones

| Milestone | Goal | Exit signal | In MVP |
|---|---|---|---|
| M0 Foundation | Docs, monorepo, CI | Done | — |
| M1 Oracle journal | Free-form solo play: dice, oracles, journal in a campaign | A free-journal session played with only oracles and dice | Yes |
| M2 Guided flow | A narrative flow drives play step by step | A full Mythic-style session end to end, and a guided sample session played as a novice (Session Zero, scenes, world turns); the player always knows the next step | Yes |
| M3 System rules | Characters, derived values, checks | A real game system played with sheet and checks. **MVP complete** | Yes |
| M4 Studio | Author game systems without code | A game system built in Studio, published and played | No |
| M5 Admin and lifecycle | Users, roles, campaign upgrades | The owner manages users; a campaign upgrades to a newer release safely | No |
| Later | Narrative assist (AI), player-created content, export | Out of scope, per the [vision](vision.md#non-goals-for-now) | No |

## Features

Build in this order (M4 builds the flow editor third: 14, 15, 18, 16, 17, 18b). Each feature follows ODD: feature doc in `odd/tasks/<feature>.md`, delivered as sequential slices to `main` (one branch and one PR per slice, at most one open PR per feature; [ADR 0015](adr/0015-sequential-slice-delivery.md)), work-unit commits.

| # | Feature | Milestone | Depends on | Settles |
|---|---|---|---|---|
| 1 | `identity-auth` | M1 | — | — |
| 2 | `randomness-dice` | M1 | 1 | — |
| 3 | `randomness-oracles` | M1 | 2 | — |
| 4 | `gamesystem-release-contract` | M1 | 3 | Is Studio core or supporting? |
| 5 | `play-campaign-journal` | M1 | 4 | Campaign upgrade path: pinned to its release ([ADR 0014](adr/0014-campaigns-pinned-to-their-release.md)) |
| 6 | `flow-presentation-spike` | M2 | 5 | How the flow is presented in Play |
| 6b | `flow-model-brainstorm` | M2 | 6 | How a guided flow is modeled; generic vs game-specific content ([ADR 0017](adr/0017-narrativeflow-model.md)) |
| 6c | `flow-model-examples` | M2 | 6b | World-turn consequences for the next scene; how a loop phase ends; interrupting into another Scene Type |
| 7 | `play-flow-run` | M2 | 6c | — |
| 8 | `play-threads-npcs` | M2 | 7 | — |
| 8b | `play-campaign-facts` | M2 | 8 | — |
| 8c | `design-foundation` | M2 | 8b | The curated theme set |
| 9 | `preset-mythic-flow` | M2 | 8c | — |
| 9b | `preset-guided-sample` | M2 | 9 | — |
| 10 | `play-characters` | M3 | 5 | — |
| 11 | `rules-derived-values` | M3 | 10 | Where formula evaluation lives |
| 12 | `play-checks` | M3 | 11 | How far checks go before scripting |
| 13 | `preset-real-system` | M3 | 12 | — (MVP complete) |
| 14 | `studio-gamesystem-drafts` | M4 | 4 | — |
| 15 | `studio-oracle-editor` | M4 | 14 | — |
| 18 | `studio-flow-editor` | M4 | 15, 9b | — |
| 16 | `studio-sheet-builder` | M4 | 14, 11 | — |
| 17 | `studio-check-editor` | M4 | 16, 12 | — |
| 18b | `studio-library` | M4 | 18, 15 | Generic flows |
| 19 | `admin-users` | M5 | 1 | — |
| 20 | `play-release-upgrade` | M5 | 14, 10 | — |

## Backlog

Items here come after every feature defined above. Their priority and order are decided later.

| Item | Area | Idea |
|---|---|---|
| `play-journal-attachments` | Play | Write a note and attach hand-picked roll and oracle results from the current scene. `play-campaign-journal` records each result as its own entry |
| `play-campaign-transfer` | Play | Start a new campaign with another flow of the same GameSystem, carrying over Campaign facts, NPCs and Threads (e.g. a one-shot that grows into a campaign). After every roadmap feature: until then a campaign's flow is fixed, with only guidance pause and resume ([ADR 0017](adr/0017-narrativeflow-model.md)) |

## Starter prompts

Paste one prompt into a fresh session to start a feature. The project `CLAUDE.md` loads the ODD workflow, so the prompts only state scope. Adjust scope during the ODD exploration step if the code or earlier features suggest it.

### M1 Oracle journal

**1. identity-auth**

```text
Start feature `identity-auth` (ODD). Identity & Access context: User aggregate, roles SOLO_PLAYER/GAME_MANAGER/OWNER, session-cookie login/logout/me endpoints (ADR 0006), console command to create users, SPA login page and role-guarded routes for /play, /studio, /admin. Out of scope: registration, user management UI, password reset.
```

**2. randomness-dice**

```text
Start feature `randomness-dice` (ODD). Randomness shared kernel: DiceExpression parser and evaluator (NdM, modifiers, keep/drop highest/lowest, arithmetic), injected randomness source, Roll result with per-die breakdown. Expose POST /api/rolls and a reusable dice-roller component in Play. Pure domain, heavily unit-tested. Out of scope: formulas referencing sheet fields.
```

**3. randomness-oracles**

```text
Start feature `randomness-oracles` (ODD). Randomness kernel: OracleTable (weighted/ranged entries, nested tables) and Likelihood oracle (yes/no with likelihood levels, optional chaos-factor input, exceptional results). Endpoints to resolve both; oracle panel component in Play. Oracle definitions are passed in as data; no persistence of definitions yet.
```

**4. gamesystem-release-contract**

```text
Start feature `gamesystem-release-contract` (ODD). Define the Published Language: a versioned JSON schema for an immutable GameSystem release (metadata, oracles, narrative flow, empty-for-now sheet and check sections). Studio side: import-and-publish console command with validation (ADR 0010). Play side: anti-corruption layer that snapshots a release into Play's model. Ship presets "Free journal" and "Mythic-style" (oracles only for now). Settle the context-map question "Is Studio core or supporting?" as an ADR.
```

**5. play-campaign-journal**

```text
Start feature `play-campaign-journal` (ODD). Play: create/list/open campaigns pinned to a GameSystem release; sessions and scenes; journal entries that can embed roll and oracle results. Play screen: journal + dice roller + oracles from the campaign's release. Record "campaigns stay pinned to their release" as the default upgrade policy (ADR).
```

### M2 Guided flow

**6. flow-presentation-spike**

```text
Start feature `flow-presentation-spike` (ODD). Settle the vision open question "How is the flow presented in Play?". Build two Storybook prototypes with static data (step-by-step wizard vs journal with inline prompts), play a mock session in each, and record the choice and rationale as an ADR. No backend work.
```

**6b. flow-model-brainstorm**

Done: the brainstorm settled the model in [ADR 0017](adr/0017-narrativeflow-model.md). The prompt is kept for reference.

```text
Start feature `flow-model-brainstorm` (ODD). Brainstorming session, read-only until I approve outcomes, to settle the NarrativeFlow model before feature 7 `play-flow-run`. Timebox: one session. Every topic below must be addressed.

Read first: docs/vision.md (open questions), docs/domain/glossary.md, docs/domain/context-map.md, docs/roadmap.md (M2–M4), ADR 0010, 0013, 0014, 0016 (flow presentation: journal with an optional focus mode), backend/presets/*.json, backend/src/Studio/Domain/Release/ReleaseContent.php (the current `flow` is a flat `{steps: []}`), frontend/src/play/flow-prototypes/ (spike prototypes).

Problem: the current plan suits solo players with TTRPG and solo-play experience. Novice players need guided flows that a GAME_MANAGER authors in Studio for a specific game: a Session Zero (character creation and worldbuilding), sequences of scene types (e.g. Social → Exploration), suggested or mandatory oracle rolls per scene, and world changes between player scenes (NPCs acting, encounters). The SOLO_PLAYER may choose such a guided flow or play freely. Some content is generic and some is specific to a game system: a netrunning scene makes sense in Cyberpunk RED but not in Call of Cthulhu, and a name table differs between a fantasy setting and a samurai setting. The GAME_MANAGER chooses which oracles and tables are available to a flow.

Goal: decide the shape of the flow model now, because published releases are pinned by campaigns for good. Do not design the Studio editor UX.

Explore one topic at a time; ask me one focused question when a product decision is needed:
1. Flow structure: phases (Session Zero, adventure loop, epilogue) → scene types → steps; a sequence, a graph or a loop; who picks the next scene type (the flow, the player, an oracle such as a random scene-type table)
2. Scene Type: purpose, setup / play / closing steps, its own oracles; where Mythic's scene check (expected / altered / interrupted) fits
3. Mandatory vs suggested steps in solo play: a gate or a strong default; can the player skip, and is the skip recorded in the journal
4. Between-scene world turns: NPC agendas, faction clocks, random encounters, threads advancing; impact on feature 8 `play-threads-npcs` (NPC agenda or disposition fields); encounters as oracle tables
5. Flow state and variables (Mythic's chaos factor, clocks, counters) and how they relate to M3 checks and derived values
6. Session Zero: character creation depends on M3 sheets; where worldbuilding output lives (journal entries or a new campaign-facts concept)
7. Several flows per GameSystem (quick one-shot, campaign with Session Zero); choosing one when creating a campaign; switching or turning guidance off mid-campaign; tutorial and tip text written by the GAME_MANAGER
8. Presentation: how ADR 0016 adapts to guided flows (focus mode by default, scene-type cards); "next step always visible" across scene boundaries
9. Studio's role and priority: is M4 / `studio-flow-editor` order still right; list vs visual-graph editor; a library of scene types
10. Glossary: Scene Type, Phase, World turn / Interlude, Encounter, Suggested / Mandatory step, and any other new term
11. Later, the Narrative-assist port: scene types as structured AI input (note only)
12. Generic vs game-specific content: which entities (oracles, oracle tables, scene types, maybe flows) can be generic (usable by any game system) or specific to one GameSystem; who authors generic content and where it lives (a shared library published like a release, or copied into each release at publish time, keeping releases self-contained and immutable per ADR 0010); how a GameSystem picks or overrides generic content (e.g. a samurai name table instead of the fantasy one); how a GAME_MANAGER chooses which oracles and tables a flow makes available, and what Play shows outside that selection; the effect on pinning (ADR 0014) when a generic library changes
13. Visual design: when a `design-foundation` feature fits (proposal: after `play-threads-npcs`, before M4 Studio), and whether each GameSystem may carry its own theme

Deliverables, proposed for my approval before any write:
- An ADR for the NarrativeFlow model: structure, generic vs game-specific content, the release schema change, what M2 implements and what is deferred
- Glossary and context-map updates
- A revised M2–M4 roadmap: adjusted feature prompts (7, 8, 9, 18 at least), a guided sample preset in M2 that proves the model without Studio UI, and a `design-foundation` feature
- Remaining questions added to the vision's open questions, each with "decide when"
```

**6c. flow-model-examples**

A second brainstorm, asked for during 6b: map real examples to the model in [ADR 0017](adr/0017-narrativeflow-model.md) and find its limits before `play-flow-run` implements schema version 2.

```text
Start feature `flow-model-examples` (ODD). Brainstorming session, read-only until I approve outcomes. Map real play examples to the NarrativeFlow model of ADR 0017 and find its limits before feature 7 `play-flow-run` implements release schema version 2. Timebox: one session.

Read first: ADR 0017, ADR 0016, docs/domain/glossary.md, docs/vision.md (open questions marked "6c"), docs/roadmap.md (M2), backend/presets/*.json.

Examples to map, step by step, as a GAME_MANAGER would author them and a SOLO_PLAYER would play them:
- A Vampire chronicle in London: Session Zero worldbuilding with Campaign facts such as "The Camarilla rules London", "The Prince is a Ventrue", "The Prince is Mithras" (an `npc` fact slot), then social and investigation scenes with world turns where NPC agendas move.
- A Cyberpunk netrun: a game-specific Scene Type with its own oracle tables and a clock.
- A Mythic-style session: chaos tracker, scene check in the scene opening, altered and interrupted scenes, end-of-scene list updates.

Questions to settle:
1. How a world turn affects the player's next scene: must the player react, and does ignoring it have a consequence? Defined by the GAME_MANAGER how?
2. How a loop phase ends: only by the player's choice, or also on a tracker condition (a full clock)?
3. Can an interruption switch the current scene to another Scene Type (e.g. Mythic's interrupted scene, an ambush)?
4. Do NPC disposition, factions or thread progress need fields now, or do trackers and facts cover them?
5. Any gap in step kinds, effects or placeholders the examples reveal.

Deliverables, proposed for my approval before any write: amendments to ADR 0017 (or a new ADR), glossary updates, an adjusted feature 7 prompt, and answered vision open questions.
```

**7. play-flow-run**

```text
Start feature `play-flow-run` (ODD). Implement the NarrativeFlow model of ADR 0017 (as amended by feature 6c). Release contract schema version 2: trackers, fact slots (declared only; Campaign facts arrive in 8b), Scene Types, flows with phases (once/loop, scene selection sequence/player/oracle, scene opening/closing, world turn), steps with mandatory/suggested, branches and effects; oracle table entries may point to a Scene Type. Studio validation and the anti-corruption layer; schema version 1 releases stay readable (no flows). Play: choose a flow or "Play freely" when creating a campaign; FlowRun across phases, scenes and parts with history (skips, tracker edits); step kinds prompt, oracle, table, roll with outcome bands, choice; tracker values on the Campaign, bound to the likelihood oracle's chaos; Scenes with a Scene Type and world-turn scenes; guidance pause/resume. Presentation per ADR 0016 as amended by ADR 0017: journal as the base, focus mode, each flow's defaultView, scene-type cards, the next step always named across boundaries; oracle panel with scene shortcuts, the flow's selection and "More oracles". Deliver as sequential slices (contract and ACL first, then FlowRun, then UI, then focus mode). Delete the flow prototypes. Works with the Free journal preset (no flows).
```

**8. play-threads-npcs**

```text
Start feature `play-threads-npcs` (ODD). Play: per-campaign Threads and NPCs lists (add, edit, close, weight), shown beside the journal. NPCs have an optional `agenda` (ADR 0017). Flow step kind `pick` (a random Thread or NPC, usable as `{picked}` in later step prompts) and effects that create an NPC or a Thread from a step's answer; extend release schema version 2 accordingly. Out of scope: relationship graphs, NPC disposition, factions, thread progress.
```

**8b. play-campaign-facts**

```text
Start feature `play-campaign-facts` (ODD). Play: Campaign facts (ADR 0017). A fact has a label and text, optionally fills a fact slot declared by the release (typed `text`, `npc` or `thread`; an `npc` slot picks or creates an NPC), or is free. Any fact links to NPCs, Threads or other facts by reference. Facts panel beside the journal (add, edit, link); NPC and Thread details show their linked facts. Flow steps fill fact slots through effects; prompts render `{slot}` placeholders. Out of scope: mentions and backlinks from journal entries.
```

**8c. design-foundation**

```text
Start feature `design-foundation` (ODD). Visual design foundation before the M2 playtest presets, now that every Play region exists (journal, step card, focus mode, oracle, tracker, facts, NPC and Thread panels). Design tokens (color, type, spacing, light and dark), the Play layout, shadcn component styling, Storybook as the catalogue. Curated per-GameSystem themes (ADR 0017): a few app-defined, contrast-checked themes (e.g. `parchment`, `neon`, `noir`), an optional release `theme` field naming one (release schema bump), applied inside that campaign. Settle the theme set. Out of scope: Studio screens beyond the shared tokens.
```

**9. preset-mythic-flow**

```text
Start feature `preset-mythic-flow` (ODD). Complete the Mythic-style preset release on the model of ADR 0017: a chaos-factor tracker bound to the fate question; one looping adventure phase; the scene check as a roll with outcome bands in the scene opening (expected / altered / interrupted); random events (focus, action/subject meaning tables); end-of-scene chaos adjustment and Thread/NPC list updates in the scene closing. Validate by playing a real session; log UX findings as follow-up tasks.
```

**9b. preset-guided-sample**

```text
Start feature `preset-guided-sample` (ODD). Hand-author an original guided preset for novice players that proves the model of ADR 0017 without Studio: Session Zero (character concept and worldbuilding into fact slots, a rival NPC, a goal Thread), an adventure loop with game-specific Scene Types (sequence and oracle scene selection), mandatory and suggested steps, a world turn with an encounter table, NPC agendas and a clock, tips and an introduction, `defaultView: focus`, and a flow-specific oracle selection. Play a session as a novice; fix the gaps it reveals and log UX findings as follow-up tasks. This completes M2.
```

### M3 System rules

**10. play-characters**

```text
Start feature `play-characters` (ODD). Extend the release schema with SheetTemplate and Field types (number, text, list, resource track). Play: create and edit characters from the campaign's release template. Add the `character` flow step kind (ADR 0017): it completes when a Character exists, so Session Zero can create one. Out of scope: derived values, checks.
```

**11. rules-derived-values**

```text
Start feature `rules-derived-values` (ODD). Formula language for DerivedValue over sheet fields (arithmetic, min/max, field references), evaluated live on the sheet. Formulas can read campaign trackers (ADR 0017), and a tracker reference in a band bound or effect becomes a trivial formula. First decide where formula evaluation lives (Randomness kernel vs a new shared kernel) and record it as an ADR. Extend the release schema.
```

**12. play-checks**

```text
Start feature `play-checks` (ODD). Check definitions in the release (dice expression + formula modifiers + outcome bands). Run checks from the sheet and from flow steps (a `check` step kind, ADR 0017); log results to the journal. Settle the vision question "How far do structured checks go?" as an ADR (dice + formulas + outcome bands; scripting only on a real gap).
```

**13. preset-real-system**

```text
Start feature `preset-real-system` (ODD). Hand-author a release for a small, license-safe real game system (sheet, derived values, checks, flow). Play a full session with it and fix the schema gaps it reveals. This completes the MVP.
```

### M4 Studio

**14. studio-gamesystem-drafts**

```text
Start feature `studio-gamesystem-drafts` (ODD). Studio: GameSystem drafts (create, edit metadata, validate), publish as a new immutable release reusing the release-contract publisher, version history. Studio shell UX at Play quality.
```

**15. studio-oracle-editor**

```text
Start feature `studio-oracle-editor` (ODD). Studio: rich editor for oracle tables and likelihood oracles in a draft, with live test rolls through Randomness.
```

**18. studio-flow-editor**

```text
Start feature `studio-flow-editor` (ODD). Studio: a list/outline editor for the NarrativeFlow model of ADR 0017 in a draft: Scene Types (purpose, tips, setup/play/closing steps, oracle shortcuts), trackers, fact slots, and flows with phases (mode, scene selection, scene opening/closing, world turn), the oracles and trackers each flow selects, steps with mandatory/suggested, branches and effects. Validation matches the publisher. Existing presets (Mythic-style, guided sample) serve as starting points. No node-graph library (ADR 0004).
```

**16. studio-sheet-builder**

```text
Start feature `studio-sheet-builder` (ODD). Studio: visual sheet-template builder (fields, layout) and derived-value formula editor with live preview and validation.
```

**17. studio-check-editor**

```text
Start feature `studio-check-editor` (ODD). Studio: check editor (dice and formula editor, outcome bands) with a test-roll sandbox against a sample sheet.
```

**18b. studio-library**

```text
Start feature `studio-library` (ODD). Studio: a library of generic content (oracle tables, likelihood oracles, Scene Types, trackers, fact slots) authored by any GAME_MANAGER. A GameSystem draft imports library items; publishing copies them into the release, so releases stay self-contained (ADR 0010, ADR 0017). A GameSystem overrides an item by key (e.g. a samurai `npc-name` table); every referenced key must resolve at publish. Flag drafts whose imported items changed in the library. Settle whether flows can be generic. Optional provenance in the release.
```

### M5 Admin and lifecycle

**19. admin-users**

```text
Start feature `admin-users` (ODD). Admin: the owner lists users, invites by email (Mailpit in dev), assigns roles and disables accounts, through Identity & Access application services. Include password reset.
```

**20. play-release-upgrade**

```text
Start feature `play-release-upgrade` (ODD). Play: let a player upgrade a campaign to a newer GameSystem release, with a preview of the character data migration; nothing changes without confirmation.
```

### Backlog

**play-journal-attachments**

```text
Start feature `play-journal-attachments` (ODD). Play: write a journal note and attach hand-picked roll and oracle results from the current scene, shown together in the journal. Builds on the journal entries of `play-campaign-journal`, where each result is its own entry. Out of scope: attaching results from other scenes, editing entries.
```

**play-campaign-transfer**

```text
Start feature `play-campaign-transfer` (ODD). Play: start a new campaign from an existing one with another flow of the same GameSystem (e.g. a one-shot that grows into a campaign), carrying over Campaign facts, NPCs and Threads; the source campaign stays unchanged. Out of scope: moving between GameSystems.
```

## Related

- [Vision](vision.md)
- [Context map](domain/context-map.md)
- [Architecture decisions](adr/README.md)
