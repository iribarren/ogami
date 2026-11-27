import type { DiagramHit, FlowDiagram } from '@/shared/flowDiagram'

import type { PhaseDraft, PhaseHook } from './flowDraft'
import { phaseLabel, type MapRefusal, type SceneNames } from './flowMapEdits'
import { mapTarget, sceneOf } from './flowMapNodes'

/** What an arrow starts from: a hook of a phase, or one of the phase's Scene Types (whose steps live on its own page). */
export type ArrowFrom = { phase: number; hook: PhaseHook } | { phase: number; sceneType: string }

/** What an arrow ends on: a Scene Type of the phase, or the end of the phase. */
export type ArrowTo = { type: 'sceneType'; sceneType: string } | { type: 'endPhase' }

/** An arrow the flow can express. */
export interface Arrow {
  from: ArrowFrom
  to: ArrowTo
}

const ORDER = 'Phases always run in order'

/**
 * What an arrow from `sourceId` dropped on `hit` means, or why it is refused. A transition goes from
 * a hook or a Scene Type to a Scene Type of the same phase (`nextScene`, `switchSceneType`), or ends the phase by
 * going to the next phase's box or, for the last phase, to the end of the flow. Anything else says
 * why not, and the draft stays as it is.
 */
export function arrowTarget({
  sourceId,
  hit,
  phases,
  phaseKeys,
  diagram,
  names,
}: {
  sourceId: string
  hit: DiagramHit | null
  phases: readonly PhaseDraft[]
  phaseKeys: readonly string[]
  diagram: FlowDiagram
  names: SceneNames
}): Arrow | MapRefusal {
  const from = arrowFrom(sourceId, phases, phaseKeys, diagram)
  if ('refused' in from) {
    return from
  }
  if (hit === null) {
    return { refused: 'Dropped on nothing: no transition added.' }
  }
  const own = phases[from.phase]
  const last = from.phase === phaseKeys.length - 1
  const next = phaseKeys[from.phase + 1]
  const ends = { from, to: { type: 'endPhase' } } as const

  if (hit.id.startsWith('scene:')) {
    const scene = sceneOf(hit.id, phaseKeys, diagram)
    if (scene === null) {
      return { refused: 'That Scene Type cannot be told from another: rename the phase keys.' }
    }
    return scene.phase === from.phase
      ? { from, to: { type: 'sceneType', sceneType: scene.sceneType } }
      : {
          refused: `"${names(scene.sceneType)}" is in phase ${phaseLabel(phases[scene.phase])}: a transition reaches a Scene Type of its own phase only.`,
        }
  }
  if (hit.id === 'end') {
    return last
      ? ends
      : { refused: `${ORDER}: only the last phase ends the flow. Drop it on the next phase.` }
  }
  if (hit.id.startsWith('exit:')) {
    return next !== undefined && hit.id === `exit:${next}`
      ? ends
      : { refused: `${ORDER}: drop it on the next phase to end ${phaseLabel(own)}.` }
  }
  // The box itself, or its title and mode chip: they all stand for the phase.
  if (hit.kind === 'phase' || hit.id.startsWith('title:') || hit.id.startsWith('mode:')) {
    const to = hit.phase === null ? -1 : phaseKeys.indexOf(hit.phase)
    if (to === from.phase) {
      return {
        refused: `That is phase ${phaseLabel(own)} itself: drop it on a Scene Type of this phase, or on the next phase to end it.`,
      }
    }
    return to === from.phase + 1
      ? ends
      : { refused: `${ORDER}: a transition ends ${phaseLabel(own)} into the next phase only.` }
  }
  return {
    refused: 'Drop it on a Scene Type of this phase, or on the next phase to end this one.',
  }
}

/** The hook or Scene Type an arrow starts from, or the reason no arrow starts from that node. */
function arrowFrom(
  sourceId: string,
  phases: readonly PhaseDraft[],
  phaseKeys: readonly string[],
  diagram: FlowDiagram,
): ArrowFrom | MapRefusal {
  if (sourceId.startsWith('table:')) {
    return {
      refused:
        'Its effects sit on table entries: edit them in the oracle editor, not with an arrow.',
    }
  }
  if (sourceId.startsWith('scene:')) {
    const scene = sceneOf(sourceId, phaseKeys, diagram)
    return scene === null
      ? { refused: 'That Scene Type cannot be told from another: rename the phase keys.' }
      : { phase: scene.phase, sceneType: scene.sceneType }
  }
  const target = mapTarget(sourceId, 'hook', phaseKeys, diagram)
  if (target?.type !== 'node' || target.node.kind !== 'hook') {
    return { refused: 'No arrow starts from that node.' }
  }
  const { phase, hook } = target.node
  return phases[phase] === undefined
    ? { refused: 'That phase is not in the flow.' }
    : { phase, hook }
}
