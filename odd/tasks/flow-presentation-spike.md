# Feature: flow-presentation-spike

- **Locator:** `odd/tasks/flow-presentation-spike.md` · Engram topic `odd/flow-presentation-spike/tasks`
- **Issue:** #33 · **PR:** #34 · **Branch:** `feat/flow-presentation-spike-1-prototypes` (from `main` `00167cf`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-campaign-journal` (M1 complete)

## Objective

Settle the vision open question "How is the flow presented in Play (step-by-step wizard, journal with prompts, a mix)?" before feature 7 `play-flow-run` builds the FlowRun UI.

## Problem and why

Play is driven by a `NarrativeFlow`, and the vision calls its presentation "key". Building `play-flow-run` without a chosen presentation risks a UI rewrite. The vision prescribes the method: prototype two variants in Storybook, play them, pick one, record an ADR.

## Scope

- **In:** one static mock session (a short scene with every step type `play-flow-run` will need: prompt, oracle question, roll, choice, journal entry; one branch on a result); a **step-by-step wizard** prototype and a **journal with inline prompts** prototype, both as Storybook stories driven by the same mock data; playing the session in each; ADR with the choice and rationale; vision open question marked resolved; roadmap prompt for feature 7 points to the ADR; roadmap feature 6b `flow-model-brainstorm` with its bootstrap prompt (added on user request after the play-through).
- **Out:** backend, API, routes, real dice or oracle calls, persistence, production components. Prototypes live only in Storybook and are not imported by the app.

## Constraints

- No new libraries ([ADR 0004](../../docs/adr/0004-react-vite-spa-ui-toolkit.md)); reuse `shared/ui` and the Play journal/dice views where it helps.
- Static, scripted results only: the mock session is deterministic.
- Glossary terms: `NarrativeFlow`, `Flow step`, `FlowRun`, `JournalEntry`, `Scene`, `Thread`.
- `make frontend-qa` (ESLint, Prettier, tsc) and `make frontend-test` stay green.

## Slice plan

One slice (`feat/flow-presentation-spike-1-prototypes`, PR `feat(play): flow-presentation-spike 1/1 prototypes`). Forecast ~900–1,200 changed lines: T1 ~250, T2 ~350, T3 ~350, T4 ~150, T5 ~80. No generated files. T1–T3 landed at 1,369 lines (11 files) including tests and stories.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Mock session data + pure step-through logic (`advance`, branch on scripted result) shared by both prototypes; Vitest | delegated writer (multi-file, T1–T3 together) | [x] | `a948f36` |
| T2 | Wizard prototype: one step per screen, step progress, previous results summary, next step visible; Storybook story playing the mock session | delegated writer | [x] | `4243a19` |
| T3 | Journal-with-inline-prompts prototype: journal stream with the current step as an inline prompt card at the end, results recorded as entries; Storybook story | delegated writer | [x] | `c33e07d` |
| T4 | Play the mock session in both (Storybook in the browser), record findings; user picks; ADR 0016, vision open question resolved, roadmap feature 7 links the ADR | inline (docs) | [x] | `9641013` |
| T5 | Roadmap feature 6b `flow-model-brainstorm` (row + bootstrap prompt covering guided flows, scene types, generic vs game-specific content); vision open question for the flow model | inline (docs) | [x] | `45d0e28` |

## Acceptance criteria

- Both stories play the same mock session end to end, including the branch, with no backend.
- Each prototype always shows the next step.
- The ADR names the chosen presentation, the rejected one, and the reasons from the played sessions.

## Checks

- Test-first: T1 logic has a runnable deterministic Vitest (RED → GREEN). T2/T3 are throwaway Storybook prototypes: no meaningful RED; checked by `make frontend-qa`, `make storybook-build` and a played session.
- T4 is passive documentation: structural readback.

## Progress

- Exploration done: vision open question, roadmap feature 6/7 prompts, glossary, Storybook setup, journal fixtures.
- T1: `frontend/src/play/flow-prototypes/` mock session `sunkenGateSession` (every step type, oracle and choice branches) + pure `flowRun` (`startRun`, `advance`, `addNote`, `upcomingSteps`, `progress`, `replay`); Vitest RED 13 failed → GREEN 13 passed; `make frontend-qa` green.
- T2: `Wizard` prototype + shared `StepForm`/`UpcomingSteps`; stories `Play/Flow prototypes/Wizard` (FullSession, FullSessionGuarded, MidSession, Finished); smoke Vitest plays the scene to the end (15 passed); `make frontend-qa` green.
- T3: `JournalWithPrompts` prototype (journal stream via `JournalEntryView`, inline prompt card with next step, free notes between steps); stories `Play/Flow prototypes/Journal with prompts` (same four); smoke Vitest plays the guarded branch with a free note; `make frontend-qa` green, `make frontend-test` 116 passed, `make storybook-build` succeeded.
- Review of T1–T3 (medium risk, 11 files, 1,369 lines): consent **declined** by the user for this candidate; verification of record is the writer's `make frontend-qa`, `make frontend-test` (116 passed), `make storybook-build`, plus a parent re-run of `make frontend-test`.
- T4: both prototypes played end to end on the guarded branch (oracle "Yes" → describe the guard → climb → roll → journal entry), the journal one with a free note mid-scene. The Chrome extension was not connected, so the play-through ran as a Playwright script in the `playwright` container against Storybook (`http://node:6006/iframe.html?id=…`), with screenshots per step. Findings in ADR 0016. User choice: **Mix** (journal as the base, optional wizard-style focus mode). The user also raised guided flows for novice players (Studio-authored scene types, Session Zero, world changes between scenes), which go to feature 6b. ADR 0016, ADR index, vision (question resolved) and roadmap feature 7 prompt updated; structural readback done.
- T5: roadmap feature 6b `flow-model-brainstorm` (row, depends on 6; feature 7 now depends on 6b) with a bootstrap prompt covering 13 topics, including the user's addition: generic vs game-specific content (oracles, tables, scene types) and the game manager choosing which oracles and tables a flow makes available. Feature 7 prompt points to the 6b ADR. New vision open question for the flow model. Structural readback done.
- Review of the whole slice (T1–T5, medium risk, 15 files, 1,453 lines; lineage `review-a539fd9f1222b204`): consent **granted**, one lens (reliability), **approved and acknowledged**. Advisory findings, carried to `play-flow-run` and not fixed in the throwaway prototypes:
  - `JournalWithPrompts.tsx:90-99`: keys on `actions.length` remount the step form and the free-note form, so an unsent draft in one is lost when the other is submitted.
  - `flowRun.ts:194-202`: `remaining()` has no cycle guard, and a choice step with no options gives "about Infinity".
  - `flowRun.test.ts`: `exceptional_yes` / `exceptional_no` branch mapping untested.
- Delivered: issue #33, PR #34 (`feat(play): flow-presentation-spike 1/1 prototypes`, closes #33). Feature complete once #34 is merged; next feature is 6b `flow-model-brainstorm`.
