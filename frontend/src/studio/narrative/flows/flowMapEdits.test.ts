import { flowToDraft, type PhaseDraft } from './flowDraft'
import { followMove, followNode, isRefusal, movePhase, toggleMode } from './flowMapEdits'

const phases = (...names: string[]): PhaseDraft[] => {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: names.map((name) => ({
      key: name.toLowerCase(),
      name,
      mode: 'once',
      selection: { rule: 'sequence', sceneTypes: ['a'] },
    })),
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft.phases
}

const names = (list: PhaseDraft[]) => list.map(({ name }) => name)

describe('movePhase', () => {
  it('moves a phase to the place of another and says before or after', () => {
    const list = phases('A', 'B', 'C')

    const later = movePhase(list, 0, 2)
    const earlier = movePhase(list, 2, 0)

    expect(isRefusal(later) ? [] : names(later.phases)).toEqual(['B', 'C', 'A'])
    expect(isRefusal(later) ? '' : later.say).toBe('Moved phase "A" after "C".')
    expect(isRefusal(later) ? null : later.move).toEqual({ from: 0, to: 2 })
    expect(isRefusal(earlier) ? [] : names(earlier.phases)).toEqual(['C', 'A', 'B'])
    expect(isRefusal(earlier) ? '' : earlier.say).toBe('Moved phase "C" before "A".')
  })

  it('refuses, with a reason, a phase dropped on itself or one that is not there', () => {
    const list = phases('A', 'B')

    expect(movePhase(list, 1, 1)).toEqual({ refused: 'Phase "B" stays where it is.' })
    expect(movePhase(list, 5, 0)).toEqual({ refused: 'That phase is not in the flow.' })
    expect(movePhase(list, 0, -1)).toEqual({ refused: 'That phase is not in the flow.' })
  })
})

describe('toggleMode', () => {
  it('switches between once and loop, and leaves the other phases alone', () => {
    const list = phases('A', 'B')

    const looped = toggleMode(list, 1)
    const back = isRefusal(looped) ? looped : toggleMode(looped.phases, 1)

    expect(isRefusal(looped) ? [] : looped.phases.map(({ mode }) => mode)).toEqual(['once', 'loop'])
    expect(isRefusal(looped) ? '' : looped.say).toBe('Phase "B" now loops.')
    expect(isRefusal(back) ? '' : back.say).toBe('Phase "B" now runs once.')
    expect(toggleMode(list, 7)).toEqual({ refused: 'That phase is not in the flow.' })
  })
})

describe('followMove', () => {
  it.each([
    [0, { from: 0, to: 2 }, 2],
    [1, { from: 0, to: 2 }, 0],
    [2, { from: 0, to: 2 }, 1],
    [3, { from: 0, to: 2 }, 3],
    [2, { from: 2, to: 0 }, 0],
    [0, { from: 2, to: 0 }, 1],
    [1, { from: 2, to: 0 }, 2],
  ])('puts the phase at %i at its new place after %j', (index, move, expected) => {
    expect(followMove(index, move)).toBe(expected)
  })

  it('moves the phase of a node and leaves the flow settings alone', () => {
    expect(followNode({ kind: 'selection', phase: 0 }, { from: 0, to: 1 })).toEqual({
      kind: 'selection',
      phase: 1,
    })
    expect(followNode({ kind: 'settings' }, { from: 0, to: 1 })).toEqual({ kind: 'settings' })
  })
})
