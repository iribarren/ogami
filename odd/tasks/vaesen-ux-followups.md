# Feature: vaesen-ux-followups

- **Locator:** `odd/tasks/vaesen-ux-followups.md` · Engram topic `odd/vaesen-ux-followups/tasks`
- **Issue:** [#300](https://github.com/iribarren/ogami/issues/300) · **Branch:** `feat/vaesen-ux-followups-2-studio` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-ironsworn` (PRs #342–#352)

## Objective

Roadmap backlog B7m. Fix the UX findings logged while authoring and playing Lanterns of the Mire in [`preset-vaesen`](preset-vaesen.md#studio-findings), in Play's guided steps and in Studio authoring. None blocks play.

Out of scope: S15 (tall step cards), which goes to a follow-up issue with a roadmap line at closing.

## Problem and why

- Play: a resolved `condition` step leaves no trace in the guided area, a pool shows its size only after the roll, two prompts promise something the UI does not do, and NPCs are retyped into each Scene's cast.
- Studio: authoring a 23-field sheet took about 450 browser actions, and several length and shape errors appear only on Save.

## Decisions

| Finding | Decision | By |
|---|---|---|
| P13 | Scene start and ADR 0018 stay unchanged. The cast editor offers the previous Scene's NPCs for one-click adding | User, 2026-11-02 |
| S26 | Both Duplicate (prefilled copy, as Checks) and bulk paste (one name per line with shared settings, as oracle Paste entries) | User, 2026-11-02 |
| S15, S16 | S16 only: the per-band effects open by default or get an expand all. S15 goes to a follow-up issue | User, 2026-11-02 |
| P9 | Show the outcome in the guided area, reusing the `flowOutcome` journal entry rather than adding a FlowRun view field, unless that entry cannot be told apart | Parent |
| P3 | The server evaluates the pool size per Character; the client does not duplicate the formula engine | Parent |
| P7 | Reword the preset prompt; no post-roll text field | Parent |
| S14 | Raise the condition band label cap from 80 to 100 to match checks, backwards compatible, plus a live counter | Parent |
| S26 paste | Client side, no new endpoint: pasted fields go one by one through the existing `POST /sheet/fields`, each with the version the last one returned. A failure stops there and says what was added; the lines not added stay in the dialog | Parent |
| S16 | Expand all / Collapse all on a check set's per-band effects, collapsed by default: open by default would make the tall cards of S15 taller | Parent |

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/vaesen-ux-followups-1-play` | `feat(play): vaesen-ux-followups 1/2 guided steps` | T1–T5 | ~1,100: this doc and roadmap ~100; P3 backend ~150, generated OpenAPI and TS ~300, frontend ~60; P9 ~120; P6 ~10; P7 ~10; P13 ~300 |
| 2 | `feat/vaesen-ux-followups-2-studio` | `feat(studio): vaesen-ux-followups 2/2 authoring polish` | T6–T14 | ~1,000: S26 ~400; S1, S14 ~130; S11 ~60; S16 ~80; S22, S23 ~50; S8, S17, S21, P17 ~50; closing ~100 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | P3: a pool check step shows the evaluated pool size per Character before the roll; API test; `make api` | delegated writer (slice 1) | [x] | `3db02868` |
| T2 | 1 | P9: a resolved `condition` step's outcome shows in the guided step area, including when it ends the phase | delegated writer (slice 1) | [x] | `a4aab0e3` |
| T3 | 1 | P6: the oracle question field no longer shows a placeholder that is not used when blank | delegated writer (slice 1) | [x] | `2a6d546f` |
| T4 | 1 | P7: reword the table step prompt that promises a text field | delegated writer (slice 1) | [x] | `c28b2f14` |
| T5 | 1 | P13: the cast editor offers the previous Scene's NPCs for one-click adding | delegated writer (slice 1) | [x] | `df706859` |
| T5b | 2 | Slice 1 review findings (see Reviews) | delegated writer (slice 2) | [x] | — |
| T6 | 2 | S26: Duplicate a sheet field | delegated writer (slice 2) | [x] | — |
| T7 | 2 | S26: bulk paste sheet fields | delegated writer (slice 2) | [x] | — |
| T8 | 2 | S1, S14: condition band cap 100 in domain, translator and contract; live counter on check and condition band labels | delegated writer (slice 2) | [ ] | — |
| T9 | 2 | S11: reorder likelihood oracle levels; test that the backend keeps the order | delegated writer (slice 2) | [x] | — |
| T10 | 2 | S16: a check set's per-band effects easier to reach | delegated writer (slice 2) | [x] | — |
| T11 | 2 | S22, S23: a new phase's key follows its name; a hint that a phase needs a Scene Type | delegated writer (slice 2) | [x] | — |
| T12 | 2 | S8: push cost field select in sheet order | delegated writer (slice 2) | [x] | — |
| T13 | 2 | S17, S21, P17: `sheet` effect hint in Setup; Skip option hint before adding; a new `condition` step starts with a tracker and a second band | delegated writer (slice 2) | [x] | — |
| T14 | 2 | Closing: S15 follow-up issue and roadmap line; roadmap B7m done; this doc closed | parent (closing doc commit) | [ ] | — |

## Progress

- 2026-11-02: explored all findings against `main`; all still present except S21, partly fixed (a live guide exists once the step is added). Slice plan written.
- 2026-11-02: T1 done: `Check::poolSize()` reuses the roll's `countOf` and `CheckPool::sizeFor`, so a size of zero or below shows 1 die like the roll and an unevaluable pool shows null. `CheckView.poolSizes` by Character id; FlowRun view `characters` entries are `{id, name, poolSize}`. A check set shows no size (the pool depends on the check picked). `RollCheckDialog` left alone: Campaign characters carry no evaluated pool, so showing it there needs a new API field. RED: the form test failed before the CheckForm change; the backend unit tests were written with the code and passed first run.
- 2026-11-02: T2 done, frontend-only: `GuidedBody` shows the newest journal entry when it is a `flowOutcome`, above the step, reusing the journal's `FlowOutcomeView`. A `flowOutcome` is the Flow's own note whatever produced it (a condition step, a band's effects, a skip, a scene end), so the guided area names it "Flow outcome" like the journal and does not claim a condition step caused it. No API field added. RED: 3 of the 5 new GuidedPanel tests failed before the change.
- 2026-11-02: T3 done: the oracle question input has no placeholder; label unchanged. RED: the new no-placeholder test failed first.
- 2026-11-02: T4 done: the table prompt now says "then decide how it fits the mystery so far". Decision changed: no version bump, because a preset file carries no version (the release version is set when `make presets` publishes it) and the precedent commit 346b0ccc bumped nothing; no test pins the wording, so there is no meaningful RED. `PresetsTest` and the Lanterns flow test pass.
- 2026-11-02: T5 done, frontend-only: every Scene in the campaign view already carries its cast, so no endpoint was added. `previousSceneCast` takes the nearest earlier non-hook Scene of the session, or the last of an earlier session for a session's first Scene (a hook Scene has no cast of its own). The cast editor lists those NPCs that are not in the cast and still exist in the campaign, closed ones marked; one click sends the whole cast through the existing mutation. Scene start and ADR 0018 unchanged. RED: the two positive component tests failed before the change.
- 2026-11-02: T5b done: the pool size label adds "(before the extra dice)" once a non-zero modifier is typed (the size is the server's, so the form does not clamp it itself); the two Play tests wait for the journal entry before asserting absence; the empty cast assertion is in `waitFor`; the API test rolls and asserts the recorded `4d6`. No RED observed: the modifier test was written with the change; the other three are test-only hardening and passed first run.
- 2026-11-02: T6 done, frontend-only: a Duplicate row action on each readable sheet field opens the field dialog as a new, unsaved field (`duplicateField`: key `{key}-copy[-n]`, label `{label} (copy)`, both cut to the publisher's 64 and 100 characters); nothing is sent until Save. No RED observed: tests were written with the code and passed first run.
- 2026-11-02: T7 done, frontend-only: `Paste fields` dialog in the sheet tab (`pasteFields.ts` parser, `PasteFieldsDialog`). One name per line is a label, the key is its slug (cut to 64); shared type (number, text, list), group and a number's bounds; per-line refusal of a keyless name, a name over 100, a key of the sheet or an earlier line, before anything is sent. Fields go one by one through `useSaveSheetField` with the version each answer returned; a failure stops the run, says how many were added and keeps the lines not added; a 409 offers the reload. No RED observed: tests were written with the code and passed once the fixtures were right.
- 2026-11-02: T9 done: ArrowUp/ArrowDown buttons on each likelihood level (`moveLevel` in `likelihoodDraft.ts`; first row no up, last no down, disabled as in Checks bands). The backend already kept the order but no test pinned it: added `aSavedLikelihoodOracleKeepsItsLevelsInTheOrderTheAuthorGave`. No RED observed: the tests were written with the code and passed first run.
- 2026-11-02: T10 done, frontend-only: Expand all / Collapse all above the band rows of a check step's Outcomes (bands and special results); each row follows the newest press once and is then the author's again. Rows with a next or effects still start open, empty ones collapsed; S15 untouched. No RED observed: written with the code, passed once the fixture's open rows were accounted for.
- 2026-11-02: T11 done, frontend-only: `PhaseDraft.follow` with `editPhaseName`/`editPhaseKey` (the `slugOf` helper the other Studio forms use). A new phase follows from the start; a phase with no Scene Type shows a hint that it needs one before the Flow can be saved. Which stored phases follow was narrowed in the correction below. No RED observed: tests written with the code.
- 2026-11-02: T12 done, frontend-only: the checks read model gives conditions fields apart from the others with no position, so `pushContext` takes the key order of the sheet (already read by the check editor) and sorts the cost's sheet fields by it; unlisted ones follow. No API change. No RED observed: tests written with the code.
- 2026-11-02: T13 done, frontend-only: S17 a hint (`SETUP_NO_SHEET`, ADR 0042) under the effects of a Setup step or choice option that is offered no sheet kind; S21 the Add step menu says on `choice` that a suggested one needs a Skip option (the item keeps its accessible name); P17 `newStep('condition', steps, trackers)` preselects the draft's first tracker and adds a band besides Otherwise, a stored condition step with one band opens as stored (tested). No RED observed: tests written with the code.
- 2026-11-02: T11 correction: a stored phase follows its name only when it is the new-flow placeholder (`PLACEHOLDER_PHASE`, key `main`, name `Main`, shared with `newFlowToItem`); any other stored key, even one equal to its name's slug, is never renamed. RED observed: the `setup`/`Setup` test failed against the first version; the page test I had changed is back to its original expectation.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 | `review-293f4e9ed48f29c1` | Approved, acknowledged; 631 lines | Fixed in slice 2 (T5b): (W) `CheckForm.tsx:142-146` pool size label ignores a situational modifier; (W) `GuidedPanel.test.tsx:104-117` outcome-absent tests can pass before the journal loads; (S) `SceneCast.test.tsx:132` empty-list assertion needs `waitFor`; (S) `CampaignFlowChecksApiTest.php:106-107` should roll and assert the recorded pool size | [#354](https://github.com/iribarren/ogami/pull/354) |

## Next step

Slice 2: T5b–T13 on `feat/vaesen-ux-followups-2-studio`, then T14 closes the feature on that PR.
