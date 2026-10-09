# Feature: play-flow-run

- **Locator:** `odd/tasks/play-flow-run.md` · Engram topic `odd/play-flow-run/tasks`
- **Issue:** #39 · **Branch (slice 1):** `feat/play-flow-run-1-contract` (from `main` `f4d775b`)
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
| 1 | `feat/play-flow-run-1-contract` | `feat(studio): play-flow-run 1/12 contract` | T1 | ~1,300 (actual) |
| 2 | `feat/play-flow-run-2-contract-shapes` | `feat(studio): play-flow-run 2/12 contract-shapes` | T2 | ~1,500 |
| 3 | `feat/play-flow-run-3-contract-references` | `feat(studio): play-flow-run 3/12 contract-references` | T3 | ~1,500 |
| 4 | `feat/play-flow-run-4-contract-warnings` | `feat(studio): play-flow-run 4/12 contract-warnings` | T4 | ~500 |
| 5 | `feat/play-flow-run-5-example-fixtures` | `test(studio): play-flow-run 5/12 example-fixtures` | T5 | ~1,500 |
| 6 | `feat/play-flow-run-6-acl` | `feat(play): play-flow-run 6/12 acl` | T6 | ~1,500 |
| 7 | `feat/play-flow-run-7-campaign-state` | `feat(play): play-flow-run 7/12 campaign-state` | T7–T8 | ~1,500 |
| 8 | `feat/play-flow-run-8-flow-run` | `feat(play): play-flow-run 8/12 flow-run` | T9–T10 | ~1,500 |
| 9 | `feat/play-flow-run-9-control-flow` | `feat(play): play-flow-run 9/12 control-flow` | T11–T12 | ~1,500 |
| 10 | `feat/play-flow-run-10-guided-journal` | `feat(play): play-flow-run 10/12 guided-journal` | T13–T14 | ~1,500 |
| 11 | `feat/play-flow-run-11-trackers-oracles` | `feat(play): play-flow-run 11/12 trackers-oracles` | T15 | ~1,000 |
| 12 | `feat/play-flow-run-12-focus-mode` | `feat(play): play-flow-run 12/12 focus-mode` | T16–T17 | ~1,000 |

Slice 1 first came in at ~5,950 lines (schema, full validation, fixtures, warnings). The user chose to split it into slices 1–4 (2026-10-09). The full implementation is kept on the local tag `wip/play-flow-run-contract-full` (`66e1586`, never pushed); slices 2–4 port it from there. Before each later slice's writer starts, the parent re-forecasts it and splits it if it will pass ~1,500 lines; titles then use the new total.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc; `v2.schema.json`; contract doc v2 section (structure, rules, example, v1 kept); schema-only test of the doc example | delegated writer (slice 1, 4+ non-trivial files); schema test inline | [x] | see Progress |
| T2 | 2 | Studio `ReleaseContent` accepts v2: shape validation (envelope, trackers, fact slots, Scene Types, flows, phases, steps per kind, bands, effects, limits, key uniqueness, `end`, forward `next`) and canonical form; shape fixtures; agreement test per schema version; unit tests | | [ ] | |
| T3 | 3 | Cross-references (trackers in `flow.trackers`, shortcuts ⊆ flow oracles, reachability, oracle/table/level/entry keys, oracle selection entries, chaos tracker) and placeholders; semantic fixtures; unit tests | | [ ] | |
| T4 | 4 | Authoring warnings: rule, `CheckGameSystemRelease` query, console output; tests | | [ ] | |
| T5 | 5 | The five examples as v2 fixtures (without 8/8b/10 parts), validated by schema and domain; expected warnings asserted (heist) | | [ ] | |
| T6 | 6 | Play snapshot v2 (trackers, Scene Types, flows, phases, steps, effects, bands) and translator v2; v1 → no flows; fixtures pass the ACL | | [ ] | |
| T7 | 7 | Campaign trackers: init, clamping, edit by hand, chaos binding; API, migration | | [ ] | |
| T8 | 7 | Scene Type, kind, titles; End session; manual scene type and switch; `flowKey` at creation; API, OpenAPI, Behat | | [ ] | |
| T9 | 8 | FlowRun domain: phases, selection, parts, steps with default `next`, mandatory/skip, open play, completed, pause/resume, history | | [ ] | |
| T10 | 8 | FlowRun application, persistence, HTTP, OpenAPI, Behat | | [ ] | |
| T11 | 9 | Branches, `condition`, effects (incl. table entry effects), placeholders, switch limit | | [ ] | |
| T12 | 9 | Hooks as hook Scenes, End session while guided, Move on; domain tests playing examples 2 and 3 | | [ ] | |
| T13 | 10 | Flow choice in campaign creation; delete `flow-prototypes/`; ADR 0016 note | | [ ] | |
| T14 | 10 | Guided journal: step cards per kind, scene-type cards, next step named, scene and hook headers, End scene / End session, pause/resume; e2e | | [ ] | |
| T15 | 11 | Trackers panel (hint, levels, edit); oracle panel order (shortcuts › flow › More oracles); manual switch and offer from rolled entry | | [ ] | |
| T16 | 12 | Focus mode, `defaultView`, toggle per campaign, progress `Act › Phase › Scene type › part · step n/m`, summary with skips | | [ ] | |
| T17 | 12 | Roadmap and glossary updates; review outcomes and PR links (doc-only commit after the last review) | | [ ] | |

## Acceptance criteria

- A v2 release with every part above validates in schema and domain; invalid cases fail both (structural) or only the domain (semantic); v1 releases still validate and publish.
- The five example fixtures validate, pass the ACL, and the heist fixture yields the decision-7 warning.
- A campaign with a flow plays phases, scenes, parts and hooks to `completed`; skips, tracker edits and switches are in the history; free play and the Free journal preset still work.
- The player always sees the next step named; focus mode and journal show the same FlowRun.

## Checks

- Backend: test first (RED → GREEN) with PHPUnit unit tests per rule; `make qa`, `make test`; `make api-check` when endpoints change.
- Frontend: Vitest per component, `make e2e` for the guided flow (slices 10–12).

## Progress

- 2026-10-09: plan approved by the user; issue #39; branch `feat/play-flow-run-1-contract`.
- T1 done: `v2.schema.json` and the contract doc's schema version 2 section (structure, rules, references, placeholders, warning, canonical form, schema-vs-domain, example). Check: the v2 example and nine hand-made invalid variants validated with opis against the schema (example valid; `end` key, unknown step property, mandatory condition, duplicate flow oracles, 71 shortcuts, duplicate `player` types, bad effect, `flow` key all rejected; repeated `sequence` type accepted). Slice running count: 1,131 lines (1,118 + 13).
- T1 schema-only test: `ReleaseSchemaVersion2Test` checks the contract doc example (fixture `schema-v2/contract-doc-example.json`, outside `valid/` until the domain accepts v2) and four shape errors. `phpunit tests/Unit/Studio/Contract`: 90 tests OK.
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

## Next step

Slice 1: review, then PR. Then slice 2 (T2) from the latest `main`.
