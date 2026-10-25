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

> **Status:** Studio validates all of schema version 2: the envelope, trackers, fact slots, tags, `pick` steps, `condition` counts, the NPC and Thread effects, `fillFact`, table entries, the chaos tracker, Scene Types with their steps, bands and effects, flows with their phases and selections, the references inside each flow and placeholders, the `sheet` with its five field types, including `derived` fields, the formulas of derived fields, band `upTo` and tracker effect `value` ([ADR 0023](../adr/0023-formula-kernel-and-derived-values.md)), the `character` step and `of: character` in `pick` and `condition` ([ADR 0022](../adr/0022-character-sheets-in-schema-version-2.md)), the `checks` and the `check` step ([ADR 0024](../adr/0024-structured-checks-without-scripting.md)), and it prints the [authoring warnings](#authoring-warning) when publishing. Play reads all of version 2: trackers, fact slots and their hints, tags, table entry metadata, the chaos tracker, Scene Types with their steps, flows with their phases, Scene selections and hooks, `pick` steps, `condition` counts, the NPC and Thread effects and `{picked}`, and the `sheet`'s fields of the four stored types. It fails closed on the `check` step (and ignores `checks`) until slice 3 of `play-checks`, on the `character` step and on `of: character` until slice 4 of `play-characters`, and on `derived` fields until slice 4 of `rules-derived-values`. It reads `{formula}` band `upTo`s and tracker effect `value`s (trackers only) and evaluates them when the band or effect runs; a formula that fails then refuses the action with a 422 and changes nothing. It also applies `fillFact` and renders `{fact:key}` (see [Play behaviour of the fact parts](#play-behaviour-of-the-fact-parts)).

Everything not listed here works as in version 1: the key rule, `gameSystem`, the oracle tables and likelihood oracles with their rules, the oracle key namespace, the `sheet` (version 2 extends it, see [Sheet](#sheet-additive-in-schema-version-2)), optional fields, unknown properties and errors.

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
checks         [Check]   new (additive), see Checks
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
| Phase | `{key, name (1–100), act? (1–100), description? (≤2000), mode: once \| loop, selection, sessionOpening?, sessionClosing?, phaseOpening?, phaseClosing?, sceneOpening?, sceneClosing?, worldTurn?}` | Keys unique in the flow. `description` says what the phase is for and when it is done; Play shows it under the phase and in the end-phase confirmation ([ADR 0026](../adr/0026-prompt-inspiration-and-phase-descriptions.md)). Each hook is a step list, empty by default |
| Selection | `{rule: sequence \| player, sceneTypes: [key] (1–20)}` or `{rule: oracle, table: key}` | Scene Types exist; `sequence` may repeat a type; `player` keys unique; every entry of the `oracle` table names a `sceneType` |

### Steps

A step list (`setup`, `play`, `closing` and each hook) holds at most 50 steps.

| Kind | Fields (beside the common ones) | Rules |
|---|---|---|
| common | `{key, kind, title (1–100), prompt? (≤2000), tip? (≤2000), mandatory?, next?, effects?: [Effect]}` | Key unique within its step list; `end` is reserved; `next` is a **later** key of the same list or `end` (forward only) |
| `prompt` | `inspiration?: [table key]` (1–5) | The player writes an answer. Each inspiration table exists (a table, not a likelihood oracle) and appears once. They are suggestions: Play offers a roll button for each, but they have no effects and the step does not roll them, so they do not count as rolled tables ([ADR 0026](../adr/0026-prompt-inspiration-and-phase-descriptions.md)) |
| `oracle` | `oracle` (likelihood key), `likelihood?` (level key), `branches?: {yes?, no?, exceptionalYes?, exceptionalNo?}` | The oracle and level exist. An exceptional answer without its branch uses `yes` / `no` |
| `table` | `table` (table key), `branches?: [{entry, next?, effects?}]` (≤1000), `otherwise?: {next?, effects?}` | The table exists; `entry` is a key of an entry of the step's own table, unique in the list |
| `roll` | `dice`, `bands?: [Band]` (≤20) | Valid dice notation |
| `choice` | `options: [{key, label (1–100), next?, effects?}]` (2–10), `skip?` (option key) | Option keys unique; `skip` is one of them. A suggested (not mandatory) choice must name `skip` |
| `condition` | exactly one of `tracker` or `count: {of: npc \| thread \| character, tags?: [key] (≤5)}`, plus `bands: [Band]` (1–20) | The tracker exists, or the tags exist and are unique. A `count` compares the number of open NPCs or Threads, or of Characters, carrying all the tags to the bands. Never `mandatory`; never skipped; writes no journal entry |
| `pick` | `of: npc \| thread \| character`, `tags?: [key]` (≤5), `mode: random \| choose`, `count?: {min, max}`, `branches?: {found?, none?}` | Tags exist and are unique; `1 ≤ min ≤ max ≤ 10`, default `{min: 1, max: 1}`; branches are `{next?, effects?}`. `mandatory` allowed. Picks open items (every Character) carrying all the tags: `random` draws, `choose` lets the player select |
| `character` | — | Completes with a Character of the Campaign (see [Sheet](#sheet-additive-in-schema-version-2)). `mandatory`, `next` and `effects` as for `prompt`; no branches. `{step:key}` may name it |
| `check` | `check` (check key), `difficulty?: int \| {tracker: key} \| {formula: string}`, `outcomes?: [{band, next?, effects?}]` (≤10) | The check exists. `difficulty` only for a check that declares one: an integer within its `min..max`, or a tracker or [flow formula](#formulas-adr-0023). Each `band` names a band of the check, once; a band without an outcome continues. See [Checks](#checks-additive-in-schema-version-2) |

Every `next` (of a step, band, branch, option, `otherwise`, `pick` branch or check outcome) follows the forward-only rule of the step list it is in. Branches and `otherwise` are `{next?, effects?}`.

### Bands and effects

| Part | Shape | Rules |
|---|---|---|
| Band | `{upTo?: int \| {tracker: key} \| {formula: string}, label? (1–80), next?, effects?}` | Optional `label` names the outcome the band stands for (additive: bands stored without one stay valid; Play shows it from `play-guided-polish` slice 3). Ordered upper bounds: every band but the last has `upTo`; the last omits it and catches the rest; literal `upTo` values strictly increase (`{tracker}` and `{formula}` bounds are not compared). A `{formula}` is a [flow formula](#formulas-adr-0023) |
| Effect | `{kind: tracker, tracker, op: add \| set, value: int (±1000) \| {tracker: key} \| {formula: string}}` · `{kind: nextScene, sceneType}` · `{kind: switchSceneType, sceneType}` · `{kind: endPhase}` · `{kind: sceneTitle, title (1–100)}` · `{kind: createNpc, name? (1–100), agenda? (≤2000), tags?: [key] (≤5)}` · `{kind: createThread, name? (1–200), tags?: [key] (≤5)}` · `{kind: closeThread}` · `{kind: fillFact, slot, text? (1–2000)}` · `{kind: sheet, field, op: add \| subtract \| set, value: int (±1000) \| {formula: string} \| {dice: string}}` ([sheet effect](#sheet-effects-adr-0027)) | At most 10 per list; referenced trackers, Scene Types, tags and fact slots exist; `tags` unique; `name`, `agenda` and `text` are placeholder text; `fillFact` `text` is allowed on a `text` slot only (on an `npc` or `thread` slot the value is the picked item) |

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

A `{step:key}` in a run-once list must name an earlier step. The run-once lists are the `phaseOpening` and `phaseClosing` of a phase with `mode: once`, which run as one sequence: opening first, then closing. So a closing step may name an opening step, and an opening step may not name a closing one. A step runs front to back there, so the step itself and every later step are not answered when the text renders, and the placeholder could only show "—". Studio refuses it at the placeholder's path, naming the step. The one exception is the step's own effect text, which Play renders after the answer is recorded (it reads like `{answer}`). Lists that repeat (a session's hooks, scene hooks, a looping phase, Scene Types) can read an answer from an earlier round, and a key in a list of another kind has no fixed order, so neither is checked.

**Pattern: an NPC whose agenda comes from a later answer.** A `createNpc` effect renders its `name` and `agenda` once, when it fires, so a later answer cannot reach it. Create the NPC in the step that has every answer it needs, and refer to the earlier answers with `{step:key}`: for example, ask "Who do they serve?" and "What do they want?" first, then a last step whose effect is `createNpc` with `agenda: "{step:wants}, for {step:master}"`.

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
| `sheet` | object | Exactly `fields`, `columns`, `groups` and `pointBudget`; any other property is rejected. `{}`, `{"fields": []}` and `{"fields": null}` are the same sheet and canonicalize to `{}` |
| `sheet.fields` | optional list of Field | At most 100; keys unique (domain check) |
| Field (all types) | `{key, label (1–100), hint? (≤500), group? (1–60), span? (1–4), type}` | `key` follows the key rule. `label` is not blank once trimmed. Without declared `sheet.groups`, consecutive fields with the same `group` render together; with them, see [Sheet layout](#sheet-layout-adr-0031) |
| `number` | `min?`, `max?`, `default?`, `roll?` | Integers from −1,000,000 to 1,000,000; `min ≤ default ≤ max` when given (domain check). A missing `default` reads as 0, or the nearest bound when 0 is outside `min..max`. `roll` is a `{dice}` (1–100 characters, a Randomness kernel expression such as `3d6*5`, validated like a `roll` step's): a dice default rolled when a Character is created |
| `text` | `multiline?` (boolean), `maxLength?` (1–2000) | `maxLength` defaults to 200, or 2000 when `multiline` |
| `list` | `itemLabel?` (1–60), `maxItems?` (1–100) | Items are trimmed strings of 1–200 characters; `maxItems` defaults to 50. For inventory, cyberware and the like |
| `track` | `max?` (1–999), `startMax?` | A resource track `{current, max}`. A fixed `max` when set, else each Character sets its own; it starts full. `startMax` is a `{formula}` (1–200 characters) for the max a Character starts with; it is exclusive with `max` (domain check) and reads `number` fields and `derived` fields that depend, directly or through other derived fields, on no track and no tracker. `{key.max}`, tracks, `{tracker:key}`, `{check:…}` and unknown keys are rejected |
| `derived` | `formula` (required, 1–200 characters) | A DerivedValue computed from other fields and trackers by a [formula](#formulas-adr-0023); a Character stores no value for it |
| `conditions` | `options` (required): 1–20 of `{key, label (1–60)}` | A set of conditions a Character marks and clears ([ADR 0041](../adr/0041-dice-pools-pushes-and-conditions.md)). Option keys follow the key rule and are unique in the field (domain check; two fields may reuse a key). The value is the marked keys, none at first. A formula reads the field as the number marked. `startMax` and `pointBudget` refuse it |

Each type allows only its own properties; another is rejected. A `null` optional is left out of the canonical form, as is a `multiline` of `false`. Sheet fields change through [sheet effects](#sheet-effects-adr-0027), which are also additive in schema version 2. Checks are described in [Checks](#checks-additive-in-schema-version-2). Derived values are the additive `derived` field type of schema version 2 ([ADR 0023](../adr/0023-formula-kernel-and-derived-values.md)). Play's translator reads the sheet into the snapshot, re-checks every limit and fails closed on anything malformed; until `rules-derived-values` slices 3 and 4 that includes a `derived` field.

The `character` step kind and `of: character` in `pick` and `condition` counts are part of the same additive change (see [Steps](#steps)).

#### Structured character creation (ADR 0040)

`number.roll`, `track.startMax` and `sheet.pointBudget` say how a Character is created. They are additive in schema version 2: every release valid before stays valid with the same hash, and a `null` of any of them is left out of the canonical form.

| Part | Shape | Rules |
|---|---|---|
| `sheet.pointBudget` | optional `{label?, total, fields, min?, max?}`, or `null` | Exactly these properties. `label` is 1–60 characters. `total` is an integer from 1 to 1,000,000. `fields` lists 1–100 distinct keys of `number` fields. `min` and `max` are creation-only bounds for every budget field (sheet numbers, `min ≤ max`) |

Domain checks (item rules, so they also hold on a draft), each failing with its path, such as `sheet.pointBudget.fields[1]` or `sheet.fields[3].startMax`:

- Each budget field exists, is a `number` field and is listed once.
- Each budget field keeps at least one value inside both its own `min..max` and the budget's `min..max`.
- The lowest such values of all budget fields (an unbounded field counts as −1,000,000) add up to at most `total`.

A draft refuses to delete or rename a field that a `startMax` formula reads or `pointBudget.fields` lists, like any other reference. Play's translator refuses `roll`, `startMax` and `pointBudget` (unknown property) until Play creation supports them, so a release using them can be published but not played yet ([ADR 0040](../adr/0040-structured-character-creation.md)).

#### Sheet layout (ADR 0031)

A sheet may declare **groups** laid out in a grid ([ADR 0031](../adr/0031-sheet-layout-and-studio-sheet-builder.md)). It is additive in schema version 2: every release valid before stays valid with the same hash.

| Part | Shape | Rules |
|---|---|---|
| `sheet.columns` | optional integer | 1–2: the columns of groups. Defaults to 1 |
| `sheet.groups` | optional list of Group, or `null` | At most 20, declared in render order |
| Group | `{name, columns?, span?}` | Exactly these properties. `name` is a string of 1–60 characters, not blank once trimmed, unique by exact match. `columns` is an integer 1–4 (the columns of fields, default 1). `span` is an integer 1–2 (the sheet columns it covers, default 1) |
| Field `span` | optional integer | 1–4: the group columns the field covers. Defaults to 1 |

Domain checks (item rules, so they also hold on a draft), each failing with its path, such as `sheet.columns`, `sheet.groups[1].span` or `sheet.fields[3].group`:

- `sheet.columns` and a field `span` need declared groups. `groups: []` and `groups: null` count as no groups.
- A group `span` is at most the sheet's columns.
- A field `span` is at most the `columns` of its group.
- With declared groups every field has a `group` naming a declared group (exact match). An empty declared group is valid and renders nothing.

Rendering: groups appear in declared order, filling the sheet's columns left to right, a group of `span` 2 taking a full row; fields appear in list order within their group, each taking `span` of the group's columns. Without declared groups, the consecutive-group rendering stays. Phones show one column.

Canonical form: `sheet.columns` of 1, a group `columns` or `span` of 1, a field `span` of 1, `columns: null`, `span: null`, `groups: []` and `groups: null` are omitted, so a release without layout keeps its canonical JSON and hash. Canonical order is `fields`, `columns`, `groups`.

Play's translator reads the layout into the snapshot (none without groups), re-checks every rule above and fails closed on anything malformed; a release stored without layout reads as before, every field spanning 1.

### Sheet effects (ADR 0027)

A **sheet effect** changes a Character's `number`, `track` or `conditions` field. It is additive in schema version 2 ([ADR 0027](../adr/0027-sheet-effects.md), conditions in [ADR 0041](../adr/0041-dice-pools-pushes-and-conditions.md)); every release valid before stays valid with the same hash.

| Part | Rules |
|---|---|
| `kind` | `sheet` |
| `field` | The key of a sheet `number` field (its value), `track` field (its current value) or `conditions` field (its marked keys). A `derived`, `text` or `list` field, and an unknown key, are rejected with distinct messages |
| `op` | `add`, `subtract` or `set` on a `number` or `track` field; `mark` or `clear` on a `conditions` field. Each is refused on the other type |
| `value` | For `add`, `subtract` and `set`, required: an integer from −1,000 to 1,000; a `{formula}` (1–200 characters); or a `{dice}` (1–100 characters, no other property). The three forms are exclusive. For `mark` and `clear`, optional: a string, the key of an option of that conditions field (an unknown option is rejected) |

- **Placement.** Allowed only where a Character is known: a `check` step's `outcomes[].effects` (the checked Character), a `character` step's own `effects` (the Character the step completes with) and a check's [`push.effects`](#checks-additive-in-schema-version-2) (the rolling Character). Anywhere else is a semantic error: other step kinds, a check step's own `effects`, bands, options, branches, `otherwise`, oracle table entries and Scene Type setup effects.
- **Formula scope.** A `{formula}` value uses the sheet scope of that Character (see [Formulas](#formulas-adr-0023)): `number`, `track`, `.max`, `derived` and `{tracker:key}`; `{check:difficulty}` is rejected.
- **Dice.** A `{dice}` value is a dice expression of the Randomness kernel, validated like a `roll` step's. Play rolls it with the injected random source when the effect is applied.
- **Clamping.** The result clamps: a track to 0..its max, a number to its declared `min` and `max`.
- **Mark and clear.** `mark` marks an option and `clear` clears it; `clear` without a `value` clears every option. `mark` without a `value` leaves the option to the player, so it is allowed only in a push cost, where the push request carries the pick; in an outcome or a character step it is a semantic error.
- **Order.** Effects apply in list order with the step, all or nothing.
- **Track start.** A track starts full. A `character` step's effect sets a different start, e.g. `{kind: sheet, field: sanity, op: set, value: {formula: "{pow}"}}`.

Play's translator refuses the `sheet` kind (unknown effect kind) until `preset-real-system` slice 7, so a release using it can be published but not played yet. It keeps refusing `mark` and `clear` (unknown sheet operation) until Play plays conditions ([ADR 0041](../adr/0041-dice-pools-pushes-and-conditions.md)).

### Checks (additive in schema version 2)

A release may define **checks** ([ADR 0024](../adr/0024-structured-checks-without-scripting.md)): named rules whose total (`dice + modifier + situational modifier`, or the successes of a [pool](#pool-and-push-adr-0041)) maps to an outcome band, the first whose `upTo` is at least the total; the last band catches the rest. Adding them is additive and only in schema version 2; every release valid before stays valid with the same hash. A schema version 1 `checks` must still be `[]`.

| Part | Shape | Rules |
|---|---|---|
| `checks` | list of Check | At most 50; keys unique (domain check) |
| Check | `{key, label (1–100), hint? (≤500), dice?, pool?, modifier?, difficulty?, push?, bands}` | Exactly one of `dice` and `pool`. `dice` is dice notation (≤100), parsed by the Randomness kernel (domain check). `modifier` is a sheet [formula](#formulas-adr-0023) of 1–200 characters, refused with a `pool`. See [Pool and push](#pool-and-push-adr-0041) |
| `difficulty` | `{label (1–60), default, min?, max?}` | Integers from −1,000 to 1,000; `min ≤ default ≤ max` (domain check). The player sets it at roll time; band formulas read it as `{check:difficulty}` |
| `bands` | list of `{key, label (1–100), upTo?: int \| {tracker: key} \| {formula: string}}` | 1–10; keys unique in the check; ordered as a [band](#bands-and-effects): every band but the last has `upTo`, the last omits it, literal `upTo` values strictly increase. A `{tracker}` names a tracker of the release; a `{formula}` is a sheet formula that may also read `{check:difficulty}` |

Optional `null` fields are left out of the canonical form; checks keep their authored order. The step that rolls a check is the [`check` step](#steps). Example (the fixture `valid/v2-checks.json` has the whole release):

```json
"checks": [{
  "key": "skill", "label": "Skill check", "dice": "1d10", "modifier": "{hands}+{edge}",
  "difficulty": {"label": "Difficulty Value", "default": 13, "min": 9, "max": 29},
  "bands": [
    {"key": "fail", "label": "Fail", "upTo": {"formula": "{check:difficulty}-1"}},
    {"key": "success", "label": "Success", "upTo": {"formula": "{check:difficulty}+4"}},
    {"key": "crit", "label": "Critical success"}
  ]
}]
```

```json
{"key": "pick-lock", "kind": "check", "title": "Pick the lock", "check": "skill", "difficulty": {"formula": "{tracker:alarm}+9"},
 "outcomes": [{"band": "fail", "effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": 1}]}, {"band": "crit", "next": "end"}]}
```

Play's translator reads `checks` into the GameSystem snapshot and re-checks every rule above (a stored release is not trusted), failing closed on anything malformed. It reads a `check` step too and re-checks the rules of Studio's validator: the step's `check` exists, a `difficulty` is fixed only for a check that declares one (a literal within its `min`..`max`), and each `outcomes[].band` is a band of the check, once. Playing the step: the player picks a Character (one in the current Scene, else in the Session party), the server rolls the check, the band's outcome supplies `next` and `effects`, and a band without an outcome continues.

#### Pool and push (ADR 0041)

A check may roll a **pool** instead of a dice expression, and a pool check may be **pushed** once. Both are additive in schema version 2 ([ADR 0041](../adr/0041-dice-pools-pushes-and-conditions.md)): every release valid before stays valid with the same hash, and a `null` of either is left out of the canonical form.

| Part | Shape | Rules |
|---|---|---|
| `pool` | `{count, sides, successAt}`, exactly these properties | `count` is an integer from 1 to 100 or a `{formula}` (1–200 characters) in the sheet scope of a `modifier`. `sides` is an integer from 2 to 1,000. `successAt` is an integer from 1 to `sides`: a die succeeds when its face is at least `successAt`. The check total is the number of successes; bands and difficulty work on it as before |
| `push` | `{label (1–100), effects? (0–10)}` | Only on a check with a `pool`. `label` names the cost. `effects` are `sheet` and `tracker` effects, applied with the push; sheet effects there follow the [sheet effect](#sheet-effects-adr-0027) rules (the rolling Character, the sheet formula scope) and may `mark` without an option |

Domain checks, each failing with its path, such as `checks[0].pool.successAt` or `checks[0].push.effects[1].value`:

- Both or neither of `dice` and `pool` is an error (`checks[0]`, `checks[0].dice`), and so is a `modifier` with a `pool` (`checks[0].modifier`): the count formula sizes the pool.
- `successAt` is at most `sides`; a `count` formula reads only what a `modifier` may.
- `push` without a `pool` is an error (`checks[0].push`); push effects of any kind but `sheet` and `tracker` are an error (`checks[0].push.effects[0].kind`).

Example:

```json
{
  "key": "sneak", "label": "Sneak",
  "pool": {"count": {"formula": "{agility} + {stealth} - {physical}"}, "sides": 6, "successAt": 6},
  "push": {"label": "Take a physical Condition", "effects": [{"kind": "sheet", "field": "physical", "op": "mark"}]},
  "bands": [{"key": "fail", "label": "Failure", "upTo": 0}, {"key": "pass", "label": "Success"}]
}
```

The fixture `valid/v2-dice-pools.json` has the whole release. Play's translator refuses `pool`, `push`, the `conditions` field type and the `mark` and `clear` operations (unknown property, unknown field type, unknown sheet operation) until the slices that play them, so a release using them can be published but not played yet.

### Formulas (ADR 0023)

A **formula** is a string of 1–200 characters in seven places: a `derived` field's `formula`, a band's `upTo: {formula}`, a tracker effect's `value: {formula}`, a sheet effect's `value: {formula}`, a check's `modifier`, a pool's `count: {formula}` and a check band's `upTo: {formula}`; a check step's `difficulty: {formula}` is a flow formula. The JSON Schema checks the shape (a `{formula}` object has exactly the property `formula`); the Studio validator parses it with the Formula kernel and checks what the schema cannot. Syntax summary (full grammar in [ADR 0023](../adr/0023-formula-kernel-and-derived-values.md), terms in the [glossary](../domain/glossary.md)):

- Integers from 0 to 1,000,000, `+ - * /` with parentheses and unary minus, `min(a, b, …)` and `max(…)` with 2–10 arguments. Division rounds down. Intermediate results stay within ±1,000,000,000.
- References are braced: `{key}` reads a field, `{key.max}` a `track` field's max, `{tracker:key}` a tracker's current value and `{check:difficulty}` a check's difficulty. Keys follow the key rule.

| Scope | Where | Rules |
|---|---|---|
| Sheet | `derived` field `formula`, sheet effect `value` | `{key}` names a `number`, `track`, `conditions` or `derived` field (a `conditions` field reads as the number of options marked); `{key.max}` a `track` field; `{tracker:key}` a tracker of the release. `text` and `list` fields, unknown keys and `.max` on a non-track field (a `conditions` field included) are rejected. Derived fields never depend on each other in a cycle, a field reading itself included; the error names the first field of the cycle |
| Check | check `modifier`, check `pool.count`, check band `upTo` | The sheet scope: the references a `derived` formula may read. A band formula may also read `{check:difficulty}`, only when its check declares a `difficulty`; the modifier never reads a `{check:…}` reference, and no other check key exists |
| Flow | band `upTo`, tracker effect `value`, check step `difficulty` | Only `{tracker:key}` references, to trackers of the release (and, as for `{tracker: key}`, of the flow); `{key}` and `{key.max}` are rejected because a flow formula reads trackers only |

A syntax error is reported at the formula's path with the kernel's message. A `derived` field shares the key namespace and the `group` layout of the other field types. A `{formula}` band `upTo` still counts as an `upTo` for the "every band but the last" rule. Counter `levels` keep literal `upTo` only. A formula that fails at play time (division by zero, overflow) is an evaluation error, not a content error: it is Play's concern: a band or effect formula refuses the action with a 422 and the campaign stays as it was.

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
| JSON Schema and domain | The version 1 structural rules; the shape of each kind of tracker, selection, step and effect; enums (`kind`, `op`, `mode`, `rule`, `type`, `defaultView`, `of`); list sizes and lengths above; numeric bounds (incl. `count` 1–10); `end` not a step key; a `condition` never `mandatory`; a `condition` with exactly one of `tracker` and `count`; the shape of a check, its difficulty, bands and `check` step (sizes, lengths, `difficulty` ±1,000); the shape of a sheet effect (`op` enum, `value` an integer within ±1,000, a `{formula}` or a `{dice}` of 1–100 characters, no other property); unique key lists (Scene Type and flow `oracles`, flow `trackers`, `player` Scene Types) |
| Domain only | The version 1 semantic rules; unique keys (trackers, fact slots, tags, Scene Types, flows, phases, steps, options, entries, table branches); at most one default flow; `min ≤ initial ≤ max`; the chaos tracker; the `randomEvent` table exists and the oracle has chaos and 100 sides; forward-only `next`; band ordering; every reference above; tags named by `pick`, `count` and effects exist and are unique in their list; `pick` `count` `min ≤ max`; the trackers and oracles of each flow; oracle selection tables; suggested choices naming `skip`; dice notation (rolls and checks); placeholders; trimmed lengths; checks: unique check and band keys, modifier and band formulas parse and read only what they may, `{tracker}` bounds exist, band order and `upTo` presence, difficulty `min ≤ default ≤ max`; `check` steps: the check exists, `difficulty` only for a check that declares one and within its range, outcome bands exist and are unique; sheet effects: the placement rule, the `field` exists and is a `number` or `track`, the `{formula}` reads only the sheet scope, the `{dice}` notation parses |

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

A campaign upgrade ([ADR 0035](../adr/0035-campaign-release-upgrade.md)) reads the latest release the same way and fits stored values to it: a value that is still valid is kept; a new field gets its initial value; a removed or now `derived` field loses its value; `number` and `track` values are clamped to the new bounds, `list` values trimmed to `maxItems`, and a value whose type changed or that is still invalid is reset to the new initial value. Play matches by key, so a renamed key reads as one removed field plus one added field.

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

A complete, valid release file with a clock, a counter with levels bound to the chaos factor, one fact slot, three Scene Types and one flow of three phases (one per selection rule). It uses every step kind except `check`, every effect kind, bands, branches, the three placeholders and one check. The forced Firefight lowers the alarm, so it publishes without a warning.

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
          "key": "sneak-by",
          "kind": "check",
          "title": "Slip past the guards",
          "check": "slip-past",
          "outcomes": [{"band": "caught", "effects": [{"kind": "tracker", "tracker": "alarm", "op": "add", "value": 1}]}]
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
  "checks": [
    {
      "key": "slip-past", "label": "Slip past the guards", "hint": "Roll 2d6 against how alert the guards are.",
      "dice": "2d6",
      "difficulty": {"label": "Alert level", "default": 7, "min": 2, "max": 12},
      "bands": [
        {"key": "caught", "label": "Caught", "upTo": {"formula": "{check:difficulty}-1"}},
        {"key": "through", "label": "Through"}
      ]
    }
  ]
}
```
