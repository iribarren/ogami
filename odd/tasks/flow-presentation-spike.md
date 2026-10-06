# Feature: flow-presentation-spike

- **Locator:** `odd/tasks/flow-presentation-spike.md` · Engram topic `odd/flow-presentation-spike/tasks`
- **Issue:** to be opened with the user at PR time · **Branch:** `feat/flow-presentation-spike-1-prototypes` (from `main` `00167cf`)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-campaign-journal` (M1 complete)

## Objective

Settle the vision open question "How is the flow presented in Play (step-by-step wizard, journal with prompts, a mix)?" before feature 7 `play-flow-run` builds the FlowRun UI.

## Problem and why

Play is driven by a `NarrativeFlow`, and the vision calls its presentation "key". Building `play-flow-run` without a chosen presentation risks a UI rewrite. The vision prescribes the method: prototype two variants in Storybook, play them, pick one, record an ADR.

## Scope

- **In:** one static mock session (a short scene with every step type `play-flow-run` will need: prompt, oracle question, roll, choice, journal entry; one branch on a result); a **step-by-step wizard** prototype and a **journal with inline prompts** prototype, both as Storybook stories driven by the same mock data; playing the session in each; ADR with the choice and rationale; vision open question marked resolved; roadmap prompt for feature 7 points to the ADR.
- **Out:** backend, API, routes, real dice or oracle calls, persistence, production components. Prototypes live only in Storybook and are not imported by the app.

## Constraints

- No new libraries ([ADR 0004](../../docs/adr/0004-react-vite-spa-ui-toolkit.md)); reuse `shared/ui` and the Play journal/dice views where it helps.
- Static, scripted results only: the mock session is deterministic.
- Glossary terms: `NarrativeFlow`, `Flow step`, `FlowRun`, `JournalEntry`, `Scene`, `Thread`.
- `make frontend-qa` (ESLint, Prettier, tsc) and `make frontend-test` stay green.

## Slice plan

One slice (`feat/flow-presentation-spike-1-prototypes`, PR `feat(play): flow-presentation-spike 1/1 prototypes`). Forecast ~900–1,200 changed lines: T1 ~250, T2 ~350, T3 ~350, T4 ~150. No generated files.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | Mock session data + pure step-through logic (`advance`, branch on scripted result) shared by both prototypes; Vitest | delegated writer (multi-file, T1–T3 together) | [x] | this commit |
| T2 | Wizard prototype: one step per screen, step progress, previous results summary, next step visible; Storybook story playing the mock session | delegated writer | [ ] | |
| T3 | Journal-with-inline-prompts prototype: journal stream with the current step as an inline prompt card at the end, results recorded as entries; Storybook story | delegated writer | [ ] | |
| T4 | Play the mock session in both (Storybook in the browser), record findings; user picks; ADR 0016, vision open question resolved, roadmap feature 7 links the ADR | inline (docs) | [ ] | |

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
