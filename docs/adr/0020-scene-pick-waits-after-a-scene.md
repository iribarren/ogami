# 0020. The scene pick waits after a scene

- **Status:** Accepted
- **Date:** 2026-10-13
- **Amends:** [ADR 0018](0018-narrativeflow-control-flow-and-cast.md) decision 11

## Context

[ADR 0018](0018-narrativeflow-control-flow-and-cast.md) decision 11 says a phase with one Scene Type picks it automatically. The FlowRun applied it every time it reached the scene pick, after a scene included.

The `preset-mythic-flow` playtest (finding P2, 2026-10-13) showed the cost. The Mythic-style `adventure` phase loops on one Scene Type, so the next scene started the moment the previous one closed. "End session" is offered only at the scene pick, so guided play could never end a session there. The only way out was Pause, Start session, Resume, which left an empty scene in the old session.

## Decision

A selection with one Scene Type is picked automatically only **when a session starts or a phase opens**. After a scene the FlowRun waits at the scene pick, where the player chooses:

- **Play this:** start the only Scene Type's next scene.
- **End session:** run the session closing and end the session.
- **Move on:** end the loop phase.

The FlowRun keeps whether the pick is due to pick by itself (`autoPickDue`). Running the session opening or the phase opening sets it, and starting a scene clears it. The waiting state is stable: resuming guidance or loading the campaign again leaves the FlowRun at the pick. A FlowRun stored before this decision reads as waiting, so no scene starts by surprise.

The next session's start picks again, as does the next phase's opening. A forced next Scene Type and an oracle selection wait for the player, as before.

## Consequences

- One extra click per scene in a loop phase with one Scene Type. The pick card says what comes next.
- "End session" is reachable between scenes in every guided Flow.
- Completing the last step of a scene never starts another one, so a session that holds the most scenes no longer refuses that step.
- A once phase whose sequence has several scenes also waits between them. Its opening still picks the first one.
