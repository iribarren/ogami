# GameSystem release contract

A **GameSystem release** is the Published Language between Studio and Play ([ADR 0010](../adr/0010-versioned-gamesystem-releases.md)): an immutable, versioned JSON document that describes one GameSystem. Studio validates and publishes it; Play reads it only through its anti-corruption layer. This page is the human reference; the machine-readable structure is the JSON Schema [`backend/contracts/gamesystem-release/v1.schema.json`](../../backend/contracts/gamesystem-release/v1.schema.json).

Terms follow the [glossary](../domain/glossary.md).

## Quick path

1. Write a release file that follows schema version 1 (see the [example](#example)).
2. Publish it: `bin/console app:gamesystem:publish <file> [--if-changed]` (from the host: `make console ARGS="app:gamesystem:publish <file>"`).
3. Play reads it with `GetPublishedRelease` through its anti-corruption layer.

## Two kinds of version

| Version | Field | Who sets it | Meaning |
|---|---|---|---|
| Schema version | `schemaVersion` in the file | The file author | Which version of **this contract** the file follows. Version 1 is the only one today |
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

## Rules

### Top level and `gameSystem`

| Path | Rule |
|---|---|
| top level | Exactly `schemaVersion`, `gameSystem`, `oracles`, `flow`, `sheet`, `checks` |
| `schemaVersion` | Must be `1`; any other value fails with "unsupported schema version" |
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

The step shape is provisional. It grows in a later schema version (feature `flow-presentation-spike`).

### `sheet` and `checks` (reserved)

`sheet` must be `{}` and `checks` must be `[]`. Any content fails with "not supported in schema version 1".

### Errors

Invalid content fails with one `InvalidReleaseContent` domain error. The message starts with the path of the offending value, e.g. `oracles.tables[2]: …` or `flow.steps[0].key: …`.

### Schema file vs domain

| Checked by | Rules |
|---|---|
| JSON Schema and domain | Required and unknown properties, types, key pattern, lengths, list sizes, numeric bounds, reserved `sheet` / `checks` |
| Domain only | Unique keys (oracle namespace, flow steps, levels), ranged vs weighted entries, overlapping ranges, nested tables exist, cycles and depth, dice notation, trimmed lengths, `target` and `shiftPerPoint` against `sides`, chaos ordering |

The domain is the source of truth at runtime; the schema file documents the structure and is tested against the same fixtures.

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
| Published | `Published <key> v<N>` | 0 |
| Same content with `--if-changed` | `Unchanged <key> (v<N>)` | 0 |
| Invalid content, malformed JSON, missing file | The error message | 1 |

`make presets` publishes the shipped [presets](../domain/glossary.md#game-authoring) (`backend/presets/*.json`) with `--if-changed`.

## How Play consumes it

| Step | What happens |
|---|---|
| 1 | Play's port `PublishedGameSystemReleases::get(key, ?version)` (Play Application) is called; `null` means latest |
| 2 | Its adapter in Play Infrastructure sends Studio's Application query `GetPublishedRelease(key, ?version)` through the query bus |
| 3 | Studio returns a `PublishedReleaseView {gameSystemKey, version, schemaVersion, publishedAt, content}`, or `PublishedReleaseNotFound` |
| 4 | Play's translator turns the view into a `GameSystemSnapshot` (Play Domain). Unknown schema versions and missing releases fail with Play's own errors |

Play never imports Studio `Domain/` or `Infrastructure/` (enforced by PHPat, [ADR 0012](../adr/0012-phpat-boundary-enforcement.md)).

## Evolving the contract

- A new capability means a **new schema version**: add `v2.schema.json` next to `v1.schema.json` and a new section here.
- Never edit `v1.schema.json` once released: published releases and presets depend on it.
- Studio validates each schema version it accepts; Play's translator lists the schema versions it supports explicitly and rejects others.
- Studio is a core context ([ADR 0013](../adr/0013-studio-is-a-core-context.md)): future versions may carry system-specific, even code-backed, flow or rule extensions.

## Example

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
