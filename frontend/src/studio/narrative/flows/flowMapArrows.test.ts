import type { DiagramHit } from '@/shared/flowDiagram'
import { sceneType, step } from '@/shared/flowDiagram/testSupport'

import { flowToDraft, type FlowDraft } from './flowDraft'
import { arrowTarget } from './flowMapArrows'
import { flowDiagramFromDraft } from './flowMapDiagram'

const phase = (key: string, sceneTypes: string[], rest: Record<string, unknown> = {}) => ({
  key,
  name: key.toUpperCase(),
  mode: 'once',
  selection: { rule: 'sequence', sceneTypes },
  ...rest,
})

const draft: FlowDraft | null = flowToDraft({
  key: 'f',
  name: 'F',
  defaultView: 'focus',
  oracles: [],
  trackers: [],
  phases: [
    phase('one', ['a', 'b'], { phaseOpening: [step('s')] }),
    phase('two', ['c']),
    phase('three', [], { selection: { rule: 'oracle', table: 't' } }),
  ],
})
if (draft === null) {
  throw new Error('Expected a readable flow.')
}
const sceneTypes = ['a', 'b', 'c'].map((key) => ({ definition: sceneType(key) }))
const { diagram, phaseKeys } = flowDiagramFromDraft(draft, sceneTypes, [])

const names = (key: string) => key.toUpperCase()
const hitOn = (id: string, kind: DiagramHit['kind'], inPhase: string | null): DiagramHit => ({
  id,
  kind,
  phase: inPhase,
})
const drop = (sourceId: string, hit: DiagramHit | null) =>
  arrowTarget({ sourceId, hit, phases: draft.phases, phaseKeys, diagram, names })

const hook = 'hook:one:phaseOpening'
const from = { phase: 0, hook: 'phaseOpening' }

describe('arrowTarget', () => {
  it('makes a hook arrow to a Scene Type of its own phase a transition to it', () => {
    expect(drop(hook, hitOn('scene:one:b', 'node', 'one'))).toEqual({
      from,
      to: { type: 'sceneType', sceneType: 'b' },
    })
  })

  it('ends the phase on the next phase box, its title or its mode chip', () => {
    for (const hit of [
      hitOn('phase:two', 'phase', 'two'),
      hitOn('title:two', 'header', 'two'),
      hitOn('mode:two', 'header', 'two'),
    ]) {
      expect(drop(hook, hit)).toEqual({ from, to: { type: 'endPhase' } })
    }
  })

  it('ends the flow only from the last phase, on the end node', () => {
    expect(drop('hook:three:phaseOpening', hitOn('end', 'node', null))).toEqual({
      from: { phase: 2, hook: 'phaseOpening' },
      to: { type: 'endPhase' },
    })
    expect(drop(hook, hitOn('end', 'node', null))).toEqual({
      refused:
        'Phases always run in order: only the last phase ends the flow. Drop it on the next phase.',
    })
  })

  it('refuses its own phase box, a phase that is not the next, and a Scene Type of another phase', () => {
    expect(drop(hook, hitOn('phase:one', 'phase', 'one'))).toEqual({
      refused:
        'That is phase "ONE" itself: drop it on a Scene Type of this phase, or on the next phase to end it.',
    })
    expect(drop(hook, hitOn('phase:three', 'phase', 'three'))).toEqual({
      refused: 'Phases always run in order: a transition ends "ONE" into the next phase only.',
    })
    expect(drop(hook, hitOn('scene:two:c', 'node', 'two'))).toEqual({
      refused: '"C" is in phase "TWO": a transition reaches a Scene Type of its own phase only.',
    })
  })

  it('refuses a drop on nothing, on a hook chip and on a selection node', () => {
    expect(drop(hook, null)).toEqual({ refused: 'Dropped on nothing: no transition added.' })
    const elsewhere = {
      refused: 'Drop it on a Scene Type of this phase, or on the next phase to end this one.',
    }
    expect(drop(hook, hitOn('hook:one:phaseOpening', 'header', 'one'))).toEqual(elsewhere)
    expect(drop(hook, hitOn('table:three', 'node', 'three'))).toEqual(elsewhere)
  })

  it('starts an arrow at a Scene Type: to another of its phase, or to the end of its phase', () => {
    const scene = { phase: 0, sceneType: 'a' }

    expect(drop('scene:one:a', hitOn('scene:one:b', 'node', 'one'))).toEqual({
      from: scene,
      to: { type: 'sceneType', sceneType: 'b' },
    })
    expect(drop('scene:one:a', hitOn('phase:two', 'phase', 'two'))).toEqual({
      from: scene,
      to: { type: 'endPhase' },
    })
    expect(drop('scene:one:a', hitOn('scene:two:c', 'node', 'two'))).toEqual({
      refused: '"C" is in phase "TWO": a transition reaches a Scene Type of its own phase only.',
    })
    expect(drop('scene:one:a', hitOn('phase:three', 'phase', 'three'))).toEqual({
      refused: 'Phases always run in order: a transition ends "ONE" into the next phase only.',
    })
  })

  it('refuses a Scene Type source the drawing cannot place, and says so', () => {
    expect(drop('scene:nowhere:a', hitOn('scene:one:b', 'node', 'one'))).toEqual({
      refused: 'That Scene Type cannot be told from another: rename the phase keys.',
    })
  })

  it('refuses a selection table source and an unknown source, whatever the target', () => {
    const onScene = hitOn('scene:one:b', 'node', 'one')

    expect(drop('table:three', onScene)).toEqual({
      refused:
        'Its effects sit on table entries: edit them in the oracle editor, not with an arrow.',
    })
    expect(drop('choice:one', onScene)).toEqual({ refused: 'No arrow starts from that node.' })
    expect(drop('hook:nowhere:phaseOpening', onScene)).toEqual({
      refused: 'No arrow starts from that node.',
    })
  })
})
