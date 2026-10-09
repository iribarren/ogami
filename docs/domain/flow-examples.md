# Flow examples

Five worked examples of the NarrativeFlow model of [ADR 0017](../adr/0017-narrativeflow-model.md), as amended by [ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md). Feature 6c `flow-model-examples` mapped them to find the model's limits.

They serve as:
- the reference for presets 9 `preset-mythic-flow`, 9b `preset-guided-sample` and the backlog presets ([roadmap](../roadmap.md));
- input for Studio's flow editor;
- feature 7's contract fixtures: schema version 2 JSON that must validate and pass the anti-corruption layer (ADR 0018, decision 26).

**Fan content.** The descriptions are original, not book text. Game names belong to their publishers. A preset built from an example follows the fan-content rule (ADR 0018, decision 29).

Terms follow the [glossary](glossary.md).

## How to read the examples

| Notation | Meaning |
|---|---|
| `prompt "…"`, `roll 1d6`, `table key`, `oracle "…"`, `choice "…"`, `pick`, `condition key` | A flow step of that kind |
| `upTo n → …` / `rest → …` | Outcome bands: ordered upper bounds; the last band catches the rest |
| `→ alarm +1`, `→ edge set 0` | A `tracker` effect (add or set) |
| `→ nextScene X`, `→ switchSceneType X`, `→ endPhase`, `→ sceneTitle {answer}` | Control-flow effects |
| `→ createNpc [tag]`, `createThread`, `closeThread`, `fillFact slot` | Content effects (features 8 and 8b) |
| `pick NPC [a, b]` | Pick an NPC carrying all listed tags; branches `found` / `none` |
| (suggested), (mandatory) | Step gate; steps are suggested unless marked mandatory |
| (8), (8b), (10), (M3) | The part needs that feature; feature 7's fixtures add it when the feature lands |

Every phase hook not listed is empty.

## 1. VtM chronicle (sandbox)

A Vampire: the Masquerade chronicle with no set plot. Session Zero builds the city and its factions as Campaign facts. The character's ambition turns into a goal at the start of every session.

### Release

| Part | Content |
|---|---|
| Trackers | `masquerade` counter 0–10, hint "At 8+ the Second Inquisition moves"; `hunters` clock 4; `night` counter |
| Fact slots | `city`, `era`, `tone`, `ruling-sect` (text); `prince` (npc); `ambition` (thread, hint "Long-term"); `desire` (thread, hint "Short-term, renewed each session") |
| Tags | `leader`, `ally`, `touchstone`, `rival`, `hunter`, `newcomer` |
| Scene Types | City, Court, Factions, Character; Elysium, Hunt, Investigation, Intrigue, Haven, Dawn; Hunters strike, Inquisition raid |
| Tables | `night-events`, `ally-problems`, `arrivals` |

### Flow `chronicle`

| Phase | Mode | Selection | Scene Types |
|---|---|---|---|
| `session-zero` | once | sequence | City → Court → Factions → Character |
| `chronicle` | loop | player | Elysium, Hunt, Investigation, Intrigue, Haven, Dawn |

**Session Zero**

```
City       prompt "Which city, which era, what tone?"   → fillFact city, era, tone
Court      prompt "Which sect rules the city?"           → fillFact ruling-sect
           prompt "Who is the Prince?"                   → createNpc [leader], fillFact prince
                                                           (e.g. Mithras)
           free fact "The Prince is a Ventrue", linked to Mithras
Factions   free play: one free fact per faction, plus its leader NPC [leader] with an agenda
Character  prompt "What is your ambition?" (mandatory)   → createThread, fillFact ambition
           prompt "Who keeps you human?"                 → createNpc [touchstone]
           prompt "Who stands by you?" (suggested)       → createNpc [ally]
```

**Chronicle**

```
sessionOpening
  prompt "Your ambition: {fact:ambition}. What do you want this session?" (mandatory)
    → createThread, fillFact desire

Dawn (Scene Type)
  the night ends → night +1; its steps cover the between-nights events

worldTurn
  roll 1d6
    upTo 3 → "A quiet night"
    rest   → table night-events, branching per entry:
      Faction move     → pick NPC [leader] → prompt "What does {picked} do toward their agenda?"
      Ally in trouble  → pick NPC [ally] → table ally-problems
      New arrival      → table arrivals → createNpc [newcomer]
      Touchstone       → pick NPC [touchstone] → prompt "What happens to {picked}?"
      Hunter activity  → hunters +1
                         → condition hunters: upTo 3 → —; rest → nextScene Hunters strike
  masquerade, in one of two authored styles:
    fixed threshold   condition masquerade: upTo 7 → —; rest → nextScene Inquisition raid
                      (Inquisition raid's closing: masquerade set 4)
    roll-under        roll 1d10: upTo {tracker:masquerade} → nextScene Inquisition raid; rest → —

sessionClosing
  choice "Did you get {fact:desire}?"  yes → closeThread; no → —
```

Hunters strike lowers `hunters` in its closing, or the threshold fires on every turn (authoring rule, ADR 0018 decision 7).

### What it shows

| Model part | Decisions |
|---|---|
| Sandbox goals: Threads in `thread` fact slots, driven by session hooks | 12, 21 |
| Factions as free facts plus a tagged leader NPC with an agenda | 15, 20 |
| `pick` by tag, table branching per entry | 4, 16 |
| Pressure and forced world turns: a clock, a `condition`, `nextScene` | 3, 7 |
| Bands bound to a tracker (roll-under) and fixed thresholds | 2 |
| Tracker and fact slot hints | 17 |
| A Session is a sitting; in-story time is the `night` counter and Dawn | 14 |

**Notes**
- A Session is one sitting, not one night. A sitting may hold several nights or part of one.
- Factions repeat through free play: there is no "for each".
- A per-Character ambition waits for several Characters (M3); one `ambition` slot serves until then.

## 2. Cyberpunk RED heist one-shot

A short heist flow for one sitting. Generic Scene Types (Social, Exploration) sit beside game-specific ones. An alarm clock drives the pressure. Feature 9b builds this example as its fan preset.

### Release

| Part | Content |
|---|---|
| Trackers | `alarm` clock 6, hint "At 6/6 security locks down: you run"; `edge` counter 0–3; `objective` counter 0–1 |
| Fact slots | `client` (npc); `target`, `payout`, `plan` (text) |
| Tags | `fixer`, `client`, `crew`, `nomad`, `netrunner`, `corp-security`, `rival` |
| Generic Scene Types | Social, Exploration. Library content later; duplicated in the release for now |
| Specific Scene Types | Crew, Briefing, Netrun, Infiltration, Firefight, Grab, Getaway, Chase, Payday |
| Tables | `job-clients`, `job-targets`, `payouts`, `complications`, `net-floors`, `black-ice`, `security-response`, `npc-name` (street names; overrides the generic table by key) |

`complications` entries carry effects: Patrol → `alarm +1`; Lucky break → `alarm −1`; Spotted! → `alarm +2`.

### Flow `heist`

`defaultView: focus`, with an introduction for novice players.

| Phase | Mode | Selection | Scene Types | Ends |
|---|---|---|---|---|
| `the-job` | once | sequence | Crew → Briefing | After Briefing |
| `legwork` | loop | player | Social, Exploration, Netrun | The player chooses "Go time" |
| `the-heist` | loop | player | Infiltration, Netrun, Grab | `endPhase` (alarm full, or the grab) |
| `escape` | once | sequence | Getaway (may switch to Chase) | After the scene |
| `epilogue` | once | sequence | Payday | FlowRun `completed` |

**The job**

```
Crew      prompt "Who is your first crew member?" (mandatory)  → createNpc [crew]
          prompt "Who else is in?" (suggested)                 → createNpc [crew]
Briefing  table job-clients → createNpc [client], fillFact client
          table job-targets → fillFact target
          table payouts     → fillFact payout
          prompt "What's the plan?"               → fillFact plan
          prompt "What's the catch?" (suggested)
```

**Legwork**

```
sceneClosing
  choice "Did you gain an edge?"  yes → edge +1; no → —
worldTurn
  roll 1d6: upTo 1 → "Word gets around" → alarm +1; rest → —
```

**The heist**

```
sceneOpening
  condition alarm: upTo 3 → —; upTo 5 → prompt "Security is on edge. What do you notice?"; rest → —

Infiltration play
  oracle "Do you slip past?"
    yes             → —
    no              → alarm +1
    exceptional no  → switchSceneType Firefight

Grab closing
  choice "Got it?"  yes → objective set 1, endPhase; no → —

sceneClosing
  condition edge: upTo 0 → (skip the choice); rest → choice "Spend an edge to avoid trouble?"
                                                       yes → edge −1, skip the complication
                                                       no  → table complications
  table complications            (entry effects move the alarm)
  condition alarm: upTo 5 → —; rest → endPhase

worldTurn
  condition alarm: upTo 3 → —; rest → roll 1d6: upTo 3 → nextScene Firefight; rest → —
```

The forced Firefight does not lower `alarm`, so validation warns (decision 7). In a heist the author accepts it: pressure only grows until the crew runs.

**Escape and epilogue**

```
Getaway setup
  condition alarm: upTo 3 → prompt "You slip out. How?"; rest → switchSceneType Chase
Chase setup
  pick NPC [crew, nomad]  found → prompt "{picked} takes the wheel"
                          none  → prompt "You take the wheel"
Payday
  condition objective: upTo 0 → prompt "No job, no pay."
                       rest   → prompt "The fixer pays {fact:payout}"
                                → roll 1d6: upTo 1 → prompt "Double-cross!"; rest → —
```

After the epilogue the FlowRun is `completed`; play continues unguided.

### What it shows

| Model part | Decisions |
|---|---|
| Generic and game-specific Scene Types in one flow; overrides by key | ADR 0017 §12 |
| A loop ending by the player's choice (legwork) or by `condition` + `endPhase` (heist) | 8 |
| In-place switches: Infiltration → Firefight, Getaway → Chase | 6 |
| Oracle branching on its answer; table entries with effects | 4, 5 |
| A `condition` gating a `choice` | 3 |
| Pressure and forced world turns | 7 |
| `pick` with two tags and a `none` branch; the picked NPC joins the Scene cast | 16, 22 |
| A `completed` FlowRun | 10 |

**Notes**
- Netrun floors use a suggested step plus the `net-floors` and `black-ice` shortcuts. Repeating a floor happens in free play (accepted limit).
- The Scene cast holds the crew members present (8).

## 3. Mythic-style session

A Mythic-style adventure: a chaos factor, a scene check, altered and interrupted scenes, list updates. Feature 9 builds this example. All table content is original.

### Release

| Part | Content |
|---|---|
| Trackers | `chaos` counter 1–9, initial 5, bound to the fate question; hint "High chaos: more yes answers, more surprises" |
| Tags | `rival`, `ally` |
| Scene Types | Scene: no setup; shortcuts to the fate question, the meaning tables and `random-event-focus` |
| Tables | `scene-adjustments`, `random-event-focus`, meaning tables (action, subject) |

### Flow `mythic`

`defaultView: journal`.

| Phase | Mode | Selection | Scene Types |
|---|---|---|---|
| `premise` | once | single type, picked automatically | Scene |
| `adventure` | loop | single type, picked automatically | Scene |

The `adventure` phase has no world turn.

**Premise**

```
sceneOpening
  prompt "What is the premise?" (mandatory)
  prompt "What is your first goal?" → createThread
  prompt "Who is involved?" (suggested) → createNpc
```

**Adventure**

```
sceneOpening
  prompt "What scene do you expect?" (mandatory) → sceneTitle {answer}
  roll 1d10
    upTo {tracker:chaos} → twist (next step)
    rest                 → the expected scene: go to play
  roll 1d6
    upTo 3 → altered: table scene-adjustments → prompt "How is the scene altered?"
    rest   → interrupted: table random-event-focus, branching per entry:
      Rival acts           → pick NPC [rival]   found → —; none → createNpc [rival]
      A stranger appears   → createNpc
      A lead gets clearer  → pick Thread
      A lead goes cold     → pick Thread
      A loose end settles  → pick Thread → choice "Close it?" yes → closeThread
      An ally needs help   → pick NPC [ally]
      other entries        → meaning tables (action, subject)
    → prompt "What happens instead?" → sceneTitle {answer}

play
  open, with the scene's shortcuts

sceneClosing
  choice "Were you in control?"  yes → chaos −1; no → chaos +1   (clamped to 1–9)
  prompt "Update your lists" (suggested)
```

### What it shows

| Model part | Decisions |
|---|---|
| The scene check as bands bound to the `chaos` tracker | 2 |
| Table branching per entry; `pick` with a `none` branch | 4, 16 |
| Scene titles from the player's answer | 1, 9, 11 |
| A single Scene Type picked automatically | 11 |
| A journal-first flow with no world turn | ADR 0017 §8 |

**Notes**
- Whether fate-question doubles trigger random events is an open question, settled in feature 9.
- The random event steps are written once, in the scene opening. Reusable procedures that could also run from the oracle panel are deferred (vision open question).

## 4. Cyberpunk RED campaign in acts

A long campaign: the crew starts small, survives on gigs and rises in three acts. It also shows a survival economy with M2 tools.

### Release

| Part | Content |
|---|---|
| Trackers | `rep` counter 0–10; `heat` counter 0–10, hint "At 8+ someone sends a hit squad"; `alarm` clock 6 and `edge` counter 0–3 (as in example 2); `lifestyle` counter with levels Kibble / Prepak / Good prepak / Fresh food; `housing` counter with named levels; `month` clock 4; `eddies` counter (M2 only) |
| Fact slots | `turf`, `crew-name`, `big-job`, `twist` (text); `fixer`, `nemesis` (npc) |
| Tags | `crew`, `fixer`, `nomad`, `netrunner`, `solo`, `client`, `rival`, `corp` |
| Scene Types | Character, Turf, Fixer; Gig offer, Legwork, Job, Payday, Downtime, Recruit, Social, Hit squad; Gear up, Fallout; the heist types of example 2; Betrayal, Showdown, Epilogue; Night Market, Month's end |
| Tables | `street-events`, `twists`, Night Market categories and items |

### Flow `campaign`

| Act | Phase | Mode | Selection | Scene Types |
|---|---|---|---|---|
| — | `street-zero` | once | sequence | Character → Turf → Fixer |
| Act 1: Making a name | `making-a-name` | loop | player | Gig offer, Downtime, Recruit, Social |
| Act 2: The big job | `planning` | loop | player | Legwork, Recruit, Gear up |
| Act 2: The big job | `the-job` | loop | player | The heist types of example 2 |
| Act 2: The big job | `fallout` | once | sequence | Fallout |
| Act 3: The twist and conclusion | `finale` | once | sequence | Betrayal → Showdown → Epilogue |

Focus mode progress reads e.g. `Act 1: Making a name › making-a-name › Legwork › closing · step 1/1`.

**Act 1: a gig is a `nextScene` chain**

```
Gig offer   setup:   alarm set 0
            closing: choice "Take it?"  yes → nextScene Legwork; no → —
Legwork     closing: choice "More legwork, or go?"  more → nextScene Legwork; go → nextScene Job
Job         a compressed heist (oracle, alarm, edge)  → nextScene Payday
Payday      condition alarm: upTo 3 → rep +2; rest → rep +1
            prompt "Any loose ends?" → createThread
Recruit     prompt "Who joins, and what do they do?" → createNpc [crew, plus a specialty tag]

worldTurn
  roll 1d6: upTo 4 → —; rest → table street-events, branching per entry:
    Rival crew    → pick NPC [rival] → prompt "What is {picked} after?"
    Gang war      → prompt "Whose streets burn?"
    Corp sweep    → heat +1
    Special gig   → nextScene Gig offer
    Grudge        → createNpc → fillFact nemesis
  condition heat: upTo 7 → —; rest → nextScene Hit squad   (Hit squad closing: heat set 4)

sceneClosing (the act ends)
  condition rep: upTo 4 → —
    rest → condition count NPC [crew]: upTo 1 → —
      rest → choice "Go big?"  yes → endPhase; no → —
```

**Act 2 and Act 3**

```
planning phaseOpening
  prompt "Act 2: The big job. What is it?" → fillFact big-job
  → alarm set 0, edge set 0

Betrayal
  pick NPC [fixer]  none → pick NPC [client]
  table twists → fillFact twist   (e.g. "{picked} sold you to {fact:nemesis}")
```

### Economy

M2 builds a survival economy from trackers, release-level Scene Types, tables and effects (decision 25).

```
Downtime closing
  → month +1
  condition month: upTo 3 → —; rest → nextScene Month's end

Night Market
  table categories → table items (per category)
  choice "Buy?"  yes → eddies −X (a literal per choice); no → —

Month's end
  condition housing: one band per level → choice "Pay the rent?"
    yes → eddies −rent, month set 0
    no  → lifestyle −1, pick NPC [fixer] → createThread "Debt to {picked}"
  choice "Move up?"  yes → housing +1
```

`eddies` is a campaign counter only in M2. It moves to the sheet in M3, with sheet list fields such as inventory and cyberware.

### What it shows

| Model part | Decisions |
|---|---|
| Acts as phases with an `act` label | 19 |
| Scene chains with `nextScene` | 1, 7 |
| Chained `condition` steps, including a tag count, gating a `choice` | 3 |
| A threshold consequence that lowers its tracker (`heat set 4`) | 7 |
| Phase hooks for act intros and resets | 12, 13 |
| Counter levels; an M2 economy | 18, 25 |
| `pick` fallbacks through `none` instead of "or" | 16 |

**Notes**
- Phases only go forward: a failed big job carries forward into `fallout` (accepted limit).
- Scene chains such as a gig are not shown as a group (accepted limit).
- A price per item needs one choice per price: items, inventory and a `shop` step kind are an open question.
- Sheet list fields (inventory, cyberware) and effects on them arrive in M3.

## 5. West Marches

Light old-school fantasy on the 5e SRD or Knave. A town, a roster of adventurers, and one expedition per session. Every session should end back in town.

### Release

| Part | Content |
|---|---|
| Trackers | `away` counter 0–10, hint "End the session in town, or the wilds keep you"; `rations` counter; `torches` clock 6 |
| Fact slots | `town` (text); `expedition` (thread, hint "Renewed each session") |
| Tags | `adventurer`, `hireling`, `patron`, `rival`, `monster` |
| Scene Types | Town, Roster, Travel, Explore site, Delve (one room), Encounter, Combat, Return |
| Tables | `rumors`, `encounters-near`, `encounters-mid`, `encounters-deep`, `site-features`, `rooms`, `stranded` |

### Flow `west-marches`

| Phase | Mode | Selection | Scene Types |
|---|---|---|---|
| `founding` | once | sequence | Town → Roster |
| `expeditions` | loop | player | Town, Travel, Explore site, Delve, Return (Encounter and Combat through `nextScene`) |

**Founding**

```
Town     prompt "What is the town like?" → fillFact town
         table rumors → createThread
         table rumors → createThread
Roster   prompt "Who is in the roster?" (mandatory, three times) → createNpc [adventurer]
         prompt "Anyone else?" (suggested, twice)               → createNpc [adventurer]
```

The roster holds tagged NPCs in M2 and Characters in M3 (10).

**Expeditions**

```
sessionOpening
  pick Thread, mode choose → fillFact expedition
  pick Characters [adventurer], mode choose, count {min 1, max 5} → the Session party
  → away set 0

Town          supplies and hirelings (createNpc [hireling], suggested)
Travel        → away +1, rations −1
Explore site  table site-features
Delve         table rooms → torches +1
              closing: choice "Next room, or leave?"  next → nextScene Delve; leave → nextScene Travel
Return        → away set 0

worldTurn
  condition away: upTo 0 → —   (in town)
    rest → roll 1d6: upTo 1 → condition away:
                                 upTo 1 → table encounters-near
                                 upTo 3 → table encounters-mid
                                 rest   → table encounters-deep
                               (entry effects: nextScene Encounter or Combat)
             rest → —

sessionClosing
  condition away: upTo 0 → prompt "Safe in town."; rest → table stranded
  choice "Is {fact:expedition} resolved?"  yes → closeThread; no → —
  table rumors → createThread (suggested)
```

### What it shows

| Model part | Decisions |
|---|---|
| Session hooks pick the expedition and the party | 12, 21, 23 |
| Several Characters per Campaign; the party seeds each Scene cast | 22, 23, 24 |
| "End session" between scenes runs the session closing | 14 |
| Bands on a tracker choose the encounter table | 2, 3 |
| Table entries with a `nextScene` effect | 5 |
| Scene chains for travel and delving | 1 |

**Notes**
- Places (map, regions, sites) are an open question. In M2 they are free Campaign facts.
- A dungeon repeats rooms only through a `nextScene` chain (accepted limit).
- Picking Characters needs feature 10; in M2 the party is chosen from NPCs tagged `adventurer`.
