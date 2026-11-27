import { flowToDraft, type PhaseDraft } from './flowDraft'
import {
  addSceneType,
  followMove,
  followNode,
  isRefusal,
  movePhase,
  placeSceneType,
  removeSceneType,
  sceneDrop,
  toggleMode,
} from './flowMapEdits'

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

/** Phases with the given selections, as the flow reads them: `[rule, ...sceneTypes or the table]`. */
const withSelections = (...selections: [string, ...string[]][]): PhaseDraft[] => {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: selections.map(([rule, ...rest], at) => ({
      key: `p${String(at)}`,
      name: `P${String(at)}`,
      mode: 'once',
      selection: rule === 'oracle' ? { rule, table: rest[0] } : { rule, sceneTypes: rest },
    })),
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft.phases
}
const scenes = (list: PhaseDraft[]) =>
  list.map(({ selection }) =>
    selection.rule === 'oracle' ? selection.table : selection.sceneTypes,
  )
const upper = (key: string) => key.toUpperCase()
const outcome = (result: ReturnType<typeof addSceneType>) =>
  isRefusal(result)
    ? { refused: result.refused }
    : { scenes: scenes(result.phases), say: result.say }

describe('addSceneType', () => {
  it('adds at the end of a sequence, in front of a place, and appends to a player list', () => {
    const list = withSelections(['sequence', 'a', 'b'], ['player', 'a'])

    expect(outcome(addSceneType(list, 0, 'c', null, upper))).toEqual({
      scenes: [['a', 'b', 'c'], ['a']],
      say: 'Added "C" to phase "P0".',
    })
    expect(outcome(addSceneType(list, 0, 'c', 1, upper)).scenes).toEqual([['a', 'c', 'b'], ['a']])
    expect(outcome(addSceneType(list, 1, 'c', 0, upper)).scenes).toEqual([
      ['a', 'b'],
      ['a', 'c'],
    ])
    expect(scenes(list)).toEqual([['a', 'b'], ['a']])
  })

  it('refuses an oracle phase, a Scene Type already listed, a full list, a missing phase and an empty key', () => {
    const full = withSelections([
      'sequence',
      ...Array.from({ length: 20 }, (_, at) => `s${String(at)}`),
    ])
    const list = withSelections(['oracle', 'places'], ['sequence', 'a'])

    expect(outcome(addSceneType(list, 0, 'c', null, upper))).toEqual({
      refused: 'Phase "P0" draws its Scene Types from table "places": edit the table instead.',
    })
    expect(outcome(addSceneType(list, 1, 'a', null, upper))).toEqual({
      refused: '"A" is already in phase "P1".',
    })
    expect(outcome(addSceneType(full, 0, 'c', null, upper)).refused).toMatch(/lists 20 Scene Types/)
    expect(outcome(addSceneType(list, 5, 'c', null, upper)).refused).toBe(
      'That phase is not in the flow.',
    )
    expect(outcome(addSceneType(list, 1, '', null, upper)).refused).toBe(
      'Pick a Scene Type to add.',
    )
  })
})

describe('removeSceneType', () => {
  it('takes a Scene Type out, and every copy a legacy list repeats', () => {
    const list = withSelections(['sequence', 'a', 'b', 'a'])

    expect(outcome(removeSceneType(list, 0, 'a', upper))).toEqual({
      scenes: [['b']],
      say: 'Removed "A" from phase "P0".',
    })
  })

  it('refuses a Scene Type the phase does not list and an oracle phase', () => {
    const list = withSelections(['sequence', 'a'], ['oracle', 'places'])

    expect(outcome(removeSceneType(list, 0, 'z', upper)).refused).toBe(
      '"Z" is not in the selection of phase "P0".',
    )
    expect(outcome(removeSceneType(list, 1, 'a', upper)).refused).toMatch(/draws its Scene Types/)
  })
})

describe('placeSceneType', () => {
  it('moves a Scene Type in front of a place or to the end of a sequence', () => {
    const list = withSelections(['sequence', 'a', 'b', 'c'])

    expect(outcome(placeSceneType(list, 0, 'c', 0, upper)).scenes).toEqual([['c', 'a', 'b']])
    expect(outcome(placeSceneType(list, 0, 'a', 2, upper)).scenes).toEqual([['b', 'a', 'c']])
    expect(outcome(placeSceneType(list, 0, 'a', null, upper))).toEqual({
      scenes: [['b', 'c', 'a']],
      say: 'Moved "A" to place 3 of phase "P0".',
    })
  })

  it('says a Scene Type stays when its place does not change, and always for a player list', () => {
    const list = withSelections(['sequence', 'a', 'b'], ['player', 'a', 'b'])

    expect(outcome(placeSceneType(list, 0, 'a', 1, upper)).refused).toBe(
      '"A" stays where it is in phase "P0".',
    )
    expect(outcome(placeSceneType(list, 0, 'b', null, upper)).refused).toMatch(/stays where it is/)
    expect(outcome(placeSceneType(list, 1, 'a', null, upper)).refused).toMatch(/stays where it is/)
  })
})

describe('sceneDrop', () => {
  it('adds a palette Scene Type to the phase it lands on, and cancels on no phase', () => {
    const list = withSelections(['sequence', 'a'])

    expect(outcome(sceneDrop(list, { sceneType: 'b', from: null }, 0, null, upper)).scenes).toEqual(
      [['a', 'b']],
    )
    expect(outcome(sceneDrop(list, { sceneType: 'b', from: null }, -1, null, upper))).toEqual({
      refused: 'Dropped outside every phase: nothing changed.',
    })
  })

  it('removes a selected Scene Type dropped on no phase, and repositions it inside its sequence', () => {
    const list = withSelections(['sequence', 'a', 'b'])

    expect(outcome(sceneDrop(list, { sceneType: 'a', from: 0 }, -1, null, upper)).scenes).toEqual([
      ['b'],
    ])
    expect(outcome(sceneDrop(list, { sceneType: 'a', from: 0 }, 0, null, upper)).scenes).toEqual([
      ['b', 'a'],
    ])
  })

  it('moves a Scene Type to another phase, or changes nothing when that phase refuses it', () => {
    const list = withSelections(['sequence', 'a', 'b'], ['player', 'c'], ['oracle', 'places'])
    const into = (to: number) =>
      outcome(sceneDrop(list, { sceneType: 'a', from: 0 }, to, null, upper))

    expect(into(1)).toEqual({
      scenes: [['b'], ['c', 'a'], 'places'],
      say: 'Moved "A" from phase "P0" to phase "P1".',
    })
    expect(into(2).refused).toMatch(/draws its Scene Types from table "places"/)
    const twice = withSelections(['sequence', 'a'], ['player', 'a'])
    expect(outcome(sceneDrop(twice, { sceneType: 'a', from: 0 }, 1, null, upper)).refused).toBe(
      '"A" is already in phase "P1".',
    )
    expect(scenes(twice)).toEqual([['a'], ['a']])
  })

  it('leaves the others draggable when a legacy selection lists a key no Scene Type has', () => {
    const list = withSelections(['sequence', 'ghost', 'a'], ['player'])

    expect(outcome(sceneDrop(list, { sceneType: 'a', from: 0 }, 1, null, upper)).scenes).toEqual([
      ['ghost'],
      ['a'],
    ])
  })
})
