import { FlowDiagramError, type FlowDiagram } from './flowDiagram'

/**
 * One phase of a flow with its own transitions, for placements that show a single phase. Transitions
 * that end the phase keep their next-phase target, and `continuesTo` names the phase a cut of a non-last
 * phase continues to: the renderer draws an exit stub naming it.
 */
export function phaseDiagram(diagram: FlowDiagram, phaseKey: string): FlowDiagram {
  const index = diagram.phases.findIndex((candidate) => candidate.key === phaseKey)
  const phase = diagram.phases[index]
  if (phase === undefined) {
    throw new FlowDiagramError(`flows[${diagram.key}].phases`, `no phase "${phaseKey}"`)
  }

  const next = diagram.phases[index + 1]

  return {
    ...diagram,
    phases: [phase],
    continuesTo: next === undefined ? null : { key: next.key, name: next.name },
    transitions: diagram.transitions.filter((transition) => transition.phase === phaseKey),
  }
}
