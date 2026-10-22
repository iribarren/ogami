import { flowToDraft } from './flowDraft'
import {
  enableOnFlow,
  flowScope,
  hasMissing,
  missingForFlow,
  missingForSelection,
  missingForStep,
  nothingMissing,
  stepTrackers,
  type FlowScope,
} from './flowEnable'

const effectOf = (key: string) => ({ kind: 'tracker', tracker: key, op: 'add', value: 1 })

const scope: FlowScope = {
  trackers: ['heat', 'luck', 'pace'],
  oracles: ['fate', 'places'],
  sceneTypeOracles: { ambush: ['places'], quiet: ['fate', 'places'], odd: ['gone'] },
}

function draft(overrides: Record<string, unknown> = {}, steps: unknown[] = []) {
  const read = flowToDraft({
    key: 'heist',
    name: 'Heist',
    defaultView: 'focus',
    oracles: ['fate'],
    trackers: ['pace'],
    phases: [
      {
        key: 'setup',
        name: 'Setup',
        mode: 'once',
        selection: { rule: 'sequence', sceneTypes: ['ambush', 'quiet'] },
        sceneOpening: steps,
      },
    ],
    ...overrides,
  })
  if (read === null) {
    throw new Error('Unreadable flow.')
  }
  return read
}

const stepsOf = (flow: ReturnType<typeof draft>) => flow.phases[0]?.hooks.sceneOpening ?? []

describe('the trackers a step uses', () => {
  it('lists those of its effects, its bands and its options, and a condition tracker', () => {
    const flow = draft({}, [
      { key: 'a', kind: 'prompt', title: 'A', effects: [effectOf('heat'), effectOf('heat')] },
      {
        key: 'b',
        kind: 'condition',
        title: 'B',
        tracker: 'luck',
        bands: [{ upTo: 3, effects: [effectOf('pace')] }, { effects: [] }],
      },
      {
        key: 'c',
        kind: 'choice',
        title: 'C',
        options: [
          { key: 'x', label: 'X', effects: [effectOf('luck')] },
          { key: 'y', label: 'Y' },
        ],
      },
    ])

    expect(stepsOf(flow).map(stepTrackers)).toEqual([['heat'], ['luck', 'pace'], ['luck']])
  })

  it('knows nothing of a step it cannot read', () => {
    expect(stepTrackers({ kind: 'stored', stored: { kind: 'odd' } })).toEqual([])
  })
})

describe('what the flow does not enable', () => {
  it('lists the trackers of a step the flow lacks, and only the ones that exist', () => {
    const flow = draft({}, [
      { key: 'a', kind: 'prompt', title: 'A', effects: [effectOf('heat'), effectOf('pace')] },
      { key: 'b', kind: 'prompt', title: 'B', effects: [effectOf('removed')] },
    ])
    const [first, second] = stepsOf(flow)

    expect(first && missingForStep(flow, first, scope)).toEqual({ trackers: ['heat'], oracles: [] })
    expect(second && missingForStep(flow, second, scope)).toEqual(nothingMissing)
  })

  it('lists the shortcuts of the Scene Types a selection plays directly', () => {
    const flow = draft()
    const selection = flow.phases[0]?.selection
    const oracle = { rule: 'oracle' as const, table: 'places', extra: {} }

    expect(selection && missingForSelection(flow, selection, scope)).toEqual({
      trackers: [],
      oracles: ['places'],
    })
    expect(missingForSelection(flow, oracle, scope)).toEqual(nothingMissing)
    expect(
      selection && missingForSelection({ ...flow, oracles: ['fate', 'places'] }, selection, scope),
    ).toEqual(nothingMissing)
  })

  it('sums the whole flow once per key', () => {
    const flow = draft({}, [
      { key: 'a', kind: 'prompt', title: 'A', effects: [effectOf('heat'), effectOf('luck')] },
      { key: 'b', kind: 'prompt', title: 'B', effects: [effectOf('heat')] },
    ])

    const missing = missingForFlow(flow, scope)

    expect(missing).toEqual({ trackers: ['heat', 'luck'], oracles: ['places'] })
    expect(hasMissing(missing)).toBe(true)
    expect(hasMissing(missingForFlow(enableOnFlow(flow, missing), scope))).toBe(false)
  })
})

describe('enabling on the flow', () => {
  it('only adds: what is there stays, in its order, and a key already there is not doubled', () => {
    const flow = draft({ oracles: ['places', 'fate'], trackers: ['pace', 'luck'] })

    const enabled = enableOnFlow(flow, { trackers: ['heat', 'luck'], oracles: ['fate', 'x'] })

    expect(enabled.trackers).toEqual(['pace', 'luck', 'heat'])
    expect(enabled.oracles).toEqual(['places', 'fate', 'x'])
    expect(enabled.phases).toBe(flow.phases)
    expect(enableOnFlow(flow, nothingMissing)).toEqual(flow)
  })
})

describe('the scope of a narrative', () => {
  it('reads the shortcuts of each Scene Type, and none from a malformed list', () => {
    const narrative = {
      sceneTypes: [
        { key: 'ambush', definition: { oracles: ['places', 7] } },
        { key: 'odd', definition: { oracles: 'places' } },
      ],
    }
    const context = {
      trackers: [{ key: 'heat', name: 'Heat' }],
      tables: [{ key: 'places', name: 'Places', entries: [] }],
      likelihood: [{ key: 'fate', name: 'Fate', levels: [] }],
    }

    const read = flowScope(narrative as never, context as never)

    expect(read.sceneTypeOracles).toEqual({ ambush: ['places'], odd: [] })
    expect(read.trackers).toEqual(['heat'])
    expect(read.oracles).toEqual(['places', 'fate'])
  })
})
