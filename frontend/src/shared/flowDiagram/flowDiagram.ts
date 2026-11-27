/**
 * The neutral shape of a flow diagram, independent of where the flow comes from. An adapter builds it
 * (release content today, the Studio draft later); the layout, the renderer and the text alternative
 * read only this. It holds exactly what the flow model can express: phases in order, their Scene Type
 * selection and hooks, and the transitions effects make between scenes and phases.
 */

/** The hooks of a phase, in the order a diagram lists them (ReleaseVersion2::PHASE_HOOKS). */
export const PHASE_HOOKS = [
  'sessionOpening',
  'sessionClosing',
  'phaseOpening',
  'phaseClosing',
  'sceneOpening',
  'sceneClosing',
  'worldTurn',
] as const

export type PhaseHook = (typeof PHASE_HOOKS)[number]

export interface DiagramSceneType {
  key: string
  name: string
}

export interface DiagramTable {
  key: string
  name: string
}

/** How a phase picks its next Scene Type. */
export type DiagramSelection =
  | { rule: 'sequence'; sceneTypes: DiagramSceneType[] }
  | { rule: 'player'; sceneTypes: DiagramSceneType[] }
  | {
      rule: 'oracle'
      table: DiagramTable
      /** The Scene Types the table's entries draw, in table order, without duplicates. */
      sceneTypes: DiagramSceneType[]
    }

/** A hook that has steps; empty hooks are not part of the diagram. */
export interface DiagramHook {
  hook: PhaseHook
  label: string
  steps: number
}

export interface DiagramPhase {
  key: string
  name: string
  act: string | null
  mode: 'once' | 'loop'
  selection: DiagramSelection
  /** Scene Types drawn in this phase only because a transition reaches them, in first-reached order. */
  reached: DiagramSceneType[]
  hooks: DiagramHook[]
}

export type TransitionKind = 'nextScene' | 'switchSceneType' | 'endPhase'

export type TransitionSource =
  | { type: 'sceneType'; sceneType: string }
  | { type: 'hook'; hook: PhaseHook }
  | { type: 'selectionTable' }

export type TransitionTarget =
  | { type: 'sceneType'; sceneType: string }
  /** The next phase, named so a one-phase cut can still say where the phase ends. */
  | { type: 'nextPhase'; phase: string; name: string }
  | { type: 'end' }

export interface DiagramTransition {
  kind: TransitionKind
  /** The phase the effect sits in. */
  phase: string
  from: TransitionSource
  to: TransitionTarget
  /** The table whose entry carries the effect, or null when a step carries it. */
  via: DiagramTable | null
}

export interface FlowDiagram {
  key: string
  name: string
  phases: DiagramPhase[]
  transitions: DiagramTransition[]
  /**
   * Set by `phaseDiagram` on a cut of a phase that is not the last: the phase the flow continues to,
   * which the drawing shows as an exit stub whether or not an `endPhase` transition says so. Absent
   * on a whole flow; null on a cut of the last phase, which ends the flow.
   */
  continuesTo?: { key: string; name: string } | null
}

/** Content a diagram cannot be built from. The message starts with the path of the offending value. */
export class FlowDiagramError extends Error {
  readonly path: string

  constructor(path: string, problem: string) {
    super(`${path}: ${problem}`)
    this.name = 'FlowDiagramError'
    this.path = path
  }
}
