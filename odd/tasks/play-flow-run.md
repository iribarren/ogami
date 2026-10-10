# Feature: play-flow-run

- **Locator:** `odd/tasks/play-flow-run.md` · Engram topic `odd/play-flow-run/tasks`
- **Issue:** #39 · **Current branch:** `feat/play-flow-run-14-flow-run-guidance` (from `main` `4fd22f6`)
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

### Decisions on details

Contract choices the ADRs leave open (consistent with ADR 0017/0018; implemented on the tag, ported in slices 2–4, refined in 5–7):

- Limits: bands 1–20 (`condition`) or ≤20 (`roll`), counter `levels` ≤20, table `branches` ≤1000, Scene Type / flow `oracles` ≤70 (the oracle namespace size), tracker effect `value` ±1000, `roll` dice 1–100 characters.
- Bands: every band but the last needs `upTo`; the last must omit it. The first band is not special (plain ordered ranges).
- Canonical form: empty optional lists, `false` flags, empty outcomes and empty `oracle` branches are omitted (same meaning as absent); an empty band stays `{}` so the canonical JSON keeps conforming to the schema.
- Reachability follows nested tables of every rolled table.
- Outside any flow, `{tracker:key}` / `{step:key}` must name a tracker / step of the release.
- Structural (schema and domain): `end` as a step key, a `mandatory` condition, duplicates in Scene Type / flow `oracles`, flow `trackers` and `player` Scene Types.
- The decision-7 warning also checks Scene Type step lists (`sceneTypes[i].<list>[j]: …`); it fires only for a `nextScene` the `condition` on T decides (some bands reach it, not all; a band reaches its own effects and the steps its `next` leads to), compared by Scene Type, once per condition and tracker, at the first step forcing it (T5b, slice 7 review). Only S's own step effects lower T.

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

### FlowRun model (T9a)

- `Campaign\FlowRun\FlowRun`: an entity inside the Campaign (not persisted until slice 15). `FlowRunContext` passes the pinned release, the Flow, the time and the current session and scene.
- `status` active | completed (paused in T9b); `stage` scenePick | scene; `ScenePart` sceneOpening → setup → play → open → closing → sceneClosing.
- Commands on the Campaign: `pickSceneType`, `pickSceneTypeByOracle`, `completeFlowStep(stepKey, StepResult)`, `skipFlowStep`, `endFlowScene(sceneNumber)`, `moveOn`. Each names the step, scene or stage it expects (`FlowRunPositionMismatch`).
- Seams for slice 16 are marked `Hooks:`; effects call `forceNextSceneType()` and `endPhaseAfterScene()`.
- Answer text: prompt text; table entry texts joined with " › "; oracle answer label; choice option label.
- **Move on (user decision, 2026-10-10):** available at any time in a loop phase while guided, as a player-triggered `endPhase`: during a scene it ends the phase after the closing parts; at the scene pick it ends the phase at once. Single-type auto-pick stays (ADR 0018 decisions 8 and 11). T9a allows it at the pick only; T9b extends it.

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
| 1 | `feat/play-flow-run-1-contract` | `feat(studio): play-flow-run 1/19 contract` | T1 | 1,423 (actual) |
| 2 | `feat/play-flow-run-2-contract-catalog` | `feat(studio): play-flow-run 2/19 contract-catalog` | T2a | 1,729 (actual) |
| 3 | `feat/play-flow-run-3-contract-steps` | `feat(studio): play-flow-run 3/19 contract-steps` | T2b | 1,542 (actual) |
| 4 | `feat/play-flow-run-4-contract-flows` | `feat(studio): play-flow-run 4/19 contract-flows` | T3 | 1,302 (actual) |
| 5 | `feat/play-flow-run-5-contract-warnings` | `feat(studio): play-flow-run 5/19 contract-warnings` | T4 | 532 (actual) |
| 6 | `feat/play-flow-run-6-example-fixtures` | `test(studio): play-flow-run 6/19 example-fixtures` | T5 | 1,191 (actual) |
| 7 | `feat/play-flow-run-7-acl-catalog` | `feat(play): play-flow-run 7/19 acl-catalog` | T5b, T6a | 1,668 (actual, accepted by the user) |
| 8 | `feat/play-flow-run-8-acl-flows` | `feat(play): play-flow-run 8/19 acl-flows` | T6b | 709 (actual) |
| 9 | `feat/play-flow-run-9-campaign-trackers` | `feat(play): play-flow-run 9/19 campaign-trackers` | T7 | ~1,810 (actual, accepted by the user) |
| 10 | `feat/play-flow-run-10-campaign-scenes` | `feat(play): play-flow-run 10/19 campaign-scenes` | T8a | ~2,100 (actual, accepted by the user) |
| 11 | `feat/play-flow-run-11-campaign-flow` | `feat(play): play-flow-run 11/19 campaign-flow` | T8b | 1,956 (actual; reviewed as two candidates) |
| 12 | `fix/play-flow-run-12-review-fixes` | `fix(play): play-flow-run 12/19 review-fixes` | — | 237 (actual) |
| 13 | `feat/play-flow-run-13-flow-run` | `feat(play): play-flow-run 13/19 flow-run` | T9a | 1,930 (actual) |
| 14 | `feat/play-flow-run-14-flow-run-guidance` | `feat(play): play-flow-run 14/19 flow-run-guidance` | T9b | ~1,000 |
| 15 | `feat/play-flow-run-15-flow-run-api` | `feat(play): play-flow-run 15/19 flow-run-api` | T10 | ~1,600 |
| 16 | `feat/play-flow-run-16-control-flow` | `feat(play): play-flow-run 16/19 control-flow` | T11–T12 | ~1,600 |
| 17 | `feat/play-flow-run-17-guided-journal` | `feat(play): play-flow-run 17/19 guided-journal` | T13–T14 | ~1,600 |
| 18 | `feat/play-flow-run-18-trackers-oracles` | `feat(play): play-flow-run 18/19 trackers-oracles` | T15 | ~1,000 |
| 19 | `feat/play-flow-run-19-focus-mode` | `feat(play): play-flow-run 19/19 focus-mode` | T16–T17 | ~1,000 |

Slice 1 first came in at ~5,950 lines (schema, full validation, fixtures, warnings). The user chose to split it into slices 1–4 (2026-10-09). Re-forecast before slice 2 (shape validation ~2,800 lines on the tag) split shapes into catalog (2) and steps (3); the contract now spans slices 1–5. Re-forecast before slice 7 (ACL ~2,200 lines plus the warning fix) split the anti-corruption layer into catalog (7) and flows (8); 14 slices. Re-forecast before slice 9 (~2,800 lines with generated API files) split campaign state into trackers (9) and scenes (10); 15 slices. Re-forecast before slice 10 (~2,000 with generated files and review fixes) split T8 into scenes (10) and sessions + flow choice (11); 16 slices. The full implementation is kept on the local tag `wip/play-flow-run-contract-full` (`66e1586`, never pushed); slices 2–5 port it from there. Before each later slice's writer starts, the parent re-forecasts it and splits it if it will pass ~1,500 lines; titles then use the new total.

From slice 10 on, the user set the per-slice planning limit for this feature to ~2,000 lines including generated files and tests (2026-10-10). Slice 11 (1,956 lines) exceeded the native reviewer's context budget and was reviewed per commit, so slices from 12 on aim at ~1,600–1,700 lines. FlowRun splits into domain (12) and application/persistence/HTTP (13); 17 slices. The FlowRun domain alone came to ~1,850 lines with tests, so (user, 2026-10-10) slice 12 holds only the review fixes, T9 splits into T9a core (13) and T9b guidance and read model (14); 19 slices. Unfinished work is kept on local tags `wip/play-flow-run-t9a` and `wip/play-flow-run-flowrun-full` (never pushed)..

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
| T6b | 8 | Play snapshot v2, part 2: flows, phases, selection, hooks; the five example fixtures pass the ACL; tests | delegated writer (slice 8, 2+ non-trivial files) | [x] | `ef1c66d` |
| T7 | 9 | Campaign trackers: init, clamping, edit by hand, chaos binding; API, migration | delegated writer (slice 9, 2+ non-trivial files) | [x] | `efe389a` |
| T8a | 10 | Scenes: optional Scene Type, kind `scene` \| `hook` with the hook name, default titles (`<Scene Type> <n>` per type; hook titles), start a scene by hand with an optional Scene Type, switch the current Scene's type by hand; API, OpenAPI, Behat | delegated writer (slice 10, 2+ non-trivial files) | [x] | `d5d3939` |
| T8b | 11 | Sessions as sittings (`EndSession`, `endedAt`, no scenes until a new session); `flowKey` at creation (null = Play freely; must name a release flow), flows and Scene Types summary in the campaign view; API, OpenAPI, Behat | delegated writer (slice 11, 2+ non-trivial files) | [x] | `738829b` |
| T9a | 13 | FlowRun core: started at creation for a `flowKey`; scene pick (sequence, player, oracle, auto-pick, forced slot); parts; steps with typed results and default `next`; skip/mandatory; condition auto-advance; position checks; phase modes, Move on at the pick, `completed`; history for skips, phase ends, completion | delegated writer (slice 12 writer, carried to slice 13) | [x] | `bbfc562` |
| T9b | 14 | Guidance pause/resume and scene abandonment; history for hand tracker edits and Scene Type switches; "Move on" any time in a loop phase (user decision); UI read model (position, current step, pick offer, next step named) | | [ ] | |
| T10 | 15 | FlowRun application, persistence, HTTP, OpenAPI, Behat | | [ ] | |
| T11 | 16 | Branches, `condition`, effects (incl. table entry effects), placeholders, switch limit | | [ ] | |
| T12 | 16 | Hooks as hook Scenes, End session while guided, Move on; domain tests playing examples 2 and 3 | | [ ] | |
| T13 | 17 | Flow choice in campaign creation; delete `flow-prototypes/`; ADR 0016 note | | [ ] | |
| T14 | 17 | Guided journal: step cards per kind, scene-type cards, next step named, scene and hook headers, End scene / End session, pause/resume; e2e | | [ ] | |
| T15 | 18 | Trackers panel (hint, levels, edit); oracle panel order (shortcuts › flow › More oracles); manual switch and offer from rolled entry | | [ ] | |
| T16 | 19 | Focus mode, `defaultView`, toggle per campaign, progress `Act › Phase › Scene type › part · step n/m`, summary with skips | | [ ] | |
| T17 | 19 | Roadmap and glossary updates; review outcomes and PR links (doc-only commit after the last review) | | [ ] | |

## Acceptance criteria

- A v2 release with every part above validates in schema and domain; invalid cases fail both (structural) or only the domain (semantic); v1 releases still validate and publish.
- The five example fixtures validate, pass the ACL, and the heist fixture yields the decision-7 warning.
- A campaign with a flow plays phases, scenes, parts and hooks to `completed`; skips, tracker edits and switches are in the history; free play and the Free journal preset still work.
- The player always sees the next step named; focus mode and journal show the same FlowRun.

## Checks

- Backend: test first (RED → GREEN) with PHPUnit unit tests per rule; `make qa`, `make test`; `make api-check` when endpoints change.
- Frontend: Vitest per component, `make e2e` for the guided flow (slices 17–19).

## Progress

Per-task evidence (RED → GREEN counts, files, tests) is in each work-unit commit and its PR. Sizes are changed lines vs `main`.

- 2026-10-09: plan approved by the user; issue #39.
- T1: `v2.schema.json`, contract doc v2 section, `ReleaseSchemaVersion2Test` (doc example, four shape errors) · `aece3b2` · slice 1: 1,131.
- T2a: `ReleaseContent` accepts v1 and v2 (envelope, trackers, fact slots, entry `key`, `chaos.tracker`); v1 helpers extracted · `481f78d` · ~1,725, accepted by the user (~300 moved v1 code).
- T2b: Scene Types, steps, bands, effects; slice 2 review fixes · `edb9e4c` · 1,542.
- T3: flows, phases, cross-references, placeholders; slice 3 review suggestions · `2a93831` · 1,302.
- T4: decision-7 authoring warnings, `CheckGameSystemRelease`, console output; slice 4 review fix · `eebddad` · 532.
- T5: the five examples as v2 fixtures, warnings pinned (heist warns) · `5bc580f` · 1,191.
- T5b: the warning follows branches; VtM uses the fixed-threshold masquerade · `387bb11` · 288.
- T6a: Play snapshot v2 catalog and translator (flows not read yet) · `f499539` · 1,380 (slice 7: 1,668, accepted by the user).
- Slice 7 review fix: the warning compares forced scenes by Scene Type · `eb62c16` (slice 8).
- T6b: Play flows, phases, selection, hooks; the examples pass the ACL · `ef1c66d` · slice 8: 709.
- Slice 8 review fix: flow `default` is an optional boolean, else fails closed · `f3087c7` (slice 9).
- T7: campaign trackers (init, clamp, hand edit, chaos binding), `PUT /campaigns/{id}/trackers/{key}`, migration `Version20261009120000` · `efe389a` · slice 9: ~1,810 incl. ~400 generated, accepted by the user.
- Slice 9 review fix and slice 8 follow-up: missing tracker values read as starting values; step `mandatory` fails closed when not a boolean · `fe2fa6e` (slice 10).
- T8a: scene kind, Scene Type, hook Scenes, default titles, start and switch by hand · `d5d3939` · slice 10: ~2,140 incl. 317 generated, accepted by the user.
- Slice 10 review fix: the Scene Type switch guards on kind `hook`; the reader rejects kind `hook` without a hook and a hook on kind `scene` · see Reviews (slice 11).
- T8b: `EndSession` (`POST /campaigns/{id}/sessions/current/end` → 200 session view with `endedAt`; 404, 409), `flowKey` at creation (absent or null plays freely, the default Flow is not applied; unknown → 404), campaign view `flowKey`, `flows`, `sceneTypes`, catalog `flows`, migration `Version20261010120000` (`flow_key`) · see Reviews · slice 11: ~1,960 incl. 367 generated (OpenAPI spec, TS types).
- Play decisions (slice 11): an ended session is not under way, so `currentSessionNumber` / `currentSceneNumber` read null and starting a scene, recording a journal entry or switching a Scene Type fails with the existing `NoCurrentSession` / `NoCurrentScene` (409); ending again or before the first session is `NoCurrentSession` (409); `endedAt` lives in the sessions JSONB (sessions stored before have none and are under way); `flow_key` is a nullable column; the catalog lists a release Play cannot read without Flows, as before.
- Slice 11 review fix: `EndSession` names the session under way (the controller reads its number first, a mismatch is `NoCurrentSession`, 409) and the response returns that session; a test for the end-session 409 on a concurrent save; `Scene::reconstitute` rejects kind `hook` without a hook and a hook on kind `scene` (`InvalidSceneKind`), and the sessions reader maps that to its malformed-value error · see Reviews (slice 12).
- Play decisions (slices 9–10): a malformed request body is 400 on every Play endpoint (a tracker value is any integer, clamped); unknown release keys (Tracker, Scene Type; path or body) are 404; `tracker_values` uses Doctrine's `json` type (jsonb: an empty map is stored as `[]`, old rows `{}`, views follow release order); scenes stored before kinds read as scenes of play without a Scene Type (no migration); FlowRun history for hand edits and switches comes with T10; new Play value objects expose public readonly properties.
- Follow-up: the check that the contract doc example equals its fixture needs `docs/` inside the php container (it mounts only `./backend`); not done.

- T9a: FlowRun core; 24 tests (22 rules + heist and Mythic walk-throughs). Code was written before its tests, so there is no real RED; the first test run failed only on wrong expectations. ~1,900 lines.
- Slice 13 review fixes: Campaign FlowRun commands run on a copy of the campaign and keep the outcome only on success (a full session leaves FlowRun, sessions and Tracker values as before; RED 4 → GREEN); the repository contract and the stored-Flow-key test compare the whole Campaign without its FlowRun again (slice 14).

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
| 8 | Medium risk; reliability lens; approved and acknowledged (lineage `review-28ef6b16f762865d`); commits `eb62c16` (warning fix), `ef1c66d` (T6b). Non-blocking, fixed in slice 9: a non-boolean flow `default` is read as false instead of failing closed | #47 (merged) |
| 9 | Medium risk; reliability lens; approved and acknowledged (lineage `review-f88c790fab5a6b38`); commits `f3087c7` (flow default fix), `efe389a` (T7). Non-blocking, fixed in slice 10: a v2 campaign stored before T7 has no tracker values, so its bound oracle throws an unmapped `UnknownCampaignTracker` (500); missing values now read as initial; tests for that fallback and for the tracker PUT 409 | #48 (merged) |
| 10 | Medium risk; reliability lens; approved and acknowledged (lineage `review-319443b1ca8c6722`); commits `fe2fa6e` (review fixes), `d5d3939` (T8a). Non-blocking, fixed in slice 11: the Scene Type switch guards on the hook name, not on kind `hook`; the reader accepts kind `hook` without a hook name | #49 (merged) |
| 11 | Over the reviewer's context budget as one candidate; reviewed per commit, both medium risk, reliability lens, approved and acknowledged: `738829b` (T8b) and `3432431` (fix + doc condense, in a temporary worktree). Non-blocking, fixed in slice 12: the end-session response re-reads the campaign and may return a newer session; no test for the end-session 409; `Scene::reconstitute` allows kind/hook combinations the reader rejects | #50 (merged) |
| 12 | Medium risk; reliability lens; approved and acknowledged (lineage `review-0115893e19e37a99`); commit `b9c0d8d`. Non-blocking, for slice 15: an HTTP test that end-session returns the named session when a later one exists, and that a mismatch is 409 | #51 (merged) |
| 13 | Medium risk; reliability lens; approved and acknowledged (lineage `review-0a098ea5b125693d`); commit `bbfc562`. Non-blocking, fixed in slice 14: FlowRun commands are not atomic (a `CampaignLimitReached` while starting the next scene leaves a half-advanced FlowRun); a session change mid-scene leaves the FlowRun stuck; the repository round trip and a unit test compare only part of the Campaign | #52 (merged) |

## Next step

Slice 14 (review fixes, T9b) on `feat/play-flow-run-14-flow-run-guidance`.