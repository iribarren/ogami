# GameSystem release contract

A **GameSystem release** is the Published Language between Studio and Play ([ADR 0010](../adr/0010-versioned-gamesystem-releases.md)): an immutable, versioned JSON document that describes one GameSystem. Studio validates and publishes it; Play reads it only through its anti-corruption layer. This page is the human reference; the machine-readable structure is one JSON Schema per schema version: [`v1.schema.json`](../../backend/contracts/gamesystem-release/v1.schema.json) and [`v2.schema.json`](../../backend/contracts/gamesystem-release/v2.schema.json) in `backend/contracts/gamesystem-release/`.

Terms follow the [glossary](../domain/glossary.md).

## Quick path

1. Write a release file that follows schema version 1 (see the [example](#example-schema-version-1)) or schema version 2, which adds trackers, Scene Types and NarrativeFlows (see the [example](#example-schema-version-2)).
2. Publish it: `bin/console app:gamesystem:publish <file> [--if-changed]` (from the host: `make console ARGS="app:gamesystem:publish <file>"`).
3. Play reads it with `GetPublishedRelease` through its anti-corruption layer.

## Two kinds of version

| Version | Field | Who sets it | Meaning |
|---|---|---|---|
| Schema version | `schemaVersion` in the file | The file author | Which version of **this contract** the file follows: 1 or 2. Version 1 releases stay valid and mean "no flows". Studio validates and publishes both; Play reads version 2 once its anti-corruption layer lands |
| Release version | Not in the file | Studio, when publishing | Counts the releases of one GameSystem key: 1, 2, 3… |

## Structure (schema version 1)

```text
schemaVersion   1
gameSystem      { key, name, description? }
oracles         { tables: [OracleTable], likelihood: [LikelihoodOracle] }
flow            { steps: [FlowStep] }
sheet           {}    reserved
checks          []    reserved
```

Every object the contract defines rejects unknown properties. An optional field may be omitted or `null`; both mean "not set".

## Rules (schema version 1)

### Top level and `gameSystem`

| Path | Rule |
|---|---|
| top level | Exactly `schemaVersion`, `gameSystem`, `oracles`, `flow`, `sheet`, `checks` |
| `schemaVersion` | `1` here (`2`: see [schema version 2](#schema-version-2)); any other value fails with "unsupported schema version" |
| `gameSystem.key` | Key rule: `^[a-z0-9-]{1,64}$` |
| `gameSystem.name` | 1–100 characters |
| `gameSystem.description` | Optional, at most 2000 characters |

### `oracles.tables`

The [Randomness](../domain/context-map.md#randomness-shared-kernel) `OracleTableDefinition` shape, validated as one `OracleTableSet`. May be empty.

| Field | Rule |
|---|---|
| list | At most 50 tables; keys unique in the set |
| `key` | Key rule |
| `name` | 1–500 characters |
| `dice` | Optional dice notation (e.g. `1d6`). Present: **ranged** table. Absent: **weighted** table, rolled as 1dW for total weight W |
| `entries` | 1–1000 entries |
| `entries[].min`, `entries[].max` | Integers, ranged tables only; both or neither; `min ≤ max`; ranges must not overlap |
| `entries[].weight` | Integer ≥ 1, weighted tables only; 1 by default |
| `entries[].text` | 1–500 characters; may be empty only when the entry nests a table |
| `entries[].table` | Optional key of another table of the set to roll on next. Nesting must not form cycles (depth at most 10) |

### `oracles.likelihood`

Each item is `key` + `name` + a Randomness `LikelihoodOracleDefinition`. May be empty.

| Field | Rule |
|---|---|
| list | At most 20 likelihood oracles |
| `key` | Key rule |
| `name` | 1–500 characters |
| `sides` | Integer, 2–1000 |
| `levels` | 1–20 levels, keys unique: `{key, label (1–500), target (integer, 0..sides)}` |
| `chaos` | Optional `{min, max, neutral, shiftPerPoint}`, all integers; `-1000 ≤ min ≤ neutral ≤ max ≤ 1000`; `shiftPerPoint` 0..sides |
| `exceptionalPercent` | Optional integer, 0–50; 0 by default |

### Oracle key namespace

Oracle keys are unique across tables **and** likelihood oracles: one namespace, so a flow step can later name any oracle.

### `flow.steps` (provisional)

| Field | Rule |
|---|---|
| list | 0–100 steps |
| `key` | Key rule, unique in the flow |
| `title` | 1–100 characters |
| `prompt` | Optional, at most 2000 characters |

The step shape is provisional and no preset fills it. [Schema version 2](#schema-version-2) replaces `flow` with `flows`.

### `sheet` and `checks` (reserved)

`sheet` must be `{}` and `checks` must be `[]`. Any content fails with "not supported in schema version 1".

### Errors

Invalid content fails with one `InvalidReleaseContent` domain error. The message starts with the path of the offending value, e.g. `oracles.tables[2]: …` or `flow.steps[0].key: …`.

### Schema file vs domain (schema version 1)

| Checked by | Rules |
|---|---|
| JSON Schema and domain | Required and unknown properties, types, key pattern, lengths, list sizes, numeric bounds, reserved `sheet` / `checks` |
| Domain only | Unique keys (oracle namespace, flow steps, levels), ranged vs weighted entries, overlapping ranges, nested tables exist, cycles and depth, dice notation, trimmed lengths, `target` and `shiftPerPoint` against `sides`, chaos ordering |

The domain is the source of truth at runtime; the schema file documents the structure and is tested against the same fixtures.

## Schema version 2

Schema version 2 carries the NarrativeFlow model of [ADR 0017](../adr/0017-narrativeflow-model.md) as amended by [ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md): trackers, fact slots, Scene Types and flows. It replaces the provisional `flow` of version 1. Version 1 releases stay valid and publishable; Play reads them as a GameSystem with no flows.

> **Status:** Studio validates all of schema version 2: the envelope, trackers, fact slots, table entries, the chaos tracker, Scene Types with their steps, bands and effects, flows with their phases and selections, the references inside each flow and placeholders, and it prints the [authoring warnings](#authoring-warning) when publishing. Play still rejects version 2 releases ("unsupported schema version") until its anti-corruption layer lands.

Everything not listed here works as in version 1: the key rule, `gameSystem`, the oracle tables and likelihood oracles with their rules, the oracle key namespace, the reserved `sheet` and `checks` (now "not supported in schema version 2"), optional fields, unknown properties and errors.

### Structure

```text
schemaVersion  2
gameSystem     { key, name, description? }                       unchanged
oracles        { tables: [OracleTable], likelihood: [LikelihoodOracle] }
trackers       [Tracker]
factSlots      [FactSlot]
sceneTypes     [SceneType]
flows          [Flow]
sheet          {}   reserved
checks         []   reserved
```

There is no `flow` key. `trackers`, `factSlots`, `sceneTypes` and `flows` are required and may be empty; a release with no flows is played freely.

### Release parts

| Part | Shape | Rules |
|---|---|---|
| Oracle table entry | v1 fields + `key?`, `sceneType?`, `effects?: [Effect]` | `key` unique within its table; `sceneType` exists |
| Likelihood `chaos` | v1 fields + `tracker?` | A counter whose `min` / `max` equal the chaos `min` / `max`; its value is the chaos factor |
| Tracker | `{key, name (1–100), hint? (≤500), kind: counter, min, max, initial, levels?}` or `{key, name, hint?, kind: clock, segments (1–20)}` | At most 50; keys unique in their own namespace; `min ≤ initial ≤ max`, all within ±1000; a clock starts at 0 |
| Counter `levels` | `[{upTo?, label (1–100)}]` (≤20) | Same ordering as bands, literal `upTo` only |
| FactSlot | `{key, label (1–100), type: text \| npc \| thread}` | At most 100; keys unique. Declared only: feature 8b fills them |
| SceneType | `{key, name (1–100), purpose (1–500), tips? (≤2000), oracles: [key], setup: [Step], play: [Step], closing: [Step]}` | At most 100; keys unique; `oracles` (the Scene Type's shortcuts) unique and in the oracle namespace |
| Flow | `{key, name (1–100), description? (≤2000), introduction? (≤5000), default?, defaultView: focus \| journal, oracles: [key], trackers: [key], phases: [Phase] (1–20)}` | At most 20; keys unique; at most one `default: true`; `oracles` and `trackers` unique and existing |
| Phase | `{key, name (1–100), act? (1–100), mode: once \| loop, selection, sessionOpening?, sessionClosing?, phaseOpening?, phaseClosing?, sceneOpening?, sceneClosing?, worldTurn?}` | Keys unique in the flow. Each hook is a step list, empty by default |
| Selection | `{rule: sequence \| player, sceneTypes: [key] (1–20)}` or `{rule: oracle, table: key}` | Scene Types exist; `sequence` may repeat a type; `player` keys unique; every entry of the `oracle` table names a `sceneType` |

### Steps

A step list (`setup`, `play`, `closing` and each hook) holds at most 50 steps.

| Kind | Fields (beside the common ones) | Rules |
|---|---|---|
| common | `{key, kind, title (1–100), prompt? (≤2000), tip? (≤2000), mandatory?, next?, effects?: [Effect]}` | Key unique within its step list; `end` is reserved; `next` is a **later** key of the same list or `end` (forward only) |
| `prompt` | — | The player writes an answer |
| `oracle` | `oracle` (likelihood key), `likelihood?` (level key), `branches?: {yes?, no?, exceptionalYes?, exceptionalNo?}` | The oracle and level exist. An exceptional answer without its branch uses `yes` / `no` |
| `table` | `table` (table key), `branches?: [{entry, next?, effects?}]` (≤1000), `otherwise?: {next?, effects?}` | The table exists; `entry` is a key of an entry of the step's own table, unique in the list |
| `roll` | `dice`, `bands?: [Band]` (≤20) | Valid dice notation |
| `choice` | `options: [{key, label (1–100), next?, effects?}]` (2–10), `skip?` (option key) | Option keys unique; `skip` is one of them. A suggested (not mandatory) choice must name `skip` |
| `condition` | `tracker`, `bands: [Band]` (1–20) | The tracker exists. Never `mandatory`; never skipped; writes no journal entry |

Every `next` (of a step, band, branch, option or `otherwise`) follows the forward-only rule of the step list it is in. Branches and `otherwise` are `{next?, effects?}`.

### Bands and effects

| Part | Shape | Rules |
|---|---|---|
| Band | `{upTo?: int \| {tracker: key}, next?, effects?}` | Ordered upper bounds: every band but the last has `upTo`; the last omits it and catches the rest; literal `upTo` values strictly increase |
| Effect | `{kind: tracker, tracker, op: add \| set, value: int (±1000) \| {tracker: key}}` · `{kind: nextScene, sceneType}` · `{kind: switchSceneType, sceneType}` · `{kind: endPhase}` · `{kind: sceneTitle, title (1–100)}` | At most 10 per list; referenced trackers and Scene Types exist |

### References inside a flow

A Scene Type is **reachable** from a flow through:
- its phase selections (`sequence`, `player`, and the entries of an `oracle` selection table);
- `nextScene` and `switchSceneType` effects;
- table entries whose `sceneType` is set, in the tables the flow rolls: its oracle selection tables, the tables of its `table` steps and of its reachable Scene Types' `table` steps, and the tables those tables nest.

| Rule | Detail |
|---|---|
| Trackers | Every tracker reference (effects, `{tracker: key}` values and bands, `condition`, `{tracker:key}` placeholders) in the flow's phases, its reachable Scene Types and the entries of the tables it rolls is in `flow.trackers` |
| Scene Type shortcuts | Every reachable Scene Type has `oracles ⊆ flow.oracles` |
| Oracles | `oracle` and `table` step keys exist in the release (not necessarily in `flow.oracles`) |

### Placeholders

A `{…}` matching `\{[a-z]+(:[a-z0-9-]+)?\}` in a step `title`, `prompt` or `tip`, or in effect text (a `sceneTitle` title), is a placeholder; other braces are text.

| Placeholder | Allowed where | Must name |
|---|---|---|
| `{tracker:key}` | Any placeholder text | A tracker of the flow (outside any flow: of the release) |
| `{step:key}` | Any placeholder text | A step key of the flow's phases or reachable Scene Types (outside any flow: of the release) |
| `{answer}` | Effect text only | The answer of the step the effect belongs to |

Any other placeholder fails with "not supported in schema version 2". Feature 8 adds `{picked}` and 8b `{fact:key}`.

### Authoring warning

Decision 7 of ADR 0018: a threshold consequence must lower its tracker, or it fires on every turn.

In one step list, when a `condition` on tracker T is followed (in the same step or a later step) by a `nextScene` S effect, and Scene Type S has no effect lowering T (`set`, or `add` with a negative literal), the release is still valid and publishing succeeds with a warning:

```text
flows[0].phases[1].worldTurn[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn
```

Only S's own step effects count as lowering T (in any of its parts, including bands, branches and options). Effects of table entries that S rolls do not: they lower T only by chance, so a forced Scene Type whose relief comes from a rolled entry still warns.

The rule applies to the step lists of flow phases and of Scene Types (`sceneTypes[i].play[j]: …`). Warnings are returned with the validated content and printed by the console command; they are not stored.

### Canonical form

As in version 1: keys in schema order, absent or `null` optionals omitted, integer-valued floats as integers, strings as given. Also, an optional list that is empty (`effects`, hooks, `levels`, `bands` of a `roll`, `branches` of a `table`), a `false` flag (`default`, `mandatory`), an empty outcome (`{}` as a branch or `otherwise`) and `branches` of an `oracle` step with no outcome mean the same as absent and are omitted. A band with no field (`{}`) stays `{}`.

### Schema file vs domain (schema version 2)

| Checked by | Rules |
|---|---|
| JSON Schema and domain | The version 1 structural rules; the shape of each kind of tracker, selection, step and effect; enums (`kind`, `op`, `mode`, `rule`, `type`, `defaultView`); list sizes and lengths above; numeric bounds; `end` not a step key; a `condition` never `mandatory`; unique key lists (Scene Type and flow `oracles`, flow `trackers`, `player` Scene Types) |
| Domain only | The version 1 semantic rules; unique keys (trackers, fact slots, Scene Types, flows, phases, steps, options, entries, table branches); at most one default flow; `min ≤ initial ≤ max`; the chaos tracker; forward-only `next`; band ordering; every reference above; the trackers and oracles of each flow; oracle selection tables; suggested choices naming `skip`; dice notation; placeholders; trimmed lengths |

## Publishing

| Aspect | Rule |
|---|---|
| Version | Latest release version of that `gameSystem.key` + 1; the first is 1 |
| Immutability | A release never changes; no update path exists. `(key, version)` is unique |
| Stored | Id, key, release version, schema version, normalized content, content hash (sha256 of the canonical normalized JSON), `publishedAt` |
| `--if-changed` | When the latest release of that key has the same content hash, nothing is published |

Console:

```bash
bin/console app:gamesystem:publish <file> [--if-changed]
```

| Outcome | Output | Exit |
|---|---|---|
| Published | `Published <key> v<N>`, after a `Warning: <message>` line per [authoring warning](#authoring-warning) | 0 |
| Same content with `--if-changed` | `Unchanged <key> (v<N>)`, after the warning lines | 0 |
| Invalid content, malformed JSON, missing file | The error message | 1 |

Warnings never stop a publish and are not stored; the content hash ignores them. The console checks the content first (Studio query `CheckGameSystemRelease`), so the warnings come before the publish outcome.

`make presets` publishes the shipped [presets](../domain/glossary.md#game-authoring) (`backend/presets/*.json`) with `--if-changed`.

## How Play consumes it

| Step | What happens |
|---|---|
| 1 | Play's port `PublishedGameSystemReleases::get(key, ?version)` (Play Application) is called; `null` means latest |
| 2 | Its adapter in Play Infrastructure sends Studio's Application query `GetPublishedRelease(key, ?version)` through the query bus |
| 3 | Studio returns a `PublishedReleaseView {gameSystemKey, version, schemaVersion, publishedAt, content}`, or `PublishedReleaseNotFound` |
| 4 | Play's translator turns the view into a `GameSystemSnapshot` (Play Domain). Unknown schema versions and missing releases fail with Play's own errors. The translator supports schema version 1 today; feature 7 `play-flow-run` adds version 2, and a version 1 release becomes a snapshot with no flows |

Play never imports Studio `Domain/` or `Infrastructure/` (enforced by PHPat, [ADR 0012](../adr/0012-phpat-boundary-enforcement.md)).

## Evolving the contract

- A new capability means a **new schema version**: add `v<N>.schema.json` next to the others and a new section here, as [schema version 2](#schema-version-2) did.
- Never edit a released schema file (`v1.schema.json`, `v2.schema.json`): published releases and presets depend on it.
- Studio validates each schema version it accepts; Play's translator lists the schema versions it supports explicitly and rejects others.
- Studio is a core context ([ADR 0013](../adr/0013-studio-is-a-core-context.md)): future versions may carry system-specific, even code-backed, flow or rule extensions.

## Example (schema version 1)

A complete, valid release file with a ranged table, a weighted table, a likelihood oracle with chaos and one flow step:

```json
{
  "schemaVersion": 1,
  "gameSystem": {
    "key": "example-journal",
    "name": "Example journal",
    "description": "A minimal game system that shows every part of the contract."
  },
  "oracles": {
    "tables": [
      {
        "key": "weather",
        "name": "Weather",
        "dice": "1d6",
        "entries": [
          {"min": 1, "max": 4, "text": "Clear"},
          {"min": 5, "max": 6, "text": "Storm", "table": "storm-kind"}
        ]
      },
      {
        "key": "storm-kind",
        "name": "Storm kind",
        "entries": [
          {"text": "Rain", "weight": 2},
          {"text": "Hail"}
        ]
      }
    ],
    "likelihood": [
      {
        "key": "fate",
        "name": "Fate question",
        "sides": 100,
        "levels": [
          {"key": "unlikely", "label": "Unlikely", "target": 35},
          {"key": "even", "label": "50/50", "target": 50},
          {"key": "likely", "label": "Likely", "target": 65}
        ],
        "chaos": {"min": 1, "max": 9, "neutral": 5, "shiftPerPoint": 5},
        "exceptionalPercent": 20
      }
    ]
  },
  "flow": {
    "steps": [
      {"key": "set-scene", "title": "Set the scene", "prompt": "Where are you and what do you want?"}
    ]
  },
  "sheet": {},
  "checks": []
}
```

## Example (schema version 2)

A complete, valid release file with a clock, a counter with levels bound to the chaos factor, one fact slot, three Scene Types and one flow of three phases (one per selection rule). It uses every step kind, every effect kind, bands, branches and the three placeholders. The forced Firefight lowers the alarm, so it publishes without a warning.

```json
{
  "schemaVersion": 2,
  "gameSystem": {
    "key": "example-heist",
    "name": "Example heist",
    "description": "A small game system that shows the parts of schema version 2."
  },
  "oracles": {
    "tables": [
      {
        "key": "heist-scenes",
        "name": "Heist scenes",
        "dice": "1d6",
        "entries": [
          {"min": 1, "max": 4, "text": "Sneak in", "sceneType": "infiltration"},
          {"min": 5, "max": 6, "text": "Shots fired", "sceneType": "firefight"}
        ]
      },
      {
        "key": "complications",
        "name": "Complications",
        "entries": [
          {"text": "Patrol", "key": "patrol", "effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": 1}]},
          {"text": "Lucky break", "key": "lucky-break", "effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": -1}]},
          {"text": "Spotted!", "key": "spotted", "sceneType": "firefight"}
        ]
      }
    ],
    "likelihood": [
      {
        "key": "fate",
        "name": "Fate question",
        "sides": 100,
        "levels": [
          {"key": "unlikely", "label": "Unlikely", "target": 35},
          {"key": "even", "label": "50/50", "target": 50},
          {"key": "likely", "label": "Likely", "target": 65}
        ],
        "chaos": {"min": 1, "max": 9, "neutral": 5, "shiftPerPoint": 5, "tracker": "chaos"},
        "exceptionalPercent": 20
      }
    ]
  },
  "trackers": [
    {"key": "alarm", "name": "Alarm", "hint": "At 6/6 security locks down: you run", "kind": "clock", "segments": 6},
    {"key": "edge", "name": "Edge", "kind": "counter", "min": 0, "max": 3, "initial": 0, "levels": [{"upTo": 0, "label": "No edge"}, {"label": "An edge"}]},
    {"key": "chaos", "name": "Chaos factor", "kind": "counter", "min": 1, "max": 9, "initial": 5}
  ],
  "factSlots": [
    {"key": "target", "label": "Target", "type": "text"}
  ],
  "sceneTypes": [
    {
      "key": "legwork",
      "name": "Legwork",
      "purpose": "Learn about the target.",
      "oracles": ["fate"],
      "setup": [
        {"key": "goal", "kind": "prompt", "title": "What do you want to learn?", "mandatory": true}
      ],
      "play": [],
      "closing": [
        {
          "key": "gain-edge",
          "kind": "choice",
          "title": "Did you gain an edge?",
          "options": [
            {"key": "yes", "label": "Yes", "effects": [{"kind": "tracker", "tracker": "edge", "op": "add", "value": 1}]},
            {"key": "no", "label": "No"}
          ],
          "skip": "no"
        }
      ]
    },
    {
      "key": "infiltration",
      "name": "Infiltration",
      "purpose": "Get inside unseen.",
      "oracles": ["fate", "complications"],
      "setup": [],
      "play": [
        {
          "key": "slip-past",
          "kind": "oracle",
          "title": "Do you slip past?",
          "oracle": "fate",
          "likelihood": "even",
          "branches": {
            "no": {"effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": 1}]},
            "exceptionalNo": {"effects": [{"kind": "switchSceneType", "sceneType": "firefight"}]}
          }
        },
        {
          "key": "complication",
          "kind": "table",
          "title": "What gets in the way?",
          "table": "complications",
          "branches": [{"entry": "spotted", "effects": [{"kind": "switchSceneType", "sceneType": "firefight"}]}],
          "otherwise": {"next": "end"}
        }
      ],
      "closing": []
    },
    {
      "key": "firefight",
      "name": "Firefight",
      "purpose": "Shoot your way out.",
      "tips": "Keep it short and loud.",
      "oracles": ["fate"],
      "setup": [
        {
          "key": "who-shoots",
          "kind": "prompt",
          "title": "Who opens fire?",
          "effects": [
            {"kind": "tracker", "tracker": "alarm", "op": "set", "value": 0},
            {"kind": "sceneTitle", "title": "Firefight: {answer}"}
          ]
        }
      ],
      "play": [],
      "closing": []
    }
  ],
  "flows": [
    {
      "key": "heist",
      "name": "Heist",
      "description": "Plan the job, break in, get out.",
      "introduction": "Learn what you can, then go in. Watch the alarm.",
      "default": true,
      "defaultView": "focus",
      "oracles": ["fate", "complications"],
      "trackers": ["alarm", "edge", "chaos"],
      "phases": [
        {
          "key": "legwork",
          "name": "Legwork",
          "act": "Act 1",
          "mode": "loop",
          "selection": {"rule": "player", "sceneTypes": ["legwork"]},
          "sessionOpening": [
            {"key": "recap", "kind": "prompt", "title": "Where did you leave off?"}
          ],
          "worldTurn": [
            {
              "key": "word",
              "kind": "roll",
              "title": "Does word get around?",
              "dice": "1d6",
              "bands": [
                {"upTo": 1, "effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": 1}]},
                {}
              ]
            }
          ]
        },
        {
          "key": "the-heist",
          "name": "The heist",
          "act": "Act 2",
          "mode": "loop",
          "selection": {"rule": "oracle", "table": "heist-scenes"},
          "sceneOpening": [
            {"key": "pressure", "kind": "condition", "title": "How alert is security?", "tracker": "alarm", "bands": [{"upTo": 3, "next": "end"}, {}]},
            {"key": "notice", "kind": "prompt", "title": "Security is on edge", "prompt": "The alarm is at {tracker:alarm}. What do you notice?"}
          ],
          "sceneClosing": [
            {"key": "lockdown", "kind": "condition", "title": "Is the alarm full?", "tracker": "alarm", "bands": [{"upTo": 5}, {"effects": [{"kind": "endPhase"}]}]}
          ],
          "worldTurn": [
            {"key": "response", "kind": "condition", "title": "Does security respond?", "tracker": "alarm", "bands": [{"upTo": 3, "next": "end"}, {}]},
            {"key": "guards", "kind": "roll", "title": "Do the guards find you?", "dice": "1d6", "bands": [{"upTo": 3, "effects": [{"kind": "nextScene", "sceneType": "firefight"}]}, {}]}
          ]
        },
        {
          "key": "getaway",
          "name": "Getaway",
          "mode": "once",
          "selection": {"rule": "sequence", "sceneTypes": ["firefight"]},
          "phaseClosing": [
            {"key": "debrief", "kind": "prompt", "title": "Was {step:goal} worth it?"}
          ]
        }
      ]
    }
  ],
  "sheet": {},
  "checks": []
}
```
