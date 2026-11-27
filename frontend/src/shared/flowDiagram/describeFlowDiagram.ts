import type { DiagramPhase, DiagramTransition, FlowDiagram } from './flowDiagram'
import { HOOK_LABELS } from './hookLabels'

/** A phase in words, for a reader who does not see the drawing. */
export interface PhaseDescription {
  /** The name with its mode and act, such as `Wake (once)`. */
  title: string
  /** How the phase picks its scene: `In order`, `Player chooses` or `Drawn from <table>`. */
  selection: string
  /** Every Scene Type drawn in the phase; the ones only a transition reaches say so. */
  sceneTypes: string[]
  /** The hooks with steps, such as `World turn (4 steps)`. */
  hooks: string[]
}

/** The text alternative of a diagram: the phases in flow order, then every transition. */
export interface FlowDiagramDescription {
  title: string
  phases: PhaseDescription[]
  transitions: string[]
}

export function describeFlowDiagram(diagram: FlowDiagram): FlowDiagramDescription {
  return {
    title: diagram.name,
    phases: diagram.phases.map(describePhase),
    transitions: diagram.transitions.map((transition) => describeTransition(diagram, transition)),
  }
}

function describePhase(phase: DiagramPhase): PhaseDescription {
  const details = phase.act === null ? phase.mode : `${phase.mode}, ${phase.act}`

  return {
    title: `${phase.name} (${details})`,
    selection: describeSelection(phase),
    sceneTypes: [
      ...phase.selection.sceneTypes.map((sceneType) => sceneType.name),
      ...phase.reached.map((sceneType) => `${sceneType.name} (reached by a transition)`),
    ],
    hooks: phase.hooks.map(
      (hook) => `${hook.label} (${String(hook.steps)} ${hook.steps === 1 ? 'step' : 'steps'})`,
    ),
  }
}

function describeSelection(phase: DiagramPhase): string {
  switch (phase.selection.rule) {
    case 'sequence':
      return 'In order'
    case 'player':
      return 'Player chooses'
    case 'oracle':
      return `Drawn from ${phase.selection.table.name}`
  }
}

function describeTransition(diagram: FlowDiagram, transition: DiagramTransition): string {
  const phase = diagram.phases.find((candidate) => candidate.key === transition.phase)
  const sceneTypes = new Map(
    [...(phase?.selection.sceneTypes ?? []), ...(phase?.reached ?? [])].map((sceneType) => [
      sceneType.key,
      sceneType.name,
    ]),
  )
  const phaseName = phase?.name ?? transition.phase

  const origin = ((): string => {
    switch (transition.from.type) {
      case 'hook':
        return HOOK_LABELS[transition.from.hook]
      case 'sceneType':
        return sceneTypes.get(transition.from.sceneType) ?? transition.from.sceneType
      case 'selectionTable':
        return phase?.selection.rule === 'oracle' ? phase.selection.table.name : 'Selection table'
    }
  })()
  // A table's entry reads as the table, placed by the hook or Scene Type that rolls it.
  const source =
    transition.via === null
      ? `${origin} (${phaseName})`
      : `${transition.via.name} (${origin.toLowerCase() === origin ? origin : lowerFirst(origin)}, ${phaseName})`

  return `${source} → ${describeTarget(transition, sceneTypes)}`
}

function describeTarget(transition: DiagramTransition, sceneTypes: Map<string, string>): string {
  const to = transition.to
  switch (to.type) {
    case 'sceneType': {
      const name = sceneTypes.get(to.sceneType) ?? to.sceneType

      return transition.kind === 'switchSceneType'
        ? `switches the scene to: ${name}`
        : `next scene: ${name}`
    }
    case 'nextPhase':
      return `ends the phase: ${to.name}`
    case 'end':
      return 'ends the phase and the flow'
  }
}

function lowerFirst(text: string): string {
  return text.charAt(0).toLowerCase() + text.slice(1)
}
