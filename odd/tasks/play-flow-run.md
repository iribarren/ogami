# Feature: play-flow-run

- **Locator:** `odd/tasks/play-flow-run.md` · Engram topic `odd/play-flow-run/tasks`
- **Issue:** #39 · **Current branch:** `feat/play-flow-run-23-focus-mode` (from `main` `64f00a6`)
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

- **State:** `status: active | paused | completed`, position (phase index, stage, scene number, Scene Type, part, step key), per-scene switch count, the forced next Scene Type, `phaseEnding`, latest answers per step key, and `history` (`skip`, `trackerEdit`, `sceneTypeSwitch`, `sceneTypeSwitchIgnored`, `paused`, `resumed`, `sceneAbandoned`, `phaseEnded`, `completed`, each with `at`).
- **Order:**
  - Session start → `sessionOpening` → (first time in a phase) `phaseOpening` → scene pick.
  - Scene: `sceneOpening` → `setup` → `play` steps → open play until "End scene" → `closing` → `sceneClosing`.
  - After a scene: if the phase is ending → `phaseClosing` → next phase (its `phaseOpening`), no world turn; else `worldTurn` → scene pick.
  - Each non-empty hook list runs in a hook Scene; an empty one creates no Scene. After the last phase's `phaseClosing` the FlowRun is `completed` and play continues unguided.
- **Scene pick** (a mandatory step): a forced `nextScene` offers only that card. Otherwise `sequence` offers "Next: …", `player` one card per type, `oracle` rolls the table (recorded as an `oracle-table` entry, the new scene's first entry). A selection with one Scene Type picks it automatically. Loop phases also offer "Move on" (ends the phase) and, between scenes, "End session".
- **Phase modes:** `once` + `sequence` plays each listed type once; `once` + `player` / `oracle` plays one scene; `loop` repeats until `endPhase` or "Move on" (a `sequence` loop cycles). A forced `nextScene` may name any release Scene Type and does not advance a sequence.
- **Steps:** suggested steps can be skipped (history only). A skipped `choice` follows its `skip` option with only that option's effects (its label is `{answer}`; no answer is recorded); any other skipped step goes to its `next` with no effects. A mandatory step has no Skip. Step `effects` apply when the step completes, before its branch's effects. Without a branch target the next step in the list runs; `end` ends the part.
- **Results:** prompt answers are `note` entries; `oracle` → `likelihood`; `table` → `oracle-table`; `roll` → `roll`; `choice` → new `choice` kind `{question, optionKey, label}`. A step's entry stores a snapshot `flowStep {key, title, prompt}` with placeholders rendered. `condition` writes no entry and advances on its own. `{answer}` is the step's answer text (prompt text, answer label, entry text, roll total, option label).
- **Effects:** `tracker` (`add`/`set` a literal or `{tracker: key}` read when applied, clamped; no `trackerEdit` history); `nextScene` forces the next pick (last wins); `switchSceneType` changes the current Scene's type in place (entries stay, position jumps to the new type's `setup`, `sceneOpening` does not re-run, history `sceneTypeSwitch`; the remaining effects still apply, the target is not followed; a switch to the current type restarts its setup; at most one per scene through effects, a later one is ignored with history `sceneTypeSwitchIgnored {scene, sceneType}`; hand switches do not count); `endPhase` sets `phaseEnding` (first reason wins; the scene finishes, then `phaseClosing`); `sceneTitle` renames the current Scene (rendered, cut to 100 characters, ignored when empty). Table entry effects apply when a flow step rolls the entry. A rolled entry with `sceneType` is offered as a manual switch.
- **Guidance:** pause → `paused` (free play; notes, oracles, rolls, manual scenes and switches). Resume continues at the stored position. If the player started a new scene by hand while paused mid-scene, the paused scene is abandoned (history) and resume continues between scenes.
- **Concurrency:** FlowRun commands carry the current step key (or stage); a mismatch fails with 409.

### FlowRun model (T9a, T9b)

- `Campaign\FlowRun\FlowRun`: an entity inside the Campaign, stored with it as JSONB (`flow_run`, slice 15; every change replaces it with a changed copy so Doctrine sees it). `FlowRunContext` passes the pinned release, the Flow, the time and the current session and scene.
- `status` active | paused | completed; `stage` scenePick | scene | hook; `ScenePart` sceneOpening → setup → play → open → closing → sceneClosing.
- Commands on the Campaign: `pickSceneType`, `pickSceneTypeByOracle`, `completeFlowStep(stepKey, StepResult)`, `skipFlowStep`, `endFlowScene(sceneNumber)`, `moveOn`, `pauseGuidance`, `resumeGuidance`. Each step/pick command names the step, scene or stage it expects, and Move on the phase key (`FlowRunPositionMismatch`). Each runs on a copy of the Campaign and is kept only when it succeeds (all or nothing).
- Guidance (T9b): paused, every step/pick command and Move on fail with `FlowRunNotActive`. A guided scene that is no longer the current scene is abandoned (history `sceneAbandoned {session, scene}`) when guidance resumes or when a session starts (`startSession` with the release: the session ended mid-scene, or a new one started); the FlowRun goes on as after a scene (the scene counts as played), at the scene pick. Until then an active FlowRun whose scene was replaced by hand refuses commands (pause and resume to go on).
- Hand actions (T9b): `setTrackerValue(tracker, value, at)` records `trackerEdit {tracker, from, to}` and `switchSceneType(type, release, at)` records `sceneTypeSwitch {scene, from, to}` whenever a FlowRun exists; while guidance is active, a switched guided scene goes on at the new type's setup (paused or completed, the position stays; slice 15).
- Read model (T9b): `Campaign::flowRunView(release)` → `FlowRunView {status, waitsForSession, progress: FlowRunProgress {act, phase, sceneType, part, stepNumber, stepCount} (condition steps not counted), step: FlowStepView {kind, step}, pick: ScenePickView {rule, cards, table, forced}, canMoveOn, next}`. The next step is named on the default path (condition steps passed, branches ignored): `<part>: <title>`, `Open play`, `Next: <type>` (sequence), `Next scene: <type>` (forced or only type), `Next scene: choose a scene type`, `Next scene: roll on <table>`, `<phase> complete → <next phase>`, `Flow complete`.
- Control flow (T11): a finished step takes its Outcome (oracle branch, else `yes`/`no` for an exceptional answer; table branch by the key of the entry rolled on the step's own table, else `otherwise`; band of the roll total or the condition's Tracker: the first band whose `upTo`, a literal or a Tracker's current value, is ≥ the value, the band without `upTo` catches the rest; choice option). Effect order: the step's, the rolled table entries' (result order, nested tables included; not the oracle scene pick), the Outcome's; then the target (Outcome `next`, step `next`, following step). `FlowRunContext` reads and changes Tracker values, renames and switches the current Scene. `Placeholders` renders `{tracker:key}`, `{step:key}` ("—" unanswered), `{answer}`; a placeholder outside the contract throws `LogicException`. `FlowStepView` carries the rendered title, prompt and tip (also the journal `flowStep` snapshot and the next-step label).
- Hooks (T12): stage `hook` with the `Hook` and its hook Scene; on the way to the scene pick a started session runs `sessionOpening` (`sessionOpeningDue`), a phase entered for the first time `phaseOpening` (`phaseOpened`), an ending phase `phaseClosing` instead of the pick (Move on at the pick too); after a scene `worldTurn` or, phase finished, `phaseClosing`. Same step mechanics; the hook Scene starts at the first step that waits (conditions passing the whole hook leave no Scene); `switchSceneType` is ignored (`sceneTypeSwitchIgnored`); `endFlowScene` in a hook is a position mismatch; Move on during `phaseClosing` → `MoveOnNotAllowed`. A hook due while no session is under way waits and runs before that session's opening. Abandoned hook Scenes go on as after the hook. The forced Scene Type carries into the next phase. Progress `part` is the hook; skips record the hook as `part`. Rows stored before T12 read as phase opened, no session opening due.
- Answer text: prompt text; table entry texts joined with " › "; oracle answer label; choice option label.
- **Move on (user decision, 2026-10-10):** available at any time in a loop phase while guided, as a player-triggered `endPhase`: during a scene it ends the phase after the closing parts; at the scene pick it ends the phase at once. Single-type auto-pick stays (ADR 0018 decisions 8 and 11). T9a allows it at the pick only; T9b extends it (history reason `moveOn` either way; the first of an `endPhase` effect and Move on names the reason).

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
| 1 | `feat/play-flow-run-1-contract` | `feat(studio): play-flow-run 1/21 contract` | T1 | 1,423 (actual) |
| 2 | `feat/play-flow-run-2-contract-catalog` | `feat(studio): play-flow-run 2/21 contract-catalog` | T2a | 1,729 (actual) |
| 3 | `feat/play-flow-run-3-contract-steps` | `feat(studio): play-flow-run 3/21 contract-steps` | T2b | 1,542 (actual) |
| 4 | `feat/play-flow-run-4-contract-flows` | `feat(studio): play-flow-run 4/21 contract-flows` | T3 | 1,302 (actual) |
| 5 | `feat/play-flow-run-5-contract-warnings` | `feat(studio): play-flow-run 5/21 contract-warnings` | T4 | 532 (actual) |
| 6 | `feat/play-flow-run-6-example-fixtures` | `test(studio): play-flow-run 6/21 example-fixtures` | T5 | 1,191 (actual) |
| 7 | `feat/play-flow-run-7-acl-catalog` | `feat(play): play-flow-run 7/21 acl-catalog` | T5b, T6a | 1,668 (actual, accepted by the user) |
| 8 | `feat/play-flow-run-8-acl-flows` | `feat(play): play-flow-run 8/21 acl-flows` | T6b | 709 (actual) |
| 9 | `feat/play-flow-run-9-campaign-trackers` | `feat(play): play-flow-run 9/21 campaign-trackers` | T7 | ~1,810 (actual, accepted by the user) |
| 10 | `feat/play-flow-run-10-campaign-scenes` | `feat(play): play-flow-run 10/21 campaign-scenes` | T8a | ~2,100 (actual, accepted by the user) |
| 11 | `feat/play-flow-run-11-campaign-flow` | `feat(play): play-flow-run 11/21 campaign-flow` | T8b | 1,956 (actual; reviewed as two candidates) |
| 12 | `fix/play-flow-run-12-review-fixes` | `fix(play): play-flow-run 12/21 review-fixes` | — | 237 (actual) |
| 13 | `feat/play-flow-run-13-flow-run` | `feat(play): play-flow-run 13/21 flow-run` | T9a | 1,930 (actual) |
| 14 | `feat/play-flow-run-14-flow-run-guidance` | `feat(play): play-flow-run 14/21 flow-run-guidance` | T9b | 997 (actual) |
| 15 | `feat/play-flow-run-15-flow-run-persistence` | `feat(play): play-flow-run 15/21 flow-run-persistence` | T10a | ~1,300 |
| 16 | `feat/play-flow-run-16-flow-run-commands` | `feat(play): play-flow-run 16/22 flow-run-commands` | T10b | 1,523 (actual) |
| 17 | `feat/play-flow-run-17-flow-run-view` | `feat(play): play-flow-run 17/22 flow-run-view` | T10b2, T10c1 | 2,063 (actual, accepted by the user; reviewed as two candidates) |
| 18 | `feat/play-flow-run-18-flow-run-http` | `feat(play): play-flow-run 18/22 flow-run-http` | T10c2 | ~2,050 (accepted by the user, 2026-10-10; the generated OpenAPI spec and TS types are committed and reviewed as a separate candidate) |
| 19 | `feat/play-flow-run-19-control-flow` | `feat(play): play-flow-run 19/23 control-flow` | T11 | ~1,500 |
| 20 | `feat/play-flow-run-20-hooks` | `feat(play): play-flow-run 20/23 hooks` | T12 | 1,343 (actual) |
| 21 | `feat/play-flow-run-21-guided-journal` | `feat(play): play-flow-run 21/23 guided-journal` | T13, T14 | ~1,650 |
| 22 | `feat/play-flow-run-22-trackers-oracles` | `feat(play): play-flow-run 22/23 trackers-oracles` | T15 | ~1,000 |
| 23 | `feat/play-flow-run-23-focus-mode` | `feat(play): play-flow-run 23/23 focus-mode` | T13b, T16–T17 | ~1,000 authored + ~1,310 deleted (the prototype deletion is its own commit, outside the review candidate) |

Re-forecasts split slices whenever the parent's forecast passed the limit: slice 1 (~5,950 lines) into slices 1–4 (user, 2026-10-09), then shapes (2–3), the ACL (7–8), campaign state (9–11), the FlowRun (13–14, slice 12 only review fixes; user, 2026-10-10) and T10 (15–18). Before each later slice's writer starts, the parent re-forecasts it and splits it if it will pass the limit; titles then use the new total. From slice 10 on, the user set the per-slice planning limit to ~2,000 lines including generated files and tests (2026-10-10); slice 11 exceeded the native reviewer's context budget, so slices aim at ~1,600–1,700 lines. Local tags, never pushed: `wip/play-flow-run-contract-full` (`66e1586`, ported in slices 2–5), `wip/play-flow-run-t9a`, `wip/play-flow-run-flowrun-full`, `wip/play-flow-run-t10b-remainder` (four handlers held back at slice 16's size stop, moved to slice 17). Re-forecast before slice 19 (T11–T12 ~3,000 lines) split control flow (19: effects, bands, branches, placeholders) from hooks (20: hook Scenes, guided End session, examples 2 and 3); 23 slices. Re-forecast before slice 21: deleting `flow-prototypes/` is ~1,310 deleted lines, and its `JournalWithPrompts`/`StepForm` and `Wizard` are the references for T14 and T16, so the deletion and its ADR 0016 note (T13b) move to slice 23, as a commit of its own before the reviewed range.

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
| T9b | 14 | Guidance pause/resume and scene abandonment; history for hand tracker edits and Scene Type switches; "Move on" any time in a loop phase (user decision); UI read model (position, current step, pick offer, next step named) | delegated writer (slice 14, 2+ non-trivial files) | [x] | `d04ee68` |
| T10a | 15 | FlowRun persistence (JSONB on the campaign, migration, repository contract incl. FlowRun); journal results: new `choice` content kind and a `flowStep {key, title, prompt}` snapshot on entries recorded by steps | delegated writer (slice 15, 2+ non-trivial files) | [x] | `3494d1a` |
| T10b | 16 | Application, part 1: FlowRun commands through the buses (complete step with server-side roll/table/likelihood and journal entry, pick, pause, resume), `StartSessionHandler` passes the release, FlowRun read model in `GetCampaign` | delegated writer (slice 16, 2+ non-trivial files) | [x] | `9666d16` |
| T10b2 | 17 | Application, part 2: skip, oracle pick, end scene, Move on (from `wip/play-flow-run-t10b-remainder`) | delegated writer (slice 17, 2+ non-trivial files) | [x] | `83ed81b` |
| T10c1 | 17 | Campaign view `flowRun` in the API, `JournalEntryResponse` maps `choice` and `flowStep`, the slice 12 end-session HTTP test; OpenAPI and typed client | delegated writer (slice 17, 2+ non-trivial files) | [x] | `f0fc90e`, `048a257` |
| T10c2 | 18 | HTTP endpoints for the FlowRun commands, API tests (404/409/422 per domain error, 409 on a concurrent save), Behat | delegated writer (slice 18, 2+ non-trivial files) | [x] | `74fb0e6`, `76d0a92` |
| T11 | 19 | Branches, `condition`, effects (incl. table entry effects), placeholders, switch limit | delegated writer (slice 19, 2+ non-trivial files) | [x] | `3340e66` |
| T12 | 20 | Hooks as hook Scenes, End session while guided, Move on; domain tests playing examples 2 and 3 | delegated writer (slice 20, 2+ non-trivial files) | [x] | `48bd152`, `3d6df4a` |
| T13 | 21 | Flow choice in campaign creation | delegated writer (slice 21, 2+ non-trivial files) | [x] | `28ed8eb` |
| T13b | 23 | Delete `flow-prototypes/` (after T14 and T16 ported what they need); ADR 0016 note | delegated writer (slice 23, deletion of a directory) | [x] | |
| T14 | 21 | Guided journal: step cards per kind, scene-type cards, next step named, scene and hook headers, End scene / End session, pause/resume; e2e | delegated writer (slice 21, 2+ non-trivial files) | [x] | `a24b711`, `fca927c`, `24b869d` |
| T15 | 22 | Trackers panel (hint, levels, edit); oracle panel order (shortcuts › flow › More oracles); manual switch and offer from rolled entry. API adds: flow `oracles`/`trackers` on the flows summary, Scene Type `oracles` (shortcuts), `sceneType` on journal oracle-table steps (stored at roll time, legacy rows null) | delegated writer (slice 22, 2+ non-trivial files) | [x] | `302bf37`, `61374e6` |
| T16 | 23 | Focus mode (frontend only; `defaultView` already on the flows summary): per-campaign toggle (initialised from the active Flow's `defaultView`, kept in `localStorage`), progress `Act › Phase › Scene type › part · step n/m`, only the current step card and next step on screen, and a compact summary of the current scene's journal entries. **User decision 2026-10-11:** the summary lists the scene's results (journal entries) only; skipped steps leave no entry and are not shown, so no API change (ADR 0016: focus mode is a view of existing state) | delegated writer (slice 23, 2+ non-trivial files) | [x] | |
| T17 | 23 | Roadmap and glossary updates; review outcomes and PR links (doc-only commit after the last review) | | [ ] | |

## Acceptance criteria

- A v2 release with every part above validates in schema and domain; invalid cases fail both (structural) or only the domain (semantic); v1 releases still validate and publish.
- The five example fixtures validate, pass the ACL, and the heist fixture yields the decision-7 warning.
- A campaign with a flow plays phases, scenes, parts and hooks to `completed`; skips, tracker edits and switches are in the history; free play and the Free journal preset still work.
- The player always sees the next step named; focus mode and journal show the same FlowRun.

## Checks

- Backend: test first (RED → GREEN) with PHPUnit unit tests per rule; `make qa`, `make test`; `make api-check` when endpoints change.
- Frontend: Vitest per component, `make e2e` for the guided flow (slices 19–21).

## Progress

Per-task evidence (RED → GREEN counts, files, tests) is in each work-unit commit and its PR. Sizes are changed lines vs `main`.

- 2026-10-09: plan approved by the user; issue #39.
- T1: `v2.schema.json`, contract doc v2 section, `ReleaseSchemaVersion2Test` · `aece3b2` · slice 1: 1,131.
- T2a: `ReleaseContent` accepts v1 and v2 (envelope, trackers, fact slots, entry `key`, `chaos.tracker`) · `481f78d` · ~1,725, accepted by the user (~300 moved v1 code).
- T2b: Scene Types, steps, bands, effects; slice 2 review fixes · `edb9e4c` · 1,542.
- T3: flows, phases, cross-references, placeholders; slice 3 review suggestions · `2a93831` · 1,302.
- T4: decision-7 authoring warnings, `CheckGameSystemRelease`, console output · `eebddad` · 532.
- T5: the five examples as v2 fixtures, warnings pinned (heist warns) · `5bc580f` · 1,191.
- T5b: the warning follows branches; VtM uses the fixed-threshold masquerade · `387bb11` · 288.
- T6a: Play snapshot v2 catalog and translator · `f499539` · 1,380 (slice 7: 1,668, accepted by the user). Slice 7 review fix (forced scenes compared by Scene Type) · `eb62c16`.
- T6b: Play flows, phases, selection, hooks; the examples pass the ACL · `ef1c66d` · slice 8: 709. Slice 8 review fix (flow `default` fails closed when not a boolean) · `f3087c7`.
- T7: campaign trackers, `PUT /campaigns/{id}/trackers/{key}`, migration `Version20261009120000` · `efe389a` · slice 9: ~1,810 incl. ~400 generated, accepted by the user. Slice 9 review fix (missing tracker values read as starting values; step `mandatory` fails closed) · `fe2fa6e`.
- T8a: scene kind, Scene Type, hook Scenes, default titles, start and switch by hand · `d5d3939` · slice 10: ~2,140 incl. 317 generated, accepted by the user. Slice 10 review fix: the switch guards on kind `hook`; the reader rejects inconsistent kind/hook · `3432431`.
- T8b: `EndSession`, `flowKey` at creation (null plays freely; unknown → 404), campaign view `flowKey`, `flows`, `sceneTypes`, migration `Version20261010120000` · `738829b` · slice 11: ~1,960 incl. 367 generated. Slice 11 review fix: `EndSession` names its session; `Scene::reconstitute` rejects inconsistent kind/hook · `b9c0d8d`.
- Play decisions (slices 9–11): a malformed body is 400 on every Play endpoint (a tracker value is any integer, clamped); unknown release keys are 404; `tracker_values` is Doctrine `json`; scenes stored before kinds are scenes of play without a Scene Type; an ended session is not under way (`NoCurrentSession` / `NoCurrentScene`, 409); `flow_key` is a nullable column; Play value objects expose public readonly properties.
- Follow-up: the check that the contract doc example equals its fixture needs `docs/` inside the php container (it mounts only `./backend`); not done.
- T9a: FlowRun core, 24 tests (written after the code: no real RED) · `bbfc562` · ~1,900. Slice 13 review fix: FlowRun commands run on a copy of the campaign (all or nothing; RED 4 → GREEN) · `4d70d54`.
- T9b: pause/resume, scene abandonment, `trackerEdit` / `sceneTypeSwitch` history, Move on during a scene, `FlowRunView` (RED 12 → GREEN) · `d04ee68` · slice 14: 997. Slice 14 review fix: a hand switch moves only an active guided FlowRun; Move on names the phase key · `5d43b91`.
- T10a: `CampaignFlowRunType` (`flow_run` JSONB, fails closed), copy-on-write FlowRun, `ChoiceContent`, `FlowStepSnapshot` (`flow_step`), migration `Version20261010130000` (RED → GREEN) · `3494d1a` · slice 15: ~1,300. Slice 15 review fix: `Campaign::flowRun()` returns a copy; `startMissingFlowRun()`; pause/resume round trip · `6529cf2`.
- T10b: `CompleteFlowStep` (roll, table and likelihood server-side; fields of another kind → `InvalidStepResult`), `PickSceneType`, `PauseGuidance`, `ResumeGuidance`, `CampaignView::$flowRun` (RED 13 → GREEN) · `9666d16` · slice 16: 1,523. Slice 16 review fix: save the campaign before adding the entry (no orphan entry on a concurrent save) · `2b1e7ff`.
- T10b2: `SkipFlowStep`, `PickSceneTypeByOracle`, `EndFlowScene`, `MoveOn` from `wip/play-flow-run-t10b-remainder` (characterization, no RED) · `83ed81b` · 572.
- T10c1: `CampaignResponse::$flowRun`, flat `FlowStepResponse` (effects, bands, branches not exposed), `JournalEntryResponse` maps `choice` and `flowStep`; generated API and a Vitest case · `f0fc90e`, `048a257` · slice 17: 2,063, accepted by the user. Slice 17 review fix: skip and oracle-pick handler failure paths (characterization) · `5614aa3`.
- T10c2: `FlowRunController` (8 `POST /api/campaigns/{id}/flow-run/…` endpoints, 200 with the refreshed campaign; 400 malformed body, 415, 404 unknown campaign, 409 state conflicts, 422 invalid answers; an unreadable pinned release stays 500), `CampaignFlowRunCommandsApiTest` (106 cases), Behat `flow-run.feature` (RED 98 of 106 → GREEN) · `74fb0e6`, `76d0a92` · slice 18: ~2,050, accepted by the user.
- Slice 18 review fixes: `InvalidStepResult` says "an oracle step"; the `pick/roll` API test pins that the rolled table has one entry, so the real random number generator is safe; doc condensed for the Engram mirror (RED: 1 message failure → GREEN).
- T11: Outcomes, bands, Effects (order, switch limit, `sceneTypeSwitchIgnored` persisted), `Placeholders`, rendered `FlowStepView` and journal snapshot; `GuidedReleases` fork option `left` renames the scene; examples 2 and 3 replayed with effects. RED: 25 control-flow tests (20 failures, 1 error), persistence and handler rendering tests → GREEN; `Placeholders` tests are characterization.
- Slice 19 review fix: a command failing after its Effects changed a Tracker and the scene title (scene 200's closing step, then the auto-picked next scene does not fit the session) leaves the live campaign unchanged; characterization (commands already run on a copy), checked by a mutation that runs the command on the live campaign (1 failure).
- T12: hooks in hook Scenes (stage `hook`, `phaseOpened`, `sessionOpeningDue`, persisted with legacy defaults), `FlowRunHooksTest` (12), examples 2 and 3 to completed; guided `EndSession` at the pick only (`SessionCannotEndWhileGuided`, 409) after `sessionClosing`, `canEndSession` in the view and API; `EndSessionHandler` reads the pinned release. RED: examples, view and repository round trip (6 failures) after the domain change → GREEN; `FlowRunHooksTest`, the end-session handler and API tests were written after the code (characterization).
- Slice 20 review fixes: `EndSessionHandler` reads the pinned release only while the FlowRun is active (free play, paused and completed end the session without it; an unreadable release while active stays a 500; RED 1 → GREEN); a `switchSceneType` Effect on a condition step in a hook no longer starts a hook Scene, and the ignored switch is recorded with `scene` null when the hook has no Scene yet (`sceneTypeSwitchIgnored` details already allow null; reader uses `optionalInt`; RED → GREEN); characterization (written after the code) of pause during the session closing, End session while paused, resume and the next session.
- T13: `NewCampaignForm` "Flow" select (one option per Flow plus "Play freely", the release's `default` Flow preselected, description shown, reset on GameSystem change, no select without Flows; a GameSystem without Flows sends no `flowKey`, Play freely sends null); 5 Vitest cases (written with the code, no separate RED run).
- T14: `GuidedPanel` under the journal entries when `flowRun` is set (`flow/`: `StepCard` per kind, `ScenePickCard` per rule, progress, "Next:", End scene in open play, End session when `canEndSession`, Pause/Resume, API errors in `role="alert"`), `useFlowRunCommand` (one mutation for the 8 commands; updates the campaign, refetches the journal), journal hook headers, Scene Type in scene headings and "Flow step" captions; 26 Vitest cases, stories (written with the code, no separate RED run). Move on: `progress.phaseKey` added to the API (spec `frontend/src/shared/api/openapi.json`, `make api`); the panel shows Move on only when `canMoveOn` and sends that key (Vitest). Preset: `mythic-style` is now schema version 2 with the Flow `mythic` (default, from the `mythic-session` example; its chaos Tracker bound to the fate oracle, `scene-adjustments` table added, `random-event-focus` entries keyed `far-away` and `odd-sign`); `PresetsTest` pins it; `play-guided.spec.ts` plays it (e2e runs `make presets` through `e2e-seed`, no change needed).
- Slice 21 review fixes: a refused command (409) refetches the campaign so the panel shows the server's FlowRun; `StepCard` is keyed by scene, part and step so a loop's next scene starts with an empty answer (RED 2 → GREEN).
- T15 API: flow `oracles`/`trackers` and Scene Type `oracles` on the campaign view; `sceneType` stored on each `oracle-table` step at roll time (free-play roll and FlowRun table steps; the oracle scene pick records no entry) and exposed in the journal; legacy rows read null, a non-string value fails closed; `make api`. Tests written with the code (characterization, no separate RED run).
- T15 UI: `trackers/` panel (value, level, hint, − / + / typed value clamped, `PUT` answer replaces the Tracker, 409 refetches; the Flow's Trackers only while the FlowRun is active), `OraclePanel` order Scene shortcuts › Flow oracles › collapsed "More oracles" (flat when neither picks one), Scene Type select on the current scene and optional Scene Type when starting a scene (`useSwitchSceneType`, 409 refetches), "Switch to <Scene Type>" under a rolled entry in the current scene; 31 Vitest cases and Trackers stories (written with the code, no separate RED run). A hook Scene shows no switch.
- T13b: `frontend/src/play/flow-prototypes/` deleted (nothing outside it imported it); ADR 0016 consequence updated. Deletion only, no test (the prototypes' own tests went with them).
- Slice 22 review fixes: the oracle panel applies the Flow's order only while the FlowRun is active, as `visibleTrackers` does (RED 2 → GREEN, Vitest in `CampaignPage.test.tsx`); a FlowRun table step stores its rolled entry's non-null `sceneType` in the journal (through the buses; characterization, it passes against the existing code).
- T16: view toggle (`Journal view` / `Focus view`, only with a FlowRun; opens in the remembered view, else the Flow's `defaultView`; `localStorage` key per campaign, every access in try/catch) and `FocusPanel` (breadcrumb, progress bar, `StepCard`/`ScenePickCard`, next step, the same controls, and "So far" = the current scene's journal entries; the full Journal is hidden); `progress.ts` and `GuidedParts.tsx` hold what `GuidedPanel` and `FocusPanel` share; 14 Vitest cases and two stories (RED: 9 of 14 failed before the code, the other 5 hold for the existing journal view).

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
| 14 | Medium risk; reliability lens; approved and acknowledged (lineage `review-c90d115d04f78a18`); commits `4d70d54` (review fixes), `d04ee68` (T9b). Non-blocking, fixed in slice 15: a hand Scene Type switch moves the FlowRun while paused or completed; Move on on a replaced scene succeeds where other commands refuse, and has no position token | #53 (merged) |
| 15 | Medium risk; reliability lens; approved and acknowledged (lineage not recorded); commits `5d43b91` (review fixes), `3494d1a` (T10a). Non-blocking, fixed in slice 16: `Campaign::flowRun()` hands out the live FlowRun, whose public seams (`forceNextSceneType`, `endPhaseAfterScene`) change it outside a command; campaigns with a Flow key stored before T10a have no FlowRun; no resume → save → reload round trip. For slice 17: `JournalEntryResponse` maps `choice`; the slice 12 end-session HTTP test | #54 (merged) |
| 16 | Medium risk; reliability lens; approved and acknowledged (lineage `review-7a72bec78aa91f8c`); commits `6529cf2` (review fixes), `9666d16` (T10b). Non-blocking, fixed in slice 17: no through-the-buses test that a `CampaignModifiedConcurrently` on `CompleteFlowStep` leaves no orphan journal entry; no handler test for an oracle step bound to a Tracker (chaos from the Tracker, a sent `chaosFactor` refused) | #55 (merged) |
| 17 | Over the reviewer's context budget as one candidate; reviewed as two, both medium risk, reliability lens, approved and acknowledged: commits `2b1e7ff` (review fixes), `83ed81b` (T10b2), `f0fc90e` (T10c1) (lineage `review-e8d713e228ad2ce9`), and `048a257` (generated API; lineage `review-a23dfe267046c8b7`, no findings). Non-blocking, fixed in slice 18: no handler test that skipping a mandatory step is refused (`StepCannotBeSkipped`, campaign unchanged); no tests for `PickSceneTypeByOracle` failure paths (entry without `sceneType` → `SceneTypeNotOffered`, waiting for a session → `NoCurrentSession`, paused FlowRun) leaving the campaign unchanged; the `GuidedReleases` tests do not assert the fork skip target `left`'s effect. The writer touched `frontend/src/play/flow-prototypes/flowRun.ts` outside its surface (deleted in T13) | #56 (merged) |
| 18 | Reviewed as two candidates, both medium risk, reliability lens, approved and acknowledged: commits `5614aa3` (review fixes), `74fb0e6` (T10c2) (lineage `review-35a831c99f34060d`), and `76d0a92` (generated API; lineage `review-8574ffc3d0f3f546`, no findings). Non-blocking, fixed in slice 19: the `pick/roll` API happy path relies on the real random number generator (deterministic only because the release's table has one entry; pin or say so); the `InvalidStepResult` message reads "a oracle step"; assert the skipped fork option `left`'s effect once T11 runs effects; the Engram mirror was truncated at 50,000 bytes (doc condensed) | #57 (merged) |
| 19 | Medium risk; reliability lens; approved and acknowledged (lineage `review-bbf418b19b4a48d9`); commits `05889f3` (review fixes, doc condensed), `3340e66` (T11). Non-blocking, fixed in slice 20: the all-or-nothing test covers only a command refused before any Effect runs; no test shows that a failure after Effects changed the Campaign (Tracker values, scene title, Scene Type switch) leaves the live campaign unchanged | #58 (merged) |
| 20 | Medium risk; reliability lens; approved and acknowledged (lineage `review-e38b236a19a71b6e`); commits `4f21d2a` (review fix), `48bd152`, `3d6df4a` (T12). Non-blocking, fixed in slice 21: `EndSessionHandler` reads the pinned release for every campaign with a Flow, so an unreadable release makes End session 500 even with a paused or completed FlowRun; a `switchSceneType` Effect on a condition step in a hook starts a hook Scene although every condition step passes; no test for pausing during the session closing, ending the session while paused, then resuming | #59 (merged) |
| 21 | Medium risk; reliability lens; approved and acknowledged (lineage `review-d8c5dfb69a6c31d7`); commits `5303b3e` (review fixes), `28ed8eb` (T13), `a24b711`, `fca927c`, `24b869d` (T14). Non-blocking, fixed in slice 22: after a refused command (409, e.g. from another tab) the cached campaign is not refetched, so the panel shows a stale step and every retry is refused until a reload; `StepCard` is keyed only by `step.key`, so in a loop phase the same step in the next scene keeps the old typed answer | #60 (merged) |
| 22 | Medium risk; reliability lens; approved and acknowledged (lineage `review-cec90abcbd44a720`); commits `9cf986c` (slice 21 review fixes), `302bf37` (T15 API), `61374e6` (T15 UI). Non-blocking, fixed in slice 23: no test shows a FlowRun table step storing a non-null `sceneType` (only the free-play roll is covered); the oracle panel applies the Flow's order whatever the FlowRun's status, while the Trackers panel applies the Flow's selection only while guidance is active (make them consistent and pin it) | #61 (merged) |

## Next step

Slice 23 (final): T13b (delete `flow-prototypes/`, its own commit before the reviewed range), slice 22 review fixes, T16 (focus mode); review (`--base-ref` the T13b commit so the deletion is excluded), PR (`Closes #39`); then T17 (roadmap, glossary, review outcomes and PR links) as the parent's final doc-only commit after review and PR.
