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
| Schema version | `schemaVersion` in the file | The file author | Which version of **this contract** the file follows: 1 or 2. Version 1 releases stay valid and mean "no flows". Studio validates and publishes both; Play reads both (version 2 flows from slice 8 of `play-flow-run`) |
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

> **Status:** Studio validates all of schema version 2: the envelope, trackers, fact slots, tags, `pick` steps, `condition` counts, the NPC and Thread effects, `fillFact`, table entries, the chaos tracker, Scene Types with their steps, bands and effects, flows with their phases and selections, the references inside each flow and placeholders, the `sheet` with its four field types, the `character` step and `of: character` in `pick` and `condition` ([ADR 0022](../adr/0022-character-sheets-in-schema-version-2.md)), and it prints the [authoring warnings](#authoring-warning) when publishing. Play reads all of version 2: trackers, fact slots and their hints, tags, table entry metadata, the chaos tracker, Scene Types with their steps, flows with their phases, Scene selections and hooks, `pick` steps, `condition` counts, the NPC and Thread effects and `{picked}`, and the `sheet`'s fields. It fails closed on the `character` step and on `of: character` until slice 4 of `play-characters`. It also applies `fillFact` and renders `{fact:key}` (see [Play behaviour of the fact parts](#play-behaviour-of-the-fact-parts)).

Everything not listed here works as in version 1: the key rule, `gameSystem`, the oracle tables and likelihood oracles with their rules, the oracle key namespace, the reserved `checks` (now "not supported in schema version 2"), the `sheet` (version 2 extends it, see [Sheet](#sheet-additive-in-schema-version-2)), optional fields, unknown properties and errors.

### Structure

```text
schemaVersion  2
gameSystem     { key, name, description?, credits? }             credits is new
theme          parchment | neon | noir | eldritch | starship   optional
oracles        { tables: [OracleTable], likelihood: [LikelihoodOracle] }
trackers       [Tracker]
factSlots      [FactSlot]
tags           [Tag]   optional
sceneTypes     [SceneType]
flows          [Flow]
sheet          { fields?: [Field] }   fields are new (additive), see Sheet
checks         []   reserved
```

There is no `flow` key. `trackers`, `factSlots`, `sceneTypes` and `flows` are required and may be empty; a release with no flows is played freely. `tags` is optional and empty by default. `theme` is optional (see [Theme](#theme)).

### Release parts

| Part | Shape | Rules |
|---|---|---|
| Oracle table entry | v1 fields + `key?`, `sceneType?`, `effects?: [Effect]` | `key` unique within its table; `sceneType` exists |
| Likelihood `chaos` | v1 fields + `tracker?` | A counter whose `min` / `max` equal the chaos `min` / `max`; its value is the chaos factor |
| Likelihood `randomEvent` | `{table}` (optional on a likelihood oracle) | `table` is the key of an oracle table of the release; the oracle has `chaos` and `sides` 100. A d100 roll that is a double (11, 22 … 99) whose digit is at most the chaos factor used is a random event: the answer names the table, and Play offers to roll it. No flow step runs ([ADR 0019](../adr/0019-random-events-from-fate-question-doubles.md)) |
| Tracker | `{key, name (1–100), hint? (≤500), kind: counter, min, max, initial, levels?}` or `{key, name, hint?, kind: clock, segments (1–20)}` | At most 50; keys unique in their own namespace; `min ≤ initial ≤ max`, all within ±1000; a clock starts at 0 |
| Counter `levels` | `[{upTo?, label (1–100)}]` (≤20) | Same ordering as bands, literal `upTo` only |
| FactSlot | `{key, label (1–100), type: text \| npc \| thread, hint? (≤500)}` | At most 100; keys unique. A `null` hint is omitted, as for trackers and tags. `fillFact` fills a slot |
| Tag | `{key, label (1–50), hint? (≤500)}` | At most 50; keys unique (own namespace). One list for NPCs and Threads; `pick`, `count` and the NPC and Thread effects name tags by key |
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
| `condition` | exactly one of `tracker` or `count: {of: npc \| thread \| character, tags?: [key] (≤5)}`, plus `bands: [Band]` (1–20) | The tracker exists, or the tags exist and are unique. A `count` compares the number of open NPCs or Threads, or of Characters, carrying all the tags to the bands. Never `mandatory`; never skipped; writes no journal entry |
| `pick` | `of: npc \| thread \| character`, `tags?: [key]` (≤5), `mode: random \| choose`, `count?: {min, max}`, `branches?: {found?, none?}` | Tags exist and are unique; `1 ≤ min ≤ max ≤ 10`, default `{min: 1, max: 1}`; branches are `{next?, effects?}`. `mandatory` allowed. Picks open items (every Character) carrying all the tags: `random` draws, `choose` lets the player select |
| `character` | — | Completes with a Character of the Campaign (see [Sheet](#sheet-additive-in-schema-version-2)). `mandatory`, `next` and `effects` as for `prompt`; no branches. `{step:key}` may name it |

Every `next` (of a step, band, branch, option, `otherwise` or `pick` branch) follows the forward-only rule of the step list it is in. Branches and `otherwise` are `{next?, effects?}`.

### Bands and effects

| Part | Shape | Rules |
|---|---|---|
| Band | `{upTo?: int \| {tracker: key}, next?, effects?}` | Ordered upper bounds: every band but the last has `upTo`; the last omits it and catches the rest; literal `upTo` values strictly increase |
| Effect | `{kind: tracker, tracker, op: add \| set, value: int (±1000) \| {tracker: key}}` · `{kind: nextScene, sceneType}` · `{kind: switchSceneType, sceneType}` · `{kind: endPhase}` · `{kind: sceneTitle, title (1–100)}` · `{kind: createNpc, name? (1–100), agenda? (≤2000), tags?: [key] (≤5)}` · `{kind: createThread, name? (1–200), tags?: [key] (≤5)}` · `{kind: closeThread}` · `{kind: fillFact, slot, text? (1–2000)}` | At most 10 per list; referenced trackers, Scene Types, tags and fact slots exist; `tags` unique; `name`, `agenda` and `text` are placeholder text; `fillFact` `text` is allowed on a `text` slot only (on an `npc` or `thread` slot the value is the picked item) |

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

A `{…}` matching `\{[a-z]+(:[a-z0-9-]+)?\}` in a step `title`, `prompt` or `tip`, or in effect text (a `sceneTitle` title, a `createNpc` `name` and `agenda`, a `createThread` `name`, a `fillFact` `text`), is a placeholder; other braces are text.

| Placeholder | Allowed where | Must name |
|---|---|---|
| `{tracker:key}` | Any placeholder text | A tracker of the flow (outside any flow: of the release) |
| `{step:key}` | Any placeholder text | A step key of the flow's phases or reachable Scene Types (outside any flow: of the release) |
| `{fact:key}` | Any placeholder text, including `fillFact` `text` | A fact slot of the release (fact slots are not per flow); `{fact}` without a key fails |
| `{answer}` | Effect text only | The answer of the step the effect belongs to |
| `{picked}` | Any placeholder text | Nothing: no argument (`{picked:x}` fails). The names of the items most recently picked or created in the current Scene |

Any other placeholder fails with "not supported in schema version 2".

### Play behaviour of the NPC and Thread parts

Play ([ADR 0018](../adr/0018-narrativeflow-control-flow-and-cast.md)) plays them against the campaign's NPCs and Threads, which the player also edits at any time. The candidates of a `pick` and the items a `count` counts are the **open** items of the kind carrying **all** the listed tags; the player's free tags never match.

| Part | In Play |
|---|---|
| `pick` `random` | The player presses Pick; the server draws `min(max, candidates)` items, weighted by their `weight`, without replacement |
| `pick` `choose` | The step lists the candidates; the player selects between `min` and `max` of them |
| `pick` outcome | `found` or `none`. Fewer candidates than `min` is `none` (the step shows "No match"). The outcome's `branches` apply after the step's own `effects`. The step writes a journal entry of kind `pick` with the names picked; a skipped `pick` changes nothing |
| `{picked}` | The names of the items most recently picked or created in the current Scene, joined with ", ", read when rendered; empty before any pick in that Scene. `{answer}` after a `pick` is the same list |
| `condition` `count` | The number of such open items, compared to the bands like a tracker value |
| `createNpc` / `createThread` | Creates an open item of weight 1 with the effect's tags and the rendered `name` (`agenda` for an NPC); an omitted or empty name becomes `New <label of the first tag>`, `New NPC` or `New Thread`. The item becomes `{picked}`; a created NPC joins the current Scene's cast. With 500 items of the kind already held nothing is created |
| `closeThread` | Closes the open Threads in `{picked}`; nothing happens when it holds none |

Picked and created NPCs join the current Scene's cast, which the player can edit. Scenes stored before schema version 2 gained these parts read with an empty cast.

### Theme

A release may name one curated **Theme**, which Play applies to the whole screen while a Campaign pinned to that release is open. The set is closed: `parchment` and `starship` are light, `neon`, `noir` and `eldritch` are dark, and each theme fixes its own mode and heading font, so inside a themed Campaign the theme overrides the player's Light / Dark / System choice. A release without `theme` (or with `null`) uses the default app theme.

| Part | Shape | Rules |
|---|---|---|
| Release `theme` | optional top-level string | One of `parchment`, `neon`, `noir`, `eldritch`, `starship` (JSON Schema `enum`, mirrored by the Studio validator). Any other value, or a non-string, is rejected. Omitted from the canonical form when absent, so a release without it keeps its hash |

Adding it is additive: every release valid before stays valid and reads the same. Play's translator reads it into the snapshot and fails closed on an unknown name (a stored release is not trusted); the Campaign response carries it as `pinnedRelease.theme` (`null` for the default app theme). The palettes live in the client, not in the release.

### Credits

A release may carry **credits** ([ADR 0021](adr/0021-release-credits-for-fan-presets.md)): the notice, license and link a fan preset shows for its publisher's disclaimer.

| Part | Shape | Rules |
|---|---|---|
| `gameSystem.credits` | optional object, or `null` | Exactly `notice`, `license`, `url`; any other property is rejected |
| `credits.notice` | required string | 1–2000 characters, not blank once trimmed |
| `credits.license` | optional string, or `null` | At most 200 characters. The name of the license or policy |
| `credits.url` | optional string, or `null` | At most 500 characters, an absolute `https://` URL with a host (`http://` and relative URLs are rejected) |

Adding it is additive and only in schema version 2; a schema version 1 `gameSystem` still rejects `credits`. Null fields are omitted from the canonical form, so a release without credits keeps its hash. Play's translator reads credits into the snapshot, a release stored without them reads as no credits, and the API carries them as `credits` on each game system of the catalog and as `pinnedRelease.credits` on a Campaign (`null` without credits).

### Sheet (additive in schema version 2)

A release may define a **sheet template** ([ADR 0022](../adr/0022-character-sheets-in-schema-version-2.md)): the fields every Character of a Campaign pinned to it fills in. Adding it is additive and only in schema version 2; every release valid before stays valid with the same hash. A schema version 1 `sheet` must still be `{}`.

| Part | Shape | Rules |
|---|---|---|
| `sheet` | object | Exactly `fields`; any other property is rejected. `{}`, `{"fields": []}` and `{"fields": null}` are the same sheet and canonicalize to `{}` |
| `sheet.fields` | optional list of Field | At most 100; keys unique (domain check) |
| Field (all types) | `{key, label (1–100), hint? (≤500), group? (1–60), type}` | `key` follows the key rule. `label` is not blank once trimmed. Consecutive fields with the same `group` render together |
| `number` | `min?`, `max?`, `default?` | Integers from −1,000,000 to 1,000,000; `min ≤ default ≤ max` when given (domain check). A missing `default` reads as 0, or the nearest bound when 0 is outside `min..max` |
| `text` | `multiline?` (boolean), `maxLength?` (1–2000) | `maxLength` defaults to 200, or 2000 when `multiline` |
| `list` | `itemLabel?` (1–60), `maxItems?` (1–100) | Items are trimmed strings of 1–200 characters; `maxItems` defaults to 50. For inventory, cyberware and the like |
| `track` | `max?` (1–999) | A resource track `{current, max}`. A fixed `max` when set, else each Character sets its own; it starts full |

Each type allows only its own properties; another is rejected. A `null` optional is left out of the canonical form, as is a `multiline` of `false`. The sheet has no effects or checks: they open schema version 3. Derived values arrive as an additive `derived` field type in schema version 2 ([ADR 0023](../adr/0023-formula-kernel-and-derived-values.md)). Play's translator reads the sheet into the snapshot, re-checks every limit and fails closed on anything malformed.

The `character` step kind and `of: character` in `pick` and `condition` counts are part of the same additive change (see [Steps](#steps)).

### Play behaviour of the fact parts

Slot `hint`s are read and kept in the GameSystem snapshot, shown in the Facts panel and on the step card ("Fills: label — hint"). Play applies `fillFact` and renders `{fact:key}` as follows:

| Part | In Play |
|---|---|
| `fillFact` on a `text` slot | Fills the slot with `text` (default `{answer}`), trimmed and cut to 2000; empty changes nothing |
| `fillFact` on an `npc` / `thread` slot | Fills it with the first item of that kind in `{picked}`; none changes nothing |
| `{fact:key}` | The slot's value (the text, or the subject's current name), read when rendered; empty when unfilled |

A filled slot is replaced (its links stay). `fillFact` writes no journal entry of its own. A text slot without `text` and without an answer changes nothing. At 500 facts no slot fact is added, though a filled slot is still replaced. A `{fact:key}` naming a slot the release does not declare fails closed.

The player fills or refills a slot by hand too (a text, an existing NPC or Thread, or a new one by name); a refill keeps the fact's links.

### Authoring warning

Decision 7 of ADR 0018: a threshold consequence must lower its tracker, or it fires on every turn.

In one step list, when a `condition` on tracker T decides a `nextScene` S effect, and Scene Type S has no effect lowering T (`set`, or `add` with a negative literal), the release is still valid and publishing succeeds with a warning:

```text
flows[0].phases[1].worldTurn[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn
```

The condition decides the effect when some of its bands reach a `nextScene` S and others do not. A band reaches its own effects and every effect of the steps it leads to: its `next` (or the condition's `next`, or the following step), then every outcome of each step reached, forward until `end`. Bands compare by Scene Type: bands forcing S through different effects or steps all reach S. A Scene Type every band reaches is forced whatever T is, so it does not warn: two thresholds in one world turn (a `condition` on `masquerade`, then one on `hunters`, each forcing its own Scene Type) warn only when a forced Scene Type does not lower its own tracker. A decided Scene Type warns once per condition and tracker, at the first step forcing it.

Only S's own step effects count as lowering T (in any of its parts, including bands, branches and options). Effects of table entries that S rolls do not: they lower T only by chance, so a forced Scene Type whose relief comes from a rolled entry still warns.

The rule applies to tracker conditions only: a `condition` with `count` has no tracker and never warns. It applies to the step lists of flow phases and of Scene Types (`sceneTypes[i].play[j]: …`). Warnings are returned with the validated content and printed by the console command; they are not stored.

### Canonical form

As in version 1: keys in schema order, absent or `null` optionals omitted, integer-valued floats as integers, strings as given. Also, an optional list that is empty (`effects`, hooks, `levels`, `bands` of a `roll`, `branches` of a `table`), a `false` flag (`default`, `mandatory`), an empty outcome (`{}` as a branch or `otherwise`) and `branches` of an `oracle` step with no outcome mean the same as absent and are omitted. A band with no field (`{}`) stays `{}`. An empty `tags` list (top level, `pick`, `count`, `createNpc`, `createThread`) and a `pick` `count` equal to the default `{min: 1, max: 1}` are omitted too.

### Schema file vs domain (schema version 2)

| Checked by | Rules |
|---|---|
| JSON Schema and domain | The version 1 structural rules; the shape of each kind of tracker, selection, step and effect; enums (`kind`, `op`, `mode`, `rule`, `type`, `defaultView`, `of`); list sizes and lengths above; numeric bounds (incl. `count` 1–10); `end` not a step key; a `condition` never `mandatory`; a `condition` with exactly one of `tracker` and `count`; unique key lists (Scene Type and flow `oracles`, flow `trackers`, `player` Scene Types) |
| Domain only | The version 1 semantic rules; unique keys (trackers, fact slots, tags, Scene Types, flows, phases, steps, options, entries, table branches); at most one default flow; `min ≤ initial ≤ max`; the chaos tracker; the `randomEvent` table exists and the oracle has chaos and 100 sides; forward-only `next`; band ordering; every reference above; tags named by `pick`, `count` and effects exist and are unique in their list; `pick` `count` `min ≤ max`; the trackers and oracles of each flow; oracle selection tables; suggested choices naming `skip`; dice notation; placeholders; trimmed lengths |

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
| 4 | Play's translator turns the view into a `GameSystemSnapshot` (Play Domain). Unknown schema versions and missing releases fail with Play's own errors. The translator supports schema versions 1 and 2 and reads all of version 2, flows included; a version 1 release becomes a snapshot with no trackers, Scene Types or flows |

Play never imports Studio `Domain/` or `Infrastructure/` (enforced by PHPat, [ADR 0012](../adr/0012-phpat-boundary-enforcement.md)).

## Evolving the contract

- A new capability means a **new schema version**: add `v<N>.schema.json` next to the others and a new section here, as [schema version 2](#schema-version-2) did. Additive extensions (an optional field or a new value) stay in the current version when every valid release remains valid with the same content hash, as [`credits`](#credits) and the [sheet](#sheet-additive-in-schema-version-2) did ([ADR 0022](../adr/0022-character-sheets-in-schema-version-2.md)).
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
