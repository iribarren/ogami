# 0016. The flow is presented in the journal, with an optional focus mode

- **Status:** Accepted. Amended by [ADR 0017](0017-narrativeflow-model.md): each flow sets its default view (`focus` or `journal`)
- **Date:** 2026-10-07

## Context

The [vision](../vision.md#open-questions) left open how a `NarrativeFlow` is presented in Play: a step-by-step wizard, a journal with prompts, or a mix. Feature 7 `play-flow-run` builds the FlowRun UI, so the question must be settled first.

Feature `flow-presentation-spike` built two Storybook prototypes in `frontend/src/play/flow-prototypes/`. Both play the same static scene, "The Sunken Gate". It has every step type `play-flow-run` needs (prompt, oracle question, roll, choice, journal entry) and branches on an oracle answer and on a choice. Both prototypes were played end to end in Storybook on the guarded branch (oracle "Yes", then climb). Their smoke tests play the other paths: the wizard plays "No" then climb, and the journal plays "Yes" then swim. Together they cover every branch, though no single prototype was played on every branch.

| Variant | Worked | Did not work |
|---|---|---|
| Step-by-step wizard (one step per screen) | Very focused; clear progress ("Step 5 of 6") and a visible next step | Oracle and roll details shrink to a one-line "So far" list once the player moves on; no natural place for a free note mid-flow; feels like filling a form, not journaling; results live outside the journal until the scene ends |
| Journal with inline prompts | Every result is a real `JournalEntry` shown with the existing journal views; the whole story stays visible; free notes between steps feel natural | Long scenes push the step card far down; less focus; progress is only a small label |

The experienced solo player, the player the current plan serves, wants the journal as the record of play. Some moments still need focus: a long scene, or a player who wants only the next step on screen.

## Decision

Play presents a FlowRun as a **mix**:

- **The journal is the base.** The current flow step is a prompt card at the end of the scene's journal, and the player answers it in place.
- **Results are journal entries.** Every step result is recorded as a `JournalEntry` in the current scene. A FlowRun has no separate results view.
- **The next step is always visible** under the current step card, as in both prototypes.
- **Free notes stay possible** between flow steps.
- **An optional focus mode** shows only the current step, wizard-style, with step progress and a short summary of earlier results. The player can switch between the journal and focus mode at any time. Both views show the same FlowRun.
- **The current step card stays in view** as the journal grows, by scrolling to it or pinning it. The way to do this is chosen in `play-flow-run`.

## Consequences

- `play-flow-run` builds on the M1 journal (`Journal`, `JournalEntryView`) and does not need a second display model for flow results.
- Focus mode is an extra view of the same state, so it can ship in a later slice of `play-flow-run` without changing the domain.
- Guided flows for novice players, authored by a game manager in Studio (scene types, Session Zero, world changes between scenes), are **not** settled here. They are explored in feature 6b `flow-model-brainstorm`, which may amend this ADR, for example to make focus mode the default for guided flows.
- The prototypes stay in Storybook as a reference until `play-flow-run` replaces them. That feature deletes them.
