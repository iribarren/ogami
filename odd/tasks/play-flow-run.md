# Feature: play-flow-run

- **Locator:** `odd/tasks/play-flow-run.md` · Engram topic `odd/play-flow-run/tasks`
- **Issue:** #39 · **Current branch:** `feat/play-flow-run-8-acl-flows` (from `main` `100c691`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `flow-model-examples` (ADR 0018), PR #38

## Objective

Implement the NarrativeFlow model of [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) as amended by [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md): release contract schema version 2, Studio validation, Play's anti-corruption layer, FlowRun, trackers, Scene Types and hook scenes, and the guided presentation of [ADR 0016](../../docs/adr/0016-flow-presentation-journal-with-focus-mode.md). Roadmap feature 7 (M2).

## Problem and why

Campaigns pin their release for good ([ADR 0014](../../docs/adr/0014-campaigns-pinned-to-their-release.md)). Schema version 1 has a flat provisional `flow.steps` that no preset fills. Guided play needs the full model before any flow with steps is published, so Play's anti-corruption layer supports one shape forever.

## Scope

- **In:** schema version 2 with ADR 0018 decisions 1–14, tracker `hint` (17), counter `levels` (18), phase `act` (19); the five [flow examples](../../docs/domain/flow-examples.md) as v2 fixtures (decision 26) without the parts that need 8, 8b or 10; Studio validation and warnings; Play ACL (v1 → no flows); Campaign trackers; FlowRun; Scene Type, Scene kind, titles; Session end; flow choice at creation; guided journal UI, trackers and oracle panels, focus mode; deleting `frontend/src/play/flow-prototypes/`.
- **Out:** tags, `pick`, `createNpc` / `createThread` / `closeThread`, `{picked}` (8); Campaign facts, `fillFact`, slot `hint`, `{fact:key}` (8b, slots are declared only); Characters, party, cast (10); presets with flows (9, 9b; both presets stay schema version 1); theme field (8c).

## Constraints

- Dependency rule and PHPat ([ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)): FlowRun and Scene Types live in framework-free `Play\Domain`; the v2 translator lives in `Play\Infrastructure\GameSystem`; Studio validation in `Studio\Domain\Release` may use Randomness Domain (shared kernel).
- Never edit `v1.schema.json`. `ReleaseContent` and the translator list supported schema versions explicitly (1 and 2).
- ADR 0007: structured state that varies by kind is JSONB (FlowRun, tracker values, sessions/scenes); identity and ownership are columns.
- Randomness runs on the server with the injected `RandomNumberGenerator`, from the pinned snapshot.
- Glossary terms in code, UI and docs: Phase, Scene Type, Scene selection, World turn, Hook, Effect, Tracker, Outcome band, Condition step, Act, FlowRun, Guidance.
- Every PR slice is safe on `main` alone: backend unused until its UI lands.

## Contract: schema version 2

Same conventions as v1: every object rejects unknown properties; an optional field may be omitted or `null`; errors are one `InvalidReleaseContent` with a path prefix (`flows[0].phases[1].worldTurn[2].bands[0].upTo: …`). Key rule `^[a-z0-9-]{1,64}$`.

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

| Part | Shape | Rules |
|---|---|---|
| Oracle table entry | v1 fields + `key?`, `sceneType?`, `effects?: [Effect]` | `key` unique within its table; `sceneType` exists |
| Likelihood `chaos` | v1 fields + `tracker?` | A counter whose `min`/`max` equal the chaos `min`/`max` |
| Tracker | `{key, name (1–100), hint? (≤500), kind: counter, min, max, initial, levels?}` or `{key, name, hint?, kind: clock, segments (1–20)}` | Keys unique (own namespace, ≤50); `min ≤ initial ≤ max`, ±1000; clock starts at 0 |
| Counter `levels` | `[{upTo?, label (1–100)}]` | Same rule as bands, literals only |
| FactSlot | `{key, label (1–100), type: text\|npc\|thread}` | Keys unique (≤100). Declared only (8b fills them) |
| SceneType | `{key, name (1–100), purpose (1–500), tips? (≤2000), oracles: [key], setup: [Step], play: [Step], closing: [Step]}` | Keys unique (≤100); `oracles` exist in the oracle namespace |
| Flow | `{key, name (1–100), description? (≤2000), introduction? (≤5000), default?, defaultView: focus\|journal, oracles: [key], trackers: [key], phases: [Phase] (1–20)}` | Keys unique (≤20); at most one `default: true`; every Scene Type the flow can reach has `oracles ⊆ flow.oracles` |
| Phase | `{key, name (1–100), act? (1–100), mode: once\|loop, selection, sessionOpening?, sessionClosing?, phaseOpening?, phaseClosing?, sceneOpening?, sceneClosing?, worldTurn?}` (hook lists: `[Step]`, empty by default) | Keys unique in the flow |
| Selection | `{rule: sequence\|player, sceneTypes: [key] (1–20)}` or `{rule: oracle, table: key}` | `sequence` may repeat a type; `player` keys unique; every entry of the `oracle` table names a `sceneType` |
| Step (common) | `{key, kind, title (1–100), prompt? (≤2000), tip? (≤2000), mandatory?, next?, effects?: [Effect]}` | Key unique within its step list (≤50 steps); `end` is reserved; `next` is a **later** key of the same list or `end` |
| `prompt` | — | The player writes an answer |
| `oracle` | `oracle` (likelihood key), `likelihood?` (level key), `branches?: {yes?, no?, exceptionalYes?, exceptionalNo?}` | An exceptional answer without its branch uses `yes` / `no` |
| `table` | `table` (table key), `branches?: [{entry, next?, effects?}]`, `otherwise?: {next?, effects?}` | `entry` is a key of the step's own table, unique in the list |
| `roll` | `dice`, `bands?: [Band]` | Valid dice notation |
| `choice` | `options: [{key, label (1–100), next?, effects?}]` (2–10), `skip?` (option key) | Option keys unique. Suggested (not mandatory) choice must name `skip` |
| `condition` | `tracker`, `bands: [Band]` (≥1) | Never `mandatory`, no `prompt` needed; never skipped |
| Band | `{upTo?: int \| {tracker: key}, next?, effects?}` | Ordered; only the last omits `upTo` and catches the rest; literal `upTo` values strictly increase |
| Effect | `{kind: tracker, tracker, op: add\|set, value: int \| {tracker: key}}` · `{kind: nextScene, sceneType}` · `{kind: switchSceneType, sceneType}` · `{kind: endPhase}` · `{kind: sceneTitle, title (1–100)}` | ≤10 per list; referenced keys exist |

**References inside a flow.** Tracker references (effects, bands, `condition`, `{tracker:key}`) in the flow's phases and in every Scene Type the flow can reach must be in `flow.trackers`. A Scene Type is reachable from a flow through phase selections, `nextScene` / `switchSceneType` effects, and table entries whose `sceneType` is set (tables rolled by the flow's steps or its oracle selection). `oracle` / `table` step keys exist in the release.

**Placeholders.** A `{…}` matching `\{[a-z]+(:[a-z0-9-]+)?\}` in `title`, `prompt`, `tip` or effect text is a placeholder; other braces are text. Allowed: `{tracker:key}` (a flow tracker), `{step:key}` (a step key that exists in the flow or a reachable Scene Type), `{answer}` (effect text only). Any other namespace fails ("not supported in schema version 2").

**Authoring warning (decision 7).** In one step list, when a `condition` on tracker T is followed (same step or later step) by a `nextScene S` effect, and Scene Type S has no effect lowering T (`set`, or `add` with a negative literal), publishing succeeds with a warning: `flows[f].phases[p].<list>[i]: nextScene <S> does not lower tracker <T>; the consequence may fire every turn`. Warnings are returned by `ReleaseContent` / the publish result and printed by the console command; they are not stored.

## Play rules

### Campaign state

- **Trackers:** the Campaign holds a value for **every** release tracker, set to `initial` (counter) or 0 (clock) at creation. Effects and edits clamp to `min..max` / `0..segments`. The player edits a value by hand at any time; with a FlowRun the edit is recorded in its history. The UI shows the flow's trackers while guided (in free play: all).
- **Chaos binding:** a likelihood oracle whose `chaos.tracker` is set uses the tracker's value; a request carrying `chaosFactor` for it fails with 422.
- **Flow choice:** `CreateCampaign` takes `flowKey: ?string` (null → "Play freely", no FlowRun). The SPA preselects the release's default flow.
- **Scenes:** a Scene has an optional `sceneType` key, `kind: scene | hook` and, for hooks, the hook name (`sessionOpening`, `sessionClosing`, `phaseOpening`, `phaseClosing`, `worldTurn`). Default title: the Scene Type name numbered per type in the campaign (`Legwork 2`); `sceneTitle` sets it. Hook titles: `Session N begins`, `Session N ends`, `<act>: <phase name>` (or the phase name) for `phaseOpening`, `<phase name> ends`, `The world moves`. Free play may start a scene by hand with an optional Scene Type and switch it by hand.
- **Sessions:** a Session is one sitting. `EndSession` sets `endedAt`; a new session must start before more scenes. While guided it is allowed only between scenes, and it runs `sessionClosing` first.

### FlowRun (inside the Campaign aggregate, JSONB)

- **State:** `status: active | paused | completed`, position (phase index, stage, scene number, Scene Type, part, step key), per-scene switch count, the forced next Scene Type, `phaseEnding`, latest answers per step key, and `history` (`skip`, `trackerEdit`, `sceneTypeSwitch`, `paused`, `resumed`, `sceneAbandoned`, `phaseEnded`, `completed`, each with `at`).
- **Order:**
  - Session start → `sessionOpening` → (first time in a phase) `phaseOpening` → scene pick.
  - Scene: `sceneOpening` → `setup` → `play` steps → open play until "End scene" → `closing` → `sceneClosing`.
  - After a scene: if the phase is ending → `phaseClosing` → next phase (its `phaseOpening`), no world turn; else `worldTurn` → scene pick.
  - Each non-empty hook list runs in a hook Scene; an empty one creates no Scene. After the last phase's `phaseClosing` the FlowRun is `completed` and play continues unguided.
- **Scene pick** (a mandatory step): a forced `nextScene` offers only that card. Otherwise `sequence` offers "Next: …", `player` one card per type, `oracle` rolls the table (recorded as an `oracle-table` entry, the new scene's first entry). A selection with one Scene Type picks it automatically. Loop phases also offer "Move on" (ends the phase) and, between scenes, "End session".
- **Phase modes:** `once` + `sequence` plays each listed type once; `once` + `player` / `oracle` plays one scene; `loop` repeats until `endPhase` or "Move on" (a `sequence` loop cycles). A forced `nextScene` may name any release Scene Type and does not advance a sequence.
- **Steps:** suggested steps can be skipped (history only). A skipped `choice` follows its `skip` option with that option's effects; any other skipped step goes to its `next` with no effects. A mandatory step has no Skip. Step `effects` apply when the step completes, before its branch's effects. Without a branch target the next step in the list runs; `end` ends the part.
- **Results:** prompt answers are `note` entries; `oracle` → `likelihood`; `table` → `oracle-table`; `roll` → `roll`; `choice` → new `choice` kind `{question, optionKey, label}`. A step's entry stores a snapshot `flowStep {key, title, prompt}` with placeholders rendered. `condition` writes no entry and advances on its own. `{answer}` is the step's answer text (prompt text, answer label, entry text, roll total, option label).
- **Effects:** `tracker` (clamped); `nextScene` forces the next pick; `switchSceneType` changes the current Scene's type in place (entries stay, position jumps to the new type's `setup`, `sceneOpening` does not re-run, recorded; at most one per scene through effects, a second is ignored and recorded); `endPhase` sets `phaseEnding` (the scene finishes, then `phaseClosing`); `sceneTitle` renames the current Scene. Table entry effects apply when a flow step rolls the entry. A rolled entry with `sceneType` is offered as a manual switch.
- **Guidance:** pause → `paused` (free play; notes, oracles, rolls, manual scenes and switches). Resume continues at the stored position. If the player started a new scene by hand while paused mid-scene, the paused scene is abandoned (history) and resume continues between scenes.
- **Concurrency:** FlowRun commands carry the current step key (or stage); a mismatch fails with 409.

### HTTP (under `/api`, `SOLO_PLAYER` and owner only)

Indicative; each slice fixes its endpoints in OpenAPI.

| Method | Path | Does |
|---|---|---|
| POST | `/campaigns` | + `flowKey` |
| GET | `/campaigns/{id}` | + trackers (definition + value), flows summary, Scene Types, FlowRun view (status, progress, current step rendered, next step label, cards) |
| PUT | `/campaigns/{id}/trackers/{key}` | Edit a value |
| POST | `/campaigns/{id}/sessions/current/end` | End session |
| POST | `/campaigns/{id}/scenes/current/scene-type` | Switch by hand |
| POST | `/campaigns/{id}/flow-run/{answer,skip,pick,move-on,end-scene,pause,resume}` | FlowRun commands |

## Slice plan

Forecasts include generated files (OpenAPI spec, TS types, route tree). Split a slice that grows past ~1,500 lines before review.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-flow-run-1-contract` | `feat(studio): play-flow-run 1/14 contract` | T1 | 1,423 (actual) |
| 2 | `feat/play-flow-run-2-contract-catalog` | `feat(studio): play-flow-run 2/14 contract-catalog` | T2a | 1,729 (actual) |
| 3 | `feat/play-flow-run-3-contract-steps` | `feat(studio): play-flow-run 3/14 contract-steps` | T2b | 1,542 (actual) |
| 4 | `feat/play-flow-run-4-contract-flows` | `feat(studio): play-flow-run 4/14 contract-flows` | T3 | 1,302 (actual) |
| 5 | `feat/play-flow-run-5-contract-warnings` | `feat(studio): play-flow-run 5/14 contract-warnings` | T4 | 532 (actual) |
| 6 | `feat/play-flow-run-6-example-fixtures` | `test(studio): play-flow-run 6/14 example-fixtures` | T5 | 1,191 (actual) |
| 7 | `feat/play-flow-run-7-acl-catalog` | `feat(play): play-flow-run 7/14 acl-catalog` | T5b, T6a | 1,668 (actual, accepted by the user) |
| 8 | `feat/play-flow-run-8-acl-flows` | `feat(play): play-flow-run 8/14 acl-flows` | T6b | ~1,000 |
| 9 | `feat/play-flow-run-9-campaign-state` | `feat(play): play-flow-run 9/14 campaign-state` | T7–T8 | ~1,500 |
| 10 | `feat/play-flow-run-10-flow-run` | `feat(play): play-flow-run 10/14 flow-run` | T9–T10 | ~1,500 |
| 11 | `feat/play-flow-run-11-control-flow` | `feat(play): play-flow-run 11/14 control-flow` | T11–T12 | ~1,500 |
| 12 | `feat/play-flow-run-12-guided-journal` | `feat(play): play-flow-run 12/14 guided-journal` | T13–T14 | ~1,500 |
| 13 | `feat/play-flow-run-13-trackers-oracles` | `feat(play): play-flow-run 13/14 trackers-oracles` | T15 | ~1,000 |
| 14 | `feat/play-flow-run-14-focus-mode` | `feat(play): play-flow-run 14/14 focus-mode` | T16–T17 | ~1,000 |

Slice 1 first came in at ~5,950 lines (schema, full validation, fixtures, warnings). The user chose to split it into slices 1–4 (2026-10-09). Re-forecast before slice 2 (shape validation ~2,800 lines on the tag) split shapes into catalog (2) and steps (3); the contract now spans slices 1–5. Re-forecast before slice 7 (ACL ~2,200 lines plus the warning fix) split the anti-corruption layer into catalog (7) and flows (8); 14 slices. The full implementation is kept on the local tag `wip/play-flow-run-contract-full` (`66e1586`, never pushed); slices 2–5 port it from there. Before each later slice's writer starts, the parent re-forecasts it and splits it if it will pass ~1,500 lines; titles then use the new total.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc; `v2.schema.json`; contract doc v2 section (structure, rules, example, v1 kept); schema-only test of the doc example | delegated writer (slice 1, 4+ non-trivial files); schema test inline | [x] | `aece3b2` |
| T2a | 2 | Studio `ReleaseContent` accepts v2, part 1: extract shared v1 helpers (fields, oracles); v2 envelope; trackers (counter with `levels`, clock, `hint`); fact slots; table entry `key`; likelihood `chaos.tracker` (counter with the same range); `sceneTypes` and `flows` must be empty until slices 3–4; canonical form; v2 fixtures in the agreement test; a check that the contract doc example equals its fixture (slice 1 review); unit tests | delegated writer (slice 2, 2+ non-trivial files) | [x] | `481f78d` |
| T2b | 3 | Scene Types; steps per kind, forward `next`, `end`, mandatory/skip rules; bands; effects (incl. table entry `effects` and `sceneType` shape); fixtures; unit tests | delegated writer (slice 3, 2+ non-trivial files) | [x] | `edb9e4c` |
| T3 | 4 | Flows and phases (selection, hooks, `act`, `default`, `defaultView`); cross-references (trackers in `flow.trackers`, shortcuts ⊆ flow oracles, reachability, oracle/table/level/entry/Scene Type keys, oracle selection entries); placeholders; the contract doc example moves into `valid/`; fixtures; unit tests | delegated writer (slice 4, 2+ non-trivial files) | [x] | `2a93831` |
| T4 | 5 | Authoring warnings: rule, `CheckGameSystemRelease` query, console output; tests | delegated writer (slice 5, 2+ non-trivial files) | [x] | `eebddad` |
| T5 | 6 | The five examples as v2 fixtures (without 8/8b/10 parts), validated by schema and domain; expected warnings asserted (heist) | delegated writer (slice 6, 2+ non-trivial files) | [x] | `5bc580f` |
| T5b | 7 | Studio: the decision-7 warning follows branches (user decision after slice 6): warn only when the `nextScene` is reachable from a band of the `condition` on T (the band's own effects, or steps its `next` leads to); tests incl. the two-thresholds case and the slice 6 review suggestion; contract doc; VtM fixture may show the fixed-threshold style | delegated writer (slice 7, 2+ non-trivial files) | [x] | `387bb11` |
| T6a | 7 | Play snapshot v2, part 1: trackers, fact slots, table entry `key` / `sceneType` / `effects`, chaos tracker binding, Scene Types with steps, bands and effects (typed, framework-free); translator v2 maps them (flows empty until T6b); v1 → no flows unchanged; tests | delegated writer (slice 7, 2+ non-trivial files) | [x] | `f499539` |
| T6b | 8 | Play snapshot v2, part 2: flows, phases, selection, hooks; the five example fixtures pass the ACL; tests | | [ ] | |
| T7 | 9 | Campaign trackers: init, clamping, edit by hand, chaos binding; API, migration | | [ ] | |
| T8 | 9 | Scene Type, kind, titles; End session; manual scene type and switch; `flowKey` at creation; API, OpenAPI, Behat | | [ ] | |
| T9 | 10 | FlowRun domain: phases, selection, parts, steps with default `next`, mandatory/skip, open play, completed, pause/resume, history | | [ ] | |
| T10 | 10 | FlowRun application, persistence, HTTP, OpenAPI, Behat | | [ ] | |
| T11 | 11 | Branches, `condition`, effects (incl. table entry effects), placeholders, switch limit | | [ ] | |
| T12 | 11 | Hooks as hook Scenes, End session while guided, Move on; domain tests playing examples 2 and 3 | | [ ] | |
| T13 | 12 | Flow choice in campaign creation; delete `flow-prototypes/`; ADR 0016 note | | [ ] | |
| T14 | 12 | Guided journal: step cards per kind, scene-type cards, next step named, scene and hook headers, End scene / End session, pause/resume; e2e | | [ ] | |
| T15 | 13 | Trackers panel (hint, levels, edit); oracle panel order (shortcuts › flow › More oracles); manual switch and offer from rolled entry | | [ ] | |
| T16 | 14 | Focus mode, `defaultView`, toggle per campaign, progress `Act › Phase › Scene type › part · step n/m`, summary with skips | | [ ] | |
| T17 | 14 | Roadmap and glossary updates; review outcomes and PR links (doc-only commit after the last review) | | [ ] | |

## Acceptance criteria

- A v2 release with every part above validates in schema and domain; invalid cases fail both (structural) or only the domain (semantic); v1 releases still validate and publish.
- The five example fixtures validate, pass the ACL, and the heist fixture yields the decision-7 warning.
- A campaign with a flow plays phases, scenes, parts and hooks to `completed`; skips, tracker edits and switches are in the history; free play and the Free journal preset still work.
- The player always sees the next step named; focus mode and journal show the same FlowRun.

## Checks

- Backend: test first (RED → GREEN) with PHPUnit unit tests per rule; `make qa`, `make test`; `make api-check` when endpoints change.
- Frontend: Vitest per component, `make e2e` for the guided flow (slices 12–14).

## Progress

- 2026-10-09: plan approved by the user; issue #39; branch `feat/play-flow-run-1-contract`.
- Slice 1 merged (PR #40, `08e347b`). Slice 2 branch `feat/play-flow-run-2-contract-catalog`.
- T1 done: `v2.schema.json` and the contract doc's schema version 2 section (structure, rules, references, placeholders, warning, canonical form, schema-vs-domain, example). Check: the v2 example and nine hand-made invalid variants validated with opis against the schema (example valid; `end` key, unknown step property, mandatory condition, duplicate flow oracles, 71 shortcuts, duplicate `player` types, bad effect, `flow` key all rejected; repeated `sequence` type accepted). Slice running count: 1,131 lines (1,118 + 13).
- T1 schema-only test: `ReleaseSchemaVersion2Test` checks the contract doc example (fixture `schema-v2/contract-doc-example.json`, outside `valid/` until the domain accepts v2) and four shape errors. `phpunit tests/Unit/Studio/Contract`: 90 tests OK.
- T2a done: `ReleaseFields` / `ReleaseOracles` extracted from `ReleaseContent` (v1 messages unchanged); `ReleaseContent` accepts schema versions 1 and 2; `Version2\ReleaseVersion2` validates the envelope, trackers (counter `levels` via `Version2\Bands`, clock, `hint`), fact slots, entry `key`, `chaos.tracker`; `sceneTypes` / `flows` must be empty and entry `sceneType` / `effects` fail with "not supported yet" (lifted in slices 3–4). Agreement test picks the schema file by `schemaVersion`: valid `v2-minimal`, `v2-catalog`, `v2-null-optionals`; 10 structural and 13 semantic v2 fixtures, 4 generated v2 cases; `unsupported-schema-version` now uses 3. `ReleaseContentVersion2Test` covers each rule on `v2-catalog`. RED: `phpunit tests/Unit/Studio` 287 tests, 12 errors, 86 failures (`unsupported schema version 2`); GREEN: 286 tests OK. `make qa` and `make test` green. Size accepted by the user at ~1,725 lines because ~300 are moved v1 code; the doc-example equality test was dropped: the php container mounts only `./backend`.
- Follow-up: the contract doc example vs fixture equality check needs `docs/` inside the php container; not done.
- T2b done: `ReleaseVersion2` validates Scene Types (shape, limits, unique keys, `oracles` in the oracle namespace) and their `setup` / `play` / `closing` step lists through `Version2\Steps` (every kind, unique keys per list, `end` reserved, forward `next`, mandatory/skip rules, dice, likelihood levels, table entry branches), `Version2\Bands::outcomes` (literal or `{tracker}` `upTo`, ordering, catch-all, empty band kept as `{}`) and `Version2\Effects` (tagged union, referenced trackers / Scene Types exist); `Version2\Catalog` holds the release keys, `Version2\StepParts` walks a step's outcomes. Table entry `sceneType` / `effects` are validated (no longer "not supported yet"); `flows` must still be empty; flow-scoped references and placeholders stay for slice 4. Fixtures: valid `v2-scene-types` (every step kind, band and effect) and `v2-integer-valued-floats`; 10 structural (+3 generated) and 20 semantic v2 cases; three "not supported yet" cases removed. RED: `phpunit tests/Unit/Studio` 392 tests, 15 errors, 96 failures; GREEN: 392 tests OK. `make qa` and `make test` green. Slice size: 1,542 changed lines (1,462 + 80).
- Slice 2 review fixes: (1) chaos `1.0`/`9.0` and (2) integer-valued floats in tracker numbers, level and band `upTo` were already accepted, because `ReleaseContent::fromArray` normalizes them before version 2 validation; now proved by the `v2-integer-valued-floats` fixture (schema and domain, canonical ints) and unit cases (no RED: they passed first run). (3) An empty-array entry `sceneType` now fails (`must be a string`) instead of reaching the canonical content (RED → GREEN).
- T3 done: `ReleaseVersion2` validates flows (shape, limits, unique keys, at most one `default`, `defaultView`, `oracles` / `trackers` exist and are unique, 1–20 phases) and phases (unique keys, `act`, `mode`, `sequence` / `player` / `oracle` selection, the seven hook lists through `Version2\Steps`); `flows: not supported yet` is gone. `Version2\FlowReferences` computes what each flow reaches (selections, `nextScene` / `switchSceneType` effects, entry `sceneType` of rolled tables, nested tables) and checks oracle selection entries, trackers in `flow.trackers`, Scene Type shortcuts ⊆ flow oracles and placeholders; `Version2\Placeholders` checks `{tracker:key}`, `{step:key}` and `{answer}` (release scope outside any flow); `Version2\StepParts` walks effects, tracker references, texts and rolled tables. Ported from the tag without `AuthoringWarnings` and the unused `Catalog::hasOracle`. The contract doc example moved to `valid/v2-contract-doc-example.json` (schema and domain); `ReleaseSchemaVersion2Test` keeps its four shape errors on it. Fixtures: valid `v2-every-part` (two flows, every hook, selection rule and placeholder), flow nulls in `v2-null-optionals`; 6 structural (+2 generated) and 10 semantic flow cases; `v2-flows-not-supported-yet` removed. Contract doc status note updated. RED: `phpunit tests/Unit/Studio` 476 tests, 14 errors, 70 failures; GREEN: 476 tests OK. Slice size: 1,302 changed lines.
- Slice 3 review suggestions done in T3: a valid `roll` without `bands` (unit variant and `v2-every-part`); `itAcceptsItsOwnCanonicalArrayAndJson` now feeds `toArray()` straight back into `fromArray()` and compares the canonical JSON for `v2-every-part` (empty bands `{}`, empty sheet, oracle branches), `v2-scene-types` and the v1 contract doc example.
- T4 done: `Version2\AuthoringWarnings` computes the decision-7 warning over flow phase hooks, then Scene Type step lists (condition on T, then a `nextScene S` in the same or a later step, S with no `set` / negative `add` on T); `ReleaseContent::warnings()` returns them for version 2 (version 1: none), outside the content and hash. Studio query `CheckGameSystemRelease` → `GameSystemReleaseCheck` (key, schema version, warnings); `app:gamesystem:publish` asks it first and prints `Warning: <message>` lines before `Published …` / `Unchanged …` (exit 0). Fixture `valid/v2-forced-scene-without-relief` (schema and domain). Contract doc top line, status note and Publishing note updated. Ported from the tag without its `Catalog::hasOracle` and test rewrites. Slice 4 review fix: `FlowReferences::checkTrackers` appends references in place instead of a spread per step / entry effect (same result; existing flow-tracker tests green). RED: `phpunit tests/Unit/Studio` + console integration test 508 tests, 18 errors, 3 failures; GREEN: 509 tests OK. Slice size: 532 changed lines (522 + 10).
- The contract (slices 1–5: schema, Studio validation, authoring warnings) is complete; Play reads version 2 from slice 7.
- T5 done: the five flow examples as schema version 2 releases in `backend/tests/Fixtures/Studio/releases/examples/` (`vtm-chronicle`, `cpr-heist`, `mythic-session`, `cpr-campaign-in-acts`, `west-marches`), original text, 3–8 entries per table; their `README.md` lists per example what waits for features 8, 8b and 10 (steps kept as plain prompts or tables, fact slots declared). `ReleaseSchemaAgreementTest` runs every example through the schema and the domain (valid cases, canonical form conforms); `ExampleReleasesTest` pins each example's warnings and that every example file has an expectation. Warnings: heist `flows[0].phases[2].worldTurn[1]: nextScene firefight does not lower tracker alarm` (intended); VtM, Mythic, campaign in acts and West Marches none (Hunters strike, Inquisition raid, Hit squad and Month's end lower their trackers; West Marches forces Encounter / Combat through table entry effects, which the rule does not check). VtM uses the roll-under masquerade style: the fixed threshold in the same world turn as the hunters `condition` would warn, because the rule counts every earlier `condition` of the list. Slice 5 review decision done: the contract doc states that only S's own step effects lower T, and `AuthoringWarningsTest` pins it (Firefight rolling `complications`, whose Lucky break lowers the alarm, still warns; passed on first run, the rule already behaved so). No production change and no validation case rejected. RED: `phpunit tests/Unit/Studio` 490 tests, 6 errors (no example files), 1 failure; GREEN: `phpunit tests/Unit/Studio/Contract` 182 tests OK. Slice size: 1,191 changed lines (1,186 + 5).
- T5b done: `AuthoringWarnings` warns only for a `nextScene` the `condition` on T decides: reached from some of its bands but not from all. A band reaches its own effects and every effect of the steps it leads to (its `next`, else the condition's `next`, else the following step; then every outcome of each reached step, with the step's default unless its outcomes are exhaustive, forward until `end`). An effect every band reaches happens whatever T is, so it does not warn; a step forcing the scene warns once per tracker. The first band is not special (bands are plain ordered ranges). The VtM fixture now uses the fixed-threshold masquerade (`condition masquerade`: up to 7 nothing, above it Inquisition raid; hint "At 8+ the Second Inquisition moves") at the start of the world turn; README updated. Contract doc and class doc state the rule. New `AuthoringWarningsTest` cases: every band reaches the forced scene (no warning), a band jumps over it (warns), two thresholds in one world turn in both orders (no warning; slice 6 review suggestion) and, with an Inquisition raid that no longer lowers masquerade, only the masquerade warning. RED: `phpunit AuthoringWarningsTest ExampleReleasesTest` 30 tests, 6 failures (false `hunters-strike does not lower masquerade` / `inquisition-raid does not lower hunters`); GREEN: `phpunit tests/Unit/Studio` 515 tests OK, `tests/Integration/Studio` 18 OK. Heist and the example warnings unchanged.
- T6a done: Play Domain catalog of schema version 2, framework-free and immutable. `GameSystem\`: `Tracker` (counter or clock; a clock runs 0..segments from 0), `TrackerKind`, `TrackerLevel`, `FactSlot`, `FactSlotType`, `TableEntryMetadata` (entry `key`, `sceneType`, effects), `SceneType` (purpose, tips, oracle shortcuts, `setup` / `play` / `closing`); `SnapshotLikelihoodOracle::chaosTracker()`. `GameSystem\Flow\`: `StepList` (`step(key)`), abstract `Step` (key, title, prompt, tip, mandatory, next, effects) with `PromptStep`, `OracleStep`, `TableStep` (`outcomeFor(entry)` → branch or `otherwise`), `RollStep`, `ChoiceStep` (`option(key)`, `skip`), `ConditionStep`; `Outcome` (next, effects), `Band` (`upTo`: int, `TrackerReference` or null), `TableBranch`, `ChoiceOption`, `OracleBranches` (`for(answer)`: an exceptional answer without its branch uses yes / no); effects `TrackerEffect` (`TrackerOperation` add / set, int or `TrackerReference`), `NextSceneEffect`, `SwitchSceneTypeEffect`, `EndPhaseEffect`, `SceneTitleEffect` behind the `Effect` interface. The new value objects expose public readonly properties (kept lean; the snapshot keeps getters). `GameSystemSnapshot` gains `trackers()` / `tracker(key)`, `factSlots()`, `sceneTypes()` / `sceneType(key)` (unique keys) and `rolledEntry(OracleTableStep)` (the metadata of the entry a roll selected, ranged or weighted); version 1 snapshots have none. `GameSystemReleaseTranslator` supports schema versions 1 and 2: version 2 strips entry `key` / `sceneType` / `effects` and `chaos.tracker` before the Randomness definitions, maps the catalog and fails closed (`InvalidGameSystemRelease` with the path) on unknown tracker, fact slot, step, effect or operation kinds, non-integer numbers, malformed `upTo` / values and duplicate step, tracker or Scene Type keys; flows are not read (slice 8). Contract doc status lines updated. Tests: `GameSystemReleaseTranslatorVersion2Test` (every part of `v2-scene-types` and `v2-contract-doc-example`, lookups, 11 malformed cases), the unsupported-version case now uses 3, and `PlayReadsPublishedGameSystemReleasesTest` publishes the five examples through Studio (canonical form) and reads them. RED: `phpunit tests/Unit/Play/Infrastructure/GameSystem` 40 tests, 8 errors, 12 failures (`unsupported schema version 2`); integration with version 2 unsupported: 11 tests, 5 errors; GREEN: `phpunit tests/Unit/Play` 205 tests OK, the integration test 11 OK. Slice size: 1,668 changed lines (1,600 + 68; T5b 288 incl. the parent's doc edits, T6a 1,380); over the ~1,400 target, under the ~1,700 stop.
- Slice 7 review fix: `AuthoringWarnings` compares the `nextScene` effects each band reaches by Scene Type, not by path: a Scene Type is decided when some bands force it and others do not, and it warns once per condition and tracker, at the first step forcing it. Contract doc wording made precise. Tests: every band forcing the same Scene Type (own effects; different steps) → no warning; a decided Scene Type forced at two steps → one warning at the first; `itWarnsOncePerTrackerAndForcedScene` now has one `edge` band forcing Firefight (it pinned the bug). RED: `phpunit AuthoringWarningsTest` 26 tests, 3 failures; GREEN: `phpunit tests/Unit/Studio` 517 tests OK, `tests/Integration/Studio` 18 OK; example warnings unchanged.
- Contract choices on unstated details (implemented on the tag, ported in slices 2–4) (consistent with ADR 0017/0018):
  - Limits the rules leave open: bands 1–20 (`condition`) or ≤20 (`roll`), counter `levels` ≤20, table `branches` ≤1000, Scene Type / flow `oracles` ≤70 (the oracle namespace size), tracker effect `value` ±1000, `roll` dice 1–100 characters.
  - Bands: every band but the last needs `upTo`; the last must omit it.
  - Canonical form: empty optional lists, `false` flags, empty outcomes and empty `oracle` branches are omitted (same meaning as absent); an empty band stays `{}` so the canonical JSON keeps conforming to the schema.
  - Reachability follows nested tables of every rolled table.
  - Outside any flow, `{tracker:key}` / `{step:key}` must name a tracker / step of the release.
  - The decision-7 warning also checks Scene Type step lists (`sceneTypes[i].<list>[j]: …`).
  - Structural (schema and domain): `end` as a step key, a `mandatory` condition, duplicates in Scene Type / flow `oracles`, flow `trackers` and `player` Scene Types.

## Reviews

| Slice | Review | PR |
|---|---|---|
| 1 | Medium risk; reliability lens; approved and acknowledged (lineage `review-3238b0d0acd25bd4`). Non-blocking: the schema test covers only four shape rules (fixtures in slices 2–4 cover the rest); the test validates a fixture copy of the doc example (T2a adds an equality check) | #40 (merged) |
| 2 | Medium risk; reliability lens; approved and acknowledged (lineage `review-b731c078a968d8b5`). Non-blocking, fixed in T2b: the chaos tracker range check rejects `1.0`/`9.0`; tracker numbers and band `upTo` reject integer-valued floats such as `6.0` (schema and v1 oracles accept them); an empty entry `sceneType` array is kept in the canonical content | #41 (merged) |
| 3 | Medium risk; reliability lens; approved and acknowledged (lineage `review-d926c33ab402a0d8`). Non-blocking, done in T3: a valid `roll` without `bands`; a round-trip test (`toArray()` back into `fromArray()`) since `ReleaseFields::object` accepts an empty `\stdClass` everywhere | #42 (merged) |
| 4 | Medium risk; reliability lens; approved and acknowledged (lineage `review-fbd39976b0423348`). Non-blocking, fixed in T4: `FlowReferences::checkTrackers` rebuilt its reference list with a spread per step (quadratic); now appends in place | #43 (merged) |
| 5 | Medium risk; reliability lens; approved and acknowledged (lineage `review-36b1e75ab3bcebf5`). Non-blocking: only the forced Scene Type's own step effects count as lowering the tracker, not effects on table entries it rolls. User decision (2026-10-09): keep that rule (a rolled entry lowers the tracker only by chance); T5 documents it and pins it with a test | #44 (merged) |
| 6 | Medium risk; reliability lens; approved and acknowledged (lineage `review-dc54d5e92eab40c3`). Non-blocking: pin the README claim that a fixed-threshold masquerade next to the hunters condition warns. The writer found that false positive; user decision (2026-10-09): the warning follows branches (T5b) | #45 (merged) |
| 7 | Medium risk; reliability lens; approved and acknowledged (lineage `review-191a438771206817`). Non-blocking, fixed in T6b's slice: the "decided" check keys reached effects by path, not Scene Type, so every band forcing the same Scene Type still warns, twice | #46 (merged) |

## Next step

Slice 8 (warning fix, T6b) on `feat/play-flow-run-8-acl-flows`.