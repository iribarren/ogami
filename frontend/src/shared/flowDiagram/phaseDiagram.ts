import { FlowDiagramError, type FlowDiagram } from './flowDiagram'

/**
 * One phase of a flow with its own transitions, for placements that show a single phase. Transitions
 * that end the phase keep their next-phase target: the renderer draws an exit stub naming it.
 */
export function phaseDiagram(diagram: FlowDiagram, phaseKey: string): FlowDiagram {
  const phase = diagram.phases.find((candidate) => candidate.key === phaseKey)
  if (phase === undefined) {
    throw new FlowDiagramError(`flows[${diagram.key}].phases`, `no phase "${phaseKey}"`)
  }

  return {
    ...diagram,
    phases: [phase],
    transitions: diagram.transitions.filter((transition) => transition.phase === phaseKey),
  }
}
