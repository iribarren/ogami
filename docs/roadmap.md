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
| Rules before content, after M4 | Since Studio shipped, a contract gap reaches the contract, the anti-corruption layer, Play, the Studio editors and the release upgrade planner. A rules feature closes it once; presets then add content only and are authored in Studio where they can |

## Milestones

| Milestone | Goal | Exit signal | In MVP |
|---|---|---|---|
| M0 Foundation | Docs, monorepo, CI | Done | — |
| M1 Oracle journal | Free-form solo play: dice, oracles, journal in a campaign | A free-journal session played with only oracles and dice | Yes |
| M2 Guided flow | A narrative flow drives play step by step | A full Mythic-style session end to end (played 2026-10-13, feature 9), and a guided sample session played as a novice (Session Zero, scenes, world turns); the player always knows the next step. Done: the guided sample session was played 2026-10-13 (feature 9b) | Yes |
| M3 System rules | Characters, derived values, checks | A real game system played with sheet and checks. Done: `percentile-horror` (feature 13). **MVP complete** | Yes |
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
| 6c | `flow-model-examples` | M2 | 6b | World-turn consequences for the next scene; how a loop phase ends; interrupting into another Scene Type; sandbox goals; acts; several player characters per campaign |
| 7 | `play-flow-run` | M2 | 6c | — |
| 8 | `play-threads-npcs` | M2 | 7 | — |
| 8b | `play-campaign-facts` | M2 | 8 | — |
| 8c | `design-foundation` | M2 | 8b | The curated theme set |
| 9 | `preset-mythic-flow` | M2 | 8c | Random events from fate-question doubles |
| 9b | `preset-guided-sample` | M2 | 9 | Release credits / license field for fan presets |
| 10 | `play-characters` | M3 | 5 | — |
| 11 | `rules-derived-values` | M3 | 10 | Where formula evaluation lives |
| 12 | `play-checks` | M3 | 11 | How far checks go before scripting |
| 13 | `preset-real-system` | M3 | 12 | Items, inventory and a `shop` step kind: moved post-M3 to `play-items-economy` ([ADR 0025](adr/0025-items-and-shops-after-the-mvp.md)). MVP complete |
| 14 | `studio-gamesystem-drafts` | M4 | 4 | — |
| 15 | `studio-oracle-editor` | M4 | 14 | — |
| 18 | `studio-flow-editor` | M4 | 15, 9b | — |
| 16 | `studio-sheet-builder` | M4 | 14, 11 | — |
| 17 | `studio-check-editor` | M4 | 16, 12 | — |
| 18b | `studio-library` | M4 | 18, 15 | Generic flows |
| 19 | `admin-users` | M5 | 1 | — |
| 20 | `play-release-upgrade` | M5 | 14, 10 | — |

## Backlog

Items here come after every feature defined above. Build them in this order: fixes and tooling first, then guided-play and theme polish before the next playtests, then each rules feature right before the presets that need it. Studio polish follows the first preset authored in Studio (B7), so its findings shape the design. Only `preset-cpr-campaign` needs `play-items-economy`; `preset-west-marches` uses it for shops only.

| # | Item | Area | Depends on | Idea |
|---|---|---|---|---|
| B1 | `studio-atomic-write-read` | Studio | — | Run each Studio editor write and its read-back in one transaction, as `LibraryImportController` does, so an interleaved save's version never leaks into a response and a failing read rolls the write back ([#195](https://github.com/iribarren/ogami/issues/195)). One `fix/` slice, no API change |
| B2 | `identity-account-error-codes` | Identity | — | Account-link errors (`InvalidAccountLink`, `WeakPassword`) answer a machine-readable code next to the message, so the public account pages stop matching the English text (`frontend/src/app/account/accountApi.ts`). Follow-up of `admin-users` (slice 8 review). One slice, API change |
| B3 | `e2e-shared-sign-in` | Tooling | — | One shared Playwright sign-in helper next to `frontend/e2e/mailpit.ts` instead of a copy per spec. Follow-up of `admin-users` (slice 8 review). One `chore/` slice, no behaviour change |
| B4 | `play-guided-polish` | Play | — | Fix the open playtest findings of features 9 and 9b ([P7–P13](../odd/tasks/preset-mythic-flow.md#playtest-findings), [N8–N13](../odd/tasks/preset-guided-sample.md#playtest-findings)): roll outcomes not interpreted, silent tracker effects, "Next:" previews of branch-only steps, scene numbering across sessions and world turns, stale guide tips, steps that should show only on a condition. Before the next preset playtests, so they find new gaps instead of the same ones |
| B5 | `ui-theme-polish` | UI | — | Give the UI depth and a game feel, following the [design canvas](https://claude.ai/artifact/6MWYwPaXvQVUJYb7cXGiSo) (private): a `--well` token so inputs sit sunken instead of matching their card, layered card shadows and a background texture per theme, lucide icons on panel headers, dice buttons and actions, and per-theme embellishments (title mark, label style, divider ornament, panel frame, drop cap, tracker pip shape) so every campaign theme feels as distinct as Neon. Before the preset playtests, so they judge the finished look |
| B6 | `play-journal-attachments` | Play | — | Write a note and attach hand-picked roll and oracle results from the current scene. `play-campaign-journal` records each result as its own entry |
| B7 | `preset-pbta` (in progress) | Preset | B4 | A Powered by the Apocalypse preset: Thirsty Sword Lesbians or Bluebeard's Bride in original wording. 2d6 + stat moves with three outcome bands fit the contract today: no contract change. Authored in Studio, as the first end-to-end proof of M4 on a new system |
| B7b | `studio-ui-polish` | Studio | B7 | Design first: a design canvas of the Studio screens in the default light and dark themes, reviewed before any code. Then fix the Studio findings of B7 and apply the canvas: the B5 depth, type and icon tokens where they fit, icon tiles on tabs and editor sections, test results in wells, lucide icons on add, delete and reorder buttons, editor list density |
| B8 | `rules-dice-pools` | Rules | B4 | Close the pool gaps of [ADR 0024](adr/0024-structured-checks-without-scripting.md) once, across the contract, Play, the Studio check editor and the release upgrade planner: a dice count read from the sheet, counting successes per die, pushed rolls (re-roll the dice that did not succeed) and conditions as sheet effects |
| B8b | `rules-flow-choices` | Rules | B7 | Close two flow gaps `preset-pbta` found ([S11, S12](../odd/tasks/preset-pbta.md#studio-findings)) across the contract, Play, the Studio flow editor and the release upgrade planner: sheet effects on `choice` options and `prompt` answers (end-of-session XP questions), and a `check` step that offers a set of checks for the player to pick (a PbtA move rolled with any stat). Then rework `hearts-and-rapiers` in Studio to use them |
| B9 | `preset-vaesen` | Preset | B8 | A Vaesen preset on the Year Zero SRD, under Free League's free license: d6 pools, sixes as successes, pushed rolls and conditions from B8 |
| B10 | `preset-vtm-chronicle` | Preset | B8 | A Vampire: the Masquerade night-court sandbox fan preset ([example 1](domain/flow-examples.md#1-vtm-chronicle-sandbox)): d10 pools from B8, plus critical pairs and Hunger dice. The Dark Pack license is non-commercial: check it fits before starting |
| B11 | `play-items-economy` | Play | — | Structured items with quantity, cost and slots, a currency and a `shop` step kind. Until then gear is a flat sheet `list` and money a tracker or a `number` field ([ADR 0025](adr/0025-items-and-shops-after-the-mvp.md)). Built right before the first preset that needs it, B12 |
| B12 | `preset-cpr-campaign` | Preset | B11 | A Cyberpunk RED campaign in acts fan preset ([example 4](domain/flow-examples.md#4-cyberpunk-red-campaign-in-acts)), preferably a second Flow in the `night-job` GameSystem, so the heist one-shot can grow into a campaign through B13 |
| B13 | `play-campaign-transfer` | Play | B12 | Start a new campaign with another flow of the same GameSystem, carrying over Campaign facts, NPCs and Threads (e.g. a one-shot that grows into a campaign). Until then a campaign's flow is fixed, with only guidance pause and resume ([ADR 0017](adr/0017-narrativeflow-model.md)). After B12, the first GameSystem with a one-shot and a campaign Flow |
| B14 | `play-places` | Play | — | Settle the vision question "How are places modeled?" and add a `select` sheet field type: the two West Marches gaps no other item closes |
| B15 | `preset-west-marches` | Preset | B8, B11, B14 | A West Marches preset on D&D 5e under SRD 5.2 (CC BY 4.0) ([example 5](domain/flow-examples.md#5-west-marches)): conditions from B8, shops from B11, places and a class `select` field from B14; HP and spell slots as sheet effects ([ADR 0027](adr/0027-sheet-effects.md)) |
| B16 | `preset-ironsworn` | Preset | B7 | Optional. Ironsworn under CC BY 4.0: challenge dice (an action score against two d10), momentum below zero and vow progress tracks |

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

Done: the five examples settled control flow, hooks, tags and the Scene cast in [ADR 0018](adr/0018-narrativeflow-control-flow-and-cast.md); the worked examples are in [Flow examples](domain/flow-examples.md). The prompt is kept for reference.

```text
Start feature `flow-model-examples` (ODD). Brainstorming session, read-only until I approve outcomes. Map real play examples to the NarrativeFlow model of ADR 0017 and find its limits before feature 7 `play-flow-run` implements release schema version 2. Timebox: one session.

Read first: ADR 0017, ADR 0016, docs/domain/glossary.md, docs/vision.md (open questions marked "6c"), docs/roadmap.md (M2), backend/presets/*.json.

Examples to map, step by step, as a GAME_MANAGER would author them and a SOLO_PLAYER would play them:
1. A Vampire: the Masquerade chronicle (sandbox). Session Zero worldbuilding decides the city, setting, tone and the city's factions as Campaign facts (e.g. "The Camarilla rules London", "The Prince is a Ventrue", "The Prince is Mithras" through an `npc` fact slot). With no set plot, the player turns their character's ambitions into goals that start each session; explore how the flow helps set those goals.
2. A Cyberpunk RED one-shot heist. A short flow that mixes generic Scene Types (Social, Exploration) with game-specific ones (netrun, the heist itself), with their own oracle tables and a clock.
3. A Mythic-style session: chaos tracker, scene check in the scene opening, altered and interrupted scenes, end-of-scene list updates.
4. A Cyberpunk RED long campaign. The player starts small, surviving and making a living from small gigs, and rises from there in acts: Act 1 "Making a name / getting a crew", Act 2 "The big job", Act 3 "The twist and conclusion". Explore whether acts are phases, a new level above phases, or a later extension the model must leave room for.
5. A West Marches-style fantasy campaign (D&D, or a simpler fantasy system if D&D is too complex for now). Session Zero creates a roster of characters, an adventuring group. Each session the player picks some of them to go on a specific quest or follow earlier clues.

Questions to settle:
1. How a world turn affects the player's next scene: must the player react, and does ignoring it have a consequence? Defined by the GAME_MANAGER how?
2. How a loop phase ends: only by the player's choice, or also on a tracker condition (a full clock)?
3. Can an interruption switch the current scene to another Scene Type (e.g. Mythic's interrupted scene, an ambush)?
4. Do NPC disposition, factions or thread progress need fields now, or do trackers and facts cover them?
5. How the player sets goals from their character's ambitions in a sandbox (example 1): Threads, Campaign facts, or a new concept?
6. Acts (example 4): a phase, a new level of the flow, or deferred with room left in the schema?
7. Several player characters per campaign (examples 4 and 5: a crew, a roster, picking who goes on each session): add it to the model from the start or defer it to the backlog?
8. Any gap in step kinds, effects or placeholders the examples reveal.

Deliverables, proposed for my approval before any write: amendments to ADR 0017 (or a new ADR), glossary and vision updates with what the examples reveal (new terms, answered and new open questions), and an adjusted feature 7 prompt.
```

**7. play-flow-run**

```text
Start feature `play-flow-run` (ODD). Implement the NarrativeFlow model of ADR 0017 as amended by ADR 0018. Release contract schema version 2 with ADR 0018 decisions 1–14, tracker `hint` (17), counter `levels` (18) and the phase `act` label (19): trackers, fact slots (declared only; Campaign facts arrive in 8b), Scene Types, flows with phases (once/loop, optional act label, scene selection sequence/player/oracle, hooks sessionOpening/sessionClosing, phaseOpening/phaseClosing, sceneOpening/sceneClosing, worldTurn), steps with mandatory/suggested, branches and effects. Effects are a tagged union: tracker (add/set), nextScene, switchSceneType, endPhase, sceneTitle. Outcome bands are ordered upper bounds (a literal or a tracker; the last catches the rest). Step kinds prompt, oracle (branches on its answer), table (branches per rolled entry), roll with bands, choice, condition (a tracker against bands; no dice, no journal entry). Oracle table entries may point to a Scene Type and carry effects. Placeholders {tracker:key}, {step:key}, {answer}. Contract fixtures: the five examples of docs/domain/flow-examples.md as schema version 2 JSON that must validate and pass the ACL (decision 26); parts that need features 8, 8b or 10 (tags, `pick`, facts, Characters) join the fixtures when those features land. Studio validation (warn when a threshold consequence does not lower its tracker) and the anti-corruption layer; schema version 1 releases stay readable (no flows). Play: choose a flow or "Play freely" when creating a campaign; FlowRun across phases, scenes and parts with history (skips, tracker edits, Scene Type switches), `completed` after its last phase; tracker values on the Campaign, bound to the likelihood oracle's chaos; Scenes with a Scene Type and Scene kind `scene` | `hook` (with the hook name, each hook rendered its own way); a Session is one sitting, with an "End session" action offered between scenes while guided; a loop phase ends by the player's choice or condition + endPhase; in-place switch to another Scene Type (at most one per scene through effects, by hand in free play); a phase with one Scene Type picks it automatically; scene titles (the Scene Type name, numbered, unless sceneTitle sets one); guidance pause/resume. Presentation per ADR 0016 as amended by ADR 0017: journal as the base, focus mode, each flow's defaultView, scene-type cards, the next step always named across boundaries, progress `Act › Phase › Scene type › part · step n/m`; oracle panel with scene shortcuts, the flow's selection and "More oracles". Deliver as sequential slices (contract and ACL first, then FlowRun, then control flow: conditions, effects, hooks and switches, then UI, then focus mode). Delete the flow prototypes. Works with the Free journal preset (no flows).
```

**8. play-threads-npcs**

```text
Start feature `play-threads-npcs` (ODD). Play: per-campaign Threads and NPCs lists (add, edit, close, weight), shown beside the journal. NPCs have an optional `agenda` (ADR 0017). Tags (ADR 0018): the release declares `tags[] {key, label, hint?}` for NPCs and Threads, and the player may add free tags. Flow step kind `pick`: filter by tags (all must match), mode random or choose, a count (min/max, one by default), branches found/none, the result usable as `{picked}` in later step prompts. Effects `createNpc`, `createThread` and `closeThread`; `condition` steps count NPCs with a tag or open Threads. The NPC part of the Scene cast: the NPCs present in a Scene, added by picks and edited by the player. Extend release schema version 2 accordingly. Factions are Campaign facts (8b) plus a tagged leader NPC with an agenda; disposition is a fact linked to an NPC. Out of scope: relationship graphs, player-added trackers for thread progress or faction power (a vision open question).
```

**8b. play-campaign-facts**

```text
Start feature `play-campaign-facts` (ODD). Play: Campaign facts (ADR 0017). A fact has a label and text, optionally fills a fact slot declared by the release (typed `text`, `npc` or `thread`; an `npc` slot picks or creates an NPC), or is free. Any fact links to NPCs, Threads or other facts by reference. Facts panel beside the journal (add, edit, link); NPC and Thread details show their linked facts. Flow steps fill fact slots through the `fillFact` effect; a slot has an optional `hint`, shown where it is filled; prompts render `{fact:key}` placeholders (ADR 0018). Out of scope: mentions and backlinks from journal entries.
```

**8c. design-foundation**

Done: the design tokens, the Play layout and Storybook catalogue, and the curated theme set (`parchment`, `neon`, `noir`, `eldritch`, `starship`, via the optional release `theme` in schema version 2); see [odd/tasks/design-foundation.md](../odd/tasks/design-foundation.md). The prompt is kept for reference.

```text
Start feature `design-foundation` (ODD). Visual design foundation before the M2 playtest presets, now that every Play region exists (journal, step card, focus mode, oracle, tracker, facts, NPC and Thread panels). Design tokens (color, type, spacing, light and dark), the Play layout, shadcn component styling, Storybook as the catalogue. Curated per-GameSystem themes (ADR 0017): a few app-defined, contrast-checked themes (e.g. `parchment`, `neon`, `noir`), an optional release `theme` field naming one (release schema bump), applied inside that campaign. Settle the theme set. Out of scope: Studio screens beyond the shared tokens.
```

**9. preset-mythic-flow**

Done: the `mythic-style` preset on example 3, random events from fate-question doubles ([ADR 0019](adr/0019-random-events-from-fate-question-doubles.md)), and the scene pick that waits after a scene ([ADR 0020](adr/0020-scene-pick-waits-after-a-scene.md)). M2 exit, first half: a full Mythic-style session was played end to end on 2026-10-13. Its blocking findings were fixed; findings P7–P13 (roll outcomes not interpreted, list updates not linked to the panels, random events detached from their question, free-play scene controls during guided play (P11, partly done by `preset-real-system`: the New scene form is hidden while a Flow guides, and the player can rename the current scene), scene titles counted across sessions, an early "Next:" after the scene check, shouting scene headings) are backlog, see [odd/tasks/preset-mythic-flow.md](../odd/tasks/preset-mythic-flow.md#playtest-findings). The prompt is kept for reference.

```text
Start feature `preset-mythic-flow` (ODD). Complete the Mythic-style preset release on the model of ADR 0017 as amended by ADR 0018, following example 3 of docs/domain/flow-examples.md: a chaos-factor tracker bound to the fate question; a premise phase; one looping adventure phase with a single Scene Type (picked automatically); the scene check in the scene opening (the expected scene, a roll against chaos, altered or interrupted scenes, a random-event focus table branching per entry); action/subject meaning tables; end-of-scene chaos adjustment and Thread/NPC list updates in the scene closing. Settle whether fate-question doubles trigger random events (vision open question). Validate by playing a real session; log UX findings as follow-up tasks.
```

**9b. preset-guided-sample**

Done: the `night-job` fan preset on example 2, release credits for fan presets ([ADR 0021](adr/0021-release-credits-for-fan-presets.md)), and the novice support in Play (Flow introduction, Scene Type tips). M2 exit, second half: a guided sample session was played as a novice on 2026-10-13, which completes M2. Findings N1–N6 were fixed; N7 is accepted (rising pressure is the design) and N8–N13 (silent tracker effects, stale guide tips after a scene, previews of branch-only steps, world turns taking a scene number, ungated Black ICE step, the form below the campaign list) are backlog, see [odd/tasks/preset-guided-sample.md](../odd/tasks/preset-guided-sample.md#playtest-findings). The prompt is kept for reference.

```text
Start feature `preset-guided-sample` (ODD). Hand-author a Cyberpunk RED heist one-shot fan preset for novice players, following example 2 of docs/domain/flow-examples.md, that proves the model of ADR 0017 and ADR 0018 without Studio: a crew and briefing phase that fills fact slots, legwork, heist, escape and epilogue phases; generic Scene Types (Social, Exploration) beside game-specific ones (Netrun, Infiltration, Firefight, Getaway, Chase…); mandatory and suggested steps; an alarm clock with condition steps, an in-place switch into a firefight, world turns that add pressure; tips and an introduction, `defaultView: focus`, and a flow-specific oracle selection. Fan-content rule (ADR 0018): no verbatim book text (own words or short summaries, page references to the books) and the publisher's fan-content disclaimer. Settle the release credits / license field (vision open question). Play a session as a novice; fix the gaps it reveals and log UX findings as follow-up tasks. This completes M2.
```

### M3 System rules

**10. play-characters**

Done: release sheets (number, text, list and resource track fields) added to schema version 2 additively ([ADR 0022](adr/0022-character-sheets-in-schema-version-2.md)), Characters in the Campaign with an editor and NPC promotion, the Session party, Characters in the Scene cast, `pick` and `condition` over Characters, and the `character` step. The `night-job` preset has an original sheet and creates the player's Character in Session Zero.

```text
Start feature `play-characters` (ODD). Extend the release schema with SheetTemplate and Field types (number, text, list, resource track). Play: create and edit characters from the campaign's release template; list fields cover e.g. inventory and cyberware. A Campaign has zero or more Characters, and an NPC can be promoted to a Character (ADR 0018). Characters carry tags; the Character part of the Scene cast; the Session party (the Characters picked in a session opening; a Scene's cast starts as the party). Add the `character` flow step kind (ADR 0017): it completes when a Character exists, so Session Zero can create one. Effects on sheet fields arrive in an M3 schema version. Out of scope: derived values, checks.
```

**11. rules-derived-values**

Done: the `Formula` shared kernel (parser, evaluator and dependency order over `{field}`, `{field.max}` and `{tracker:key}` references, with a conformance file) and [ADR 0023](adr/0023-formula-kernel-and-derived-values.md); the `derived` field type and `{formula}` in band `upTo` and effect `value`, additive in schema version 2; derived values on Characters computed by the server and live in the Character form through a TypeScript evaluator that runs the same conformance file. The `night-job` preset gains `cool` and `wounded`. See [odd/tasks/rules-derived-values.md](../odd/tasks/rules-derived-values.md). The prompt is kept for reference.

```text
Start feature `rules-derived-values` (ODD). Formula language for DerivedValue over sheet fields (arithmetic, min/max, field references), evaluated live on the sheet. Formulas can read campaign trackers (ADR 0017), and a tracker reference in a band bound or effect becomes a trivial formula. First decide where formula evaluation lives (Randomness kernel vs a new shared kernel) and record it as an ADR. Extend the release schema.
```

**12. play-checks**

Done: Check definitions in the release (dice expression, formula modifier, optional roll-time difficulty and outcome bands, with `{check:difficulty}` in band formulas), additive in schema version 2, and the `check` step kind with outcomes by band ([ADR 0024](adr/0024-structured-checks-without-scripting.md): no scripting, known gaps listed); checks rolled from the Characters panel and from flow steps for a Character in the Scene cast, logged to the journal. The `night-job` preset gains six stat checks and uses them in `slip-past` and `lose-them`. See [odd/tasks/play-checks.md](../odd/tasks/play-checks.md). The prompt is kept for reference.

```text
Start feature `play-checks` (ODD). Check definitions in the release (dice expression + formula modifiers + outcome bands). Run checks from the sheet and from flow steps (a `check` step kind, ADR 0017), for a Character in the Scene cast (ADR 0018); log results to the journal. Settle the vision question "How far do structured checks go?" as an ADR (dice + formulas + outcome bands; scripting only on a real gap).
```

**13. preset-real-system**

Done: the `percentile-horror` preset (Basic Roleplaying under the ORC License, Kingsport mystery flow) in original wording, with a sheet, derived values, checks and oracles; guidance, scene and inspiration improvements from two playtests; and sheet effects ([ADR 0027](adr/0027-sheet-effects.md)), so the Flow sets Sanity to Power and takes Sanity and Hit points off on the checks. Items, inventory and `shop` moved post-M3 ([ADR 0025](adr/0025-items-and-shops-after-the-mvp.md)). This completes M3 and the MVP. The prompt is kept for reference.

```text
Start feature `preset-real-system` (ODD). Hand-author a release for a small, license-safe real game system (sheet, derived values, checks, flow). Play a full session with it and fix the schema gaps it reveals. Settle the open question on items, inventory and a `shop` step kind, or move it to a post-M3 feature (ADR 0018). This completes the MVP.
```

### M4 Studio

**14. studio-gamesystem-drafts**

Done: GameSystem drafts for a GAME_MANAGER (blank or copied from a published release; only the creator sees one, [ADR 0028](adr/0028-studio-gamesystem-drafts.md)), metadata editing, validation, publishing a draft as a new release with optional release notes through the existing release publisher, a version history with a read-only release view and restore into the draft, and the Studio shell (home, GameSystem page, publish dialog, Releases tab). The section editors are features 15 to 18b. The prompt is kept for reference.

```text
Start feature `studio-gamesystem-drafts` (ODD). Studio: GameSystem drafts (create, edit metadata, validate), publish as a new immutable release reusing the release-contract publisher, version history. Studio shell UX at Play quality.
```

**15. studio-oracle-editor**

Done: an Oracles tab on the GameSystem page and an editor for the oracle tables and likelihood oracles of a draft ([ADR 0029](adr/0029-studio-oracle-editor.md)): create and delete (refused while the draft uses the oracle, with the places listed), rename, ranged tables with live coverage, weighted tables, nested tables and entry keys, likelihood levels, chaos, exceptional percent and random event table, per-item saves with an expected version, and live test rolls and test answers of the saved draft through Randomness. Entry scene types, effects and the chaos tracker were kept and shown read-only until feature 18 edited them. The prompt is kept for reference.

```text
Start feature `studio-oracle-editor` (ODD). Studio: rich editor for oracle tables and likelihood oracles in a draft, with live test rolls through Randomness.
```

**18. studio-flow-editor**

Done: Trackers & tags, Scene Types and Flows tabs on the GameSystem page and list/outline editors for the NarrativeFlow model of a schema 2 draft ([ADR 0030](adr/0030-studio-flow-editor.md)): trackers, tags and fact slots; Scene Types with their setup, play and closing steps; flows with phases, selection, hooks and world turn; step kinds `prompt`, `character`, `roll`, `condition`, `oracle`, `table`, `choice`, `pick` and `check` with branches, bands and effects; oracle table entries gain a Scene Type and effects and likelihood oracles a chaos tracker (counters only). Per-item saves with an expected version, create and delete (refused while the draft uses the item, with the places listed), and server messages placed on the field they name. The publisher's rules are the editor's rules; unknown fields and shapes round-trip. The prompt is kept for reference.

```text
Start feature `studio-flow-editor` (ODD). Studio: a list/outline editor for the NarrativeFlow model of ADR 0017 as amended by ADR 0018 in a draft: Scene Types (purpose, tips, setup/play/closing steps, oracle shortcuts), trackers (hints, counter levels), tags, fact slots, and flows with phases (act label, mode, scene selection, scene, session and phase hooks, world turn), the oracles and trackers each flow selects, steps with mandatory/suggested, branches and effects. Validation matches the publisher. Existing presets (Mythic-style, guided sample) serve as starting points. No node-graph library (ADR 0004).
```

**16. studio-sheet-builder**

Done: a sheet builder for a schema 2 draft ([ADR 0031](adr/0031-sheet-layout-and-studio-sheet-builder.md)): fields of every type, a layout of declared groups in a grid, derived-value formulas with live validation, and a live preview filled with sample values that renders the sheet as Play does. The release contract gains an additive layout model, and Play renders it. See [odd/tasks/studio-sheet-builder.md](../odd/tasks/studio-sheet-builder.md). The prompt is kept for reference.

```text
Start feature `studio-sheet-builder` (ODD). Studio: visual sheet-template builder (fields, layout) and derived-value formula editor with live preview and validation.
```

**17. studio-check-editor**

Done: a Checks tab on the GameSystem page and an editor for the checks of a schema 2 draft ([ADR 0032](adr/0032-studio-check-editor.md)): create, rename, reorder and delete (refused while a check step uses the check or one of its bands, with the places listed), dice, modifier formula, optional difficulty and outcome bands with literal, tracker and formula bounds, and check-scope formula diagnostics. A test-roll sandbox beside a saved check rolls it on the server against the sample sheet the Sheet tab's preview also uses, with live band bounds and the last 5 results; nothing is recorded. The check contract is unchanged. The prompt is kept for reference.

```text
Start feature `studio-check-editor` (ODD). Studio: check editor (dice and formula editor, outcome bands) with a test-roll sandbox against a sample sheet.
```

**18b. studio-library**

Done: library packs of generic content (oracle tables, likelihood oracles, trackers, tags, fact slots and Scene Types) authored by any GAME_MANAGER ([ADR 0033](adr/0033-studio-library-packs.md)). A schema 2 draft imports pack items with the items they reference, a key the draft already has wins, and Studio flags imports whose pack item changed or was removed, to update or detach. Releases stay self-contained. Flows stay per GameSystem: no generic flows. See [odd/tasks/studio-library.md](../odd/tasks/studio-library.md). The prompt is kept for reference.

```text
Start feature `studio-library` (ODD). Studio: a library of generic content (oracle tables, likelihood oracles, Scene Types, trackers, fact slots) authored by any GAME_MANAGER. A GameSystem draft imports library items; publishing copies them into the release, so releases stay self-contained (ADR 0010, ADR 0017). A GameSystem overrides an item by key (e.g. a samurai `npc-name` table); every referenced key must resolve at publish. Flag drafts whose imported items changed in the library. Settle whether flows can be generic. Optional provenance in the release.
```

### M5 Admin and lifecycle

**19. admin-users**

Done: the owner lists users, invites by email, assigns roles and disables accounts, with password reset ([ADR 0034](adr/0034-admin-user-management.md)). See [odd/tasks/admin-users.md](../odd/tasks/admin-users.md). The prompt is kept for reference.

```text
Start feature `admin-users` (ODD). Admin: the owner lists users, invites by email (Mailpit in dev), assigns roles and disables accounts, through Identity & Access application services. Include password reset.
```

**20. play-release-upgrade**

```text
Start feature `play-release-upgrade` (ODD). Play: let a player upgrade a campaign to a newer GameSystem release, with a preview of the character data migration; nothing changes without confirmation.
```

Done: a player upgrades a campaign to the latest release of its GameSystem from the campaign header. A preview lists what changes (Character values fitted to the new sheet, dropped Tags, Tracker values, Campaign facts that lose their slot, guidance restarting at the first phase of the Flow); nothing changes until the player confirms, and the confirmed change is exactly the previewed one, checked by a fingerprint ([ADR 0035](adr/0035-campaign-release-upgrade.md)). See [odd/tasks/play-release-upgrade.md](../odd/tasks/play-release-upgrade.md). The prompt is kept for reference.

### Backlog

In build order; see the [Backlog](#backlog) table.

**B1. studio-atomic-write-read**

```text
Start feature `studio-atomic-write-read` (ODD), issue #195. Studio: run every editor write (narrative, oracle, sheet, check controllers and any other write-then-read endpoint) and its read-back query in one DBAL transaction, as `LibraryImportController` does, with an API test per controller where a failing read leaves the draft unchanged. Add the API test that a GameSystem row stored before the `kind` column reads as `game-system`. Out of scope: the 500 on a malformed library pack row. One slice, branch `fix/`.
```

Done: every Studio write-then-read endpoint runs its write and read-back in one transaction, with a rollback API test per controller, and the legacy `kind` row test (PR #214, closes #195); see [odd/tasks/studio-atomic-write-read.md](../odd/tasks/studio-atomic-write-read.md). The prompt is kept for reference.

**B2. identity-account-error-codes**

```text
Start feature `identity-account-error-codes` (ODD). Identity: the public account endpoints answer account-link errors with a machine-readable code next to the message (at least `invalid_account_link` and `weak_password`), documented in OpenAPI; the SPA account pages (`accountApi.ts`) branch on the code instead of matching "invalid or has expired". Decide whether the code joins the shared error shape for every endpoint or only these. API tests per code; `make api`. Out of scope: localizing messages. One slice.
```

Done: the public account endpoints answer every error with `{error, code}` (`AccountErrorResponse`, six codes in an OpenAPI enum; the shared `ErrorResponse` is unchanged) and the account pages branch on `invalid_account_link` (PR #217, closes #216); see [odd/tasks/identity-account-error-codes.md](../odd/tasks/identity-account-error-codes.md). The prompt is kept for reference.

**B3. e2e-shared-sign-in**

```text
Start feature `e2e-shared-sign-in` (ODD). Tooling: replace the per-spec Playwright sign-in helpers with one shared helper in `frontend/e2e/` (exact label lookups, as the specs use now), next to `mailpit.ts`. No behaviour change; `make e2e` stays green. One `chore/` slice.
```

Done: one shared Playwright sign-in helper next to `mailpit.ts` (PRs #220 and #221, closes #219); see [odd/tasks/e2e-shared-sign-in.md](../odd/tasks/e2e-shared-sign-in.md). The prompt is kept for reference.

**B4. play-guided-polish**

```text
Start feature `play-guided-polish` (ODD). Play: fix the open playtest findings of `preset-mythic-flow` (P7–P13) and `preset-guided-sample` (N8–N13), listed under "Playtest findings" in their feature docs. First check each one against the current code (later features fixed some in part, e.g. the New scene form hidden while a Flow guides) and drop the ones already fixed. Expected themes: interpret a roll step's outcome in the journal; journal lines for tracker effects and Scene Type switches; no "Next:" preview of a branch-only step or before the dice decide; scene numbers per session and none for world turns; guide tips and "So far" reset after a scene; links from list-update prompts to the Threads and NPCs panels; random events tied to their fate question; steps shown only when a condition holds. Decide per finding whether it is a Play fix or a contract change, and keep contract changes additive in schema version 2. Replay a short guided session in Chrome to confirm. Out of scope: new step kinds beyond what a finding needs.
```

Done: the open playtest findings P7–P13 and N8–N13 in four slices (PRs #223–#226, closes #222); see [odd/tasks/play-guided-polish.md](../odd/tasks/play-guided-polish.md). The prompt is kept for reference.

**B5. ui-theme-polish**

```text
Start feature `ui-theme-polish` (ODD). Frontend only: give the app depth and a game feel, following the design canvas https://claude.ai/artifact/6MWYwPaXvQVUJYb7cXGiSo (the campaign Play screen in the default theme and the five campaign themes, plus an inputs now-vs-proposed board). 1) Depth: a `--well` token per palette in `index.css` (sunken background plus inset shadow) used by Input, Textarea, NativeSelect and read-out surfaces (last roll, oracle answer, empty tracker pips); layered card shadows; a subtle background texture per theme. 2) Icons: lucide-react icons in tiles on PlayPanel headers, the step card, the journal and icon-only buttons; dice buttons drawn as their die shapes. 3) Per-theme embellishments as theme tokens and CSS, not per-component branches: label style, title treatment and mark, a divider ornament, panel frame, a drop cap on journal prose, tracker pip and icon tile shapes. Storybook stories per theme for the touched components. Out of scope: new themes, layout changes.
```

Done: depth, per-theme type, icon tiles and dice shapes, then title marks, divider ornaments, panel frames, drop caps and tracker pips (PRs #228 and #229, closes #227); see [odd/tasks/ui-theme-polish.md](../odd/tasks/ui-theme-polish.md). The prompt is kept for reference.

**B6. play-journal-attachments**

```text
Start feature `play-journal-attachments` (ODD). Play: write a journal note and attach hand-picked roll and oracle results from the current scene, shown together in the journal. Builds on the journal entries of `play-campaign-journal`, where each result is its own entry. Out of scope: attaching results from other scenes, editing entries.
```

**B7. preset-pbta**

```text
Start feature `preset-pbta` (ODD). Author a Powered by the Apocalypse preset in Studio, not as hand-written JSON: Thirsty Sword Lesbians or Bluebeard's Bride in original wording. 2d6 + stat moves with three outcome bands (miss, partial, full) fit the contract today, so no contract change is planned; log every Studio friction as a finding. Export the published release into `backend/presets/` so `make presets` keeps it. Fan-content rule (ADR 0018) and credits with the publisher's notice (ADR 0021). Play a session and log findings. This is the first end-to-end proof of M4 on a new system.
```

**B7b. studio-ui-polish**

```text
Start feature `studio-ui-polish` (ODD). Two parts, in order. 1) Design, before any code: a design canvas (an artifact, as for `ui-theme-polish`) of the Studio screens in the default light and dark themes: home, the GameSystem page and its tabs, the oracle, Trackers & tags, Scene Types, Flows, sheet and check editors, the test-roll sandboxes, the publish dialog and the Releases tab. Start from the B5 tokens (wells, card shadows, label type, icon tiles) and the Studio findings logged by `preset-pbta`. The user reviews the canvas; distil its decisions and values into the feature doc, so writers work from the doc. 2) Build: slices that apply the canvas and fix those findings. Frontend only unless a finding needs an API change; accessible names unchanged; `contrast.test.ts` rules. Out of scope: campaign themes in Studio, new editors.
```

**B8. rules-dice-pools**

```text
Start feature `rules-dice-pools` (ODD). Close the dice pool gaps of ADR 0024 once, before the presets that need them (Vaesen, VtM, West Marches): a dice count read from the sheet (pools sized by a stat or a formula), counting successes per die against a face threshold, pushed rolls (re-roll the dice that did not succeed, once, with a cost the release declares), and conditions as sheet effects (ADR 0027). Additive in schema version 2 unless the design needs a new version; record the design as an ADR. Every layer: contract and publisher, anti-corruption layer, Play checks (panel and `check` step), the Studio check editor and its test-roll sandbox, and the release upgrade planner (ADR 0035). Leave room for VtM's critical pairs and Hunger dice (a second die colour in the pool) without building them. Out of scope: exploding dice, opposed rolls, scripting.
```

**B8b. rules-flow-choices**

```text
Start feature `rules-flow-choices` (ODD). Close two flow gaps found by `preset-pbta` (S11, S12): sheet effects on `choice` options and on `prompt` answers, and a `check` step that offers a set of checks the player picks from (e.g. Defy Disaster with any of five stats). Additive in schema version 2 unless the design needs a new version; record the design as an ADR. Every layer: contract and publisher, anti-corruption layer, Play (`choice`, `prompt` and `check` steps), the Studio flow editor, and the release upgrade planner (ADR 0035). Then rework `hearts-and-rapiers` in Studio to use them, re-export it into `backend/presets/` and play one scene. Out of scope: multi-select choices, per-NPC values.
```

**B9. preset-vaesen**

```text
Start feature `preset-vaesen` (ODD). Author a Vaesen preset on the Year Zero SRD under Free League's free license, in original wording, in Studio where it can: attributes and skills, d6 pools with sixes as successes, pushed rolls and conditions from `rules-dice-pools`, a mystery investigation flow. Credits carry the license notice (ADR 0021). Play a session; fix the gaps it reveals and log UX findings.
```

**B10. preset-vtm-chronicle**

```text
Start feature `preset-vtm-chronicle` (ODD). First check that the Dark Pack license (non-commercial) fits; stop if it does not. Author a Vampire: the Masquerade night-court sandbox fan preset, following example 1 of docs/domain/flow-examples.md: a Session Zero that fills city, era, tone, ruling sect, prince and faction facts with tagged leader NPCs; ambition and desire Threads in fact slots, driven by session hooks; a chronicle loop with a night counter, a masquerade tracker and a hunters clock; world turns with night events. d10 pools come from `rules-dice-pools`; this feature adds critical pairs and Hunger dice to the check contract (every layer, as `rules-dice-pools` did). Fan-content rule (ADR 0018): no verbatim book text or page references; show the publisher's disclaimer.
```

**B11. play-items-economy**

```text
Start feature `play-items-economy` (ODD). Play and Studio contract: structured items with quantity, cost and slots on a Character, a currency, and a `shop` step kind that buys and sells against it, replacing the flat sheet `list` for gear (ADR 0025). Design it for its first user, `preset-cpr-campaign` (rent, lifestyle, Night Market), and record the design as an ADR. Every layer: contract, anti-corruption layer, Play, a Studio editor, and the release upgrade planner (how a `list` gear field maps to items on upgrade). Out of scope: crafting, encumbrance rules beyond slots.
```

**B12. preset-cpr-campaign**

```text
Start feature `preset-cpr-campaign` (ODD). Author a Cyberpunk RED campaign-in-acts fan preset, following example 4 of docs/domain/flow-examples.md: Act 1 making a name (gigs as nextScene chains, rep and heat, recruiting a crew), Act 2 the big job, Act 3 the twist and conclusion; acts as phases with an `act` label; a survival economy from `play-items-economy` (lifestyle and housing levels, Downtime, Night Market, Month's end). Prefer a second Flow in the `night-job` GameSystem (a new release), so `play-campaign-transfer` can carry a heist one-shot into it; decide during exploration. Fan-content rule (ADR 0018).
```

**B13. play-campaign-transfer**

```text
Start feature `play-campaign-transfer` (ODD). Play: start a new campaign from an existing one with another flow of the same GameSystem (e.g. the `night-job` heist one-shot that grows into the Cyberpunk RED campaign), carrying over Campaign facts, NPCs and Threads, and optionally Characters; the source campaign stays unchanged. Out of scope: moving between GameSystems.
```

**B14. play-places**

```text
Start feature `play-places` (ODD). Settle the vision question "How are places modeled (a map, regions, sites)?" for `preset-west-marches` and record it as an ADR: Campaign facts with a `place` slot, a places list beside NPCs and Threads, or regions with distance for encounter tables. Add a `select` sheet field type (options declared by the release) for a class or background. Every layer for both: contract, anti-corruption layer, Play, Studio and the release upgrade planner. Out of scope: a drawn map.
```

**B15. preset-west-marches**

```text
Start feature `preset-west-marches` (ODD). Author a West Marches preset on D&D 5e under SRD 5.2 (CC BY 4.0), following example 5 of docs/domain/flow-examples.md: a founding phase with a town and a roster of adventurers; an expeditions loop where each session picks a rumor and a party, travels, explores and returns to town before the session ends; encounter tables by distance. Uses places and a class `select` field (`play-places`), conditions (`rules-dice-pools`), shops (`play-items-economy`), and HP and spell slots as sheet effects (ADR 0027). Credits carry the CC BY 4.0 attribution (ADR 0021).
```

**B16. preset-ironsworn**

```text
Start feature `preset-ironsworn` (ODD). Optional. Author an Ironsworn preset under CC BY 4.0: challenge dice (an action score against two d10, each compared on its own), momentum that goes below zero and burns, and vow progress tracks. Name which are contract gaps (ADR 0024) and close them in every layer. Credits carry the license notice (ADR 0021).
```

## Related

- [Vision](vision.md)
- [Context map](domain/context-map.md)
- [Architecture decisions](adr/README.md)
