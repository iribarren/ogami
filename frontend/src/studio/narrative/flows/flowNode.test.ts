import { flowToDraft } from './flowDraft'
import { flowAnswer, flowChecks } from './flowError'
import { answerNode, nodePath, nodeParam, nodeTrail, parseNodePath, resolveNode } from './flowNode'

const step = { key: 'tick', kind: 'prompt', title: 'Tick' }

const draft = (...phases: Record<string, unknown>[]) => {
  const read = flowToDraft({
    key: 'heist',
    name: 'Heist',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: phases.map((phase, at) => ({
      key: `p${String(at)}`,
      name: `P${String(at)}`,
      mode: 'once',
      selection: { rule: 'sequence', sceneTypes: ['ambush'] },
      ...phase,
    })),
  })
  if (read === null) {
    throw new Error('Unreadable flow.')
  }
  return read
}

describe('the path of a node', () => {
  it.each([
    ['settings', { kind: 'settings' }],
    ['phases.2', { kind: 'phase', phase: 2 }],
    ['phases.0.selection', { kind: 'selection', phase: 0 }],
    ['phases.2.hooks.phaseClosing', { kind: 'hook', phase: 2, hook: 'phaseClosing' }],
    ['phases.2.hooks.phaseClosing.0', { kind: 'step', phase: 2, hook: 'phaseClosing', step: 0 }],
  ])('reads %s and writes it back', (path, node) => {
    expect(parseNodePath(path)).toEqual(node)
    expect(nodePath(node as never)).toBe(path)
  })

  it.each([
    undefined,
    42,
    '',
    'nope',
    'settings.1',
    'phases',
    'phases.',
    'phases.-1',
    'phases.01',
    'phases.1.5',
    'phases.1.selection.x',
    'phases.1.hooks',
    'phases.1.hooks.nothing',
    'phases.1.hooks.worldTurn.x',
    'phases.1.hooks.worldTurn.0.title',
    'phases.99999999999',
  ])('does not read %j', (path) => {
    expect(parseNodePath(path)).toBeNull()
  })
})

describe('the node search param', () => {
  it('keeps a node path and drops anything else', () => {
    expect(nodeParam('phases.1.selection')).toBe('phases.1.selection')
    expect(nodeParam('phases.x')).toBeUndefined()
    expect(nodeParam(['settings'])).toBeUndefined()
    expect(nodeParam(undefined)).toBeUndefined()
  })
})

describe('resolving a node against the draft', () => {
  const flow = draft({}, { worldTurn: [step] })

  it('opens the flow settings without a path or with a path that names nothing', () => {
    expect(resolveNode(flow, undefined)).toEqual({ kind: 'settings' })
    expect(resolveNode(flow, 'garbage')).toEqual({ kind: 'settings' })
  })

  it('keeps a node that exists', () => {
    expect(resolveNode(flow, 'phases.1.hooks.worldTurn.0')).toEqual({
      kind: 'step',
      phase: 1,
      hook: 'worldTurn',
      step: 0,
    })
    expect(resolveNode(flow, 'phases.1.hooks.sessionOpening')).toEqual({
      kind: 'hook',
      phase: 1,
      hook: 'sessionOpening',
    })
  })

  it('falls back to the nearest node that exists: a missing step to its hook, a missing phase to the settings', () => {
    expect(resolveNode(flow, 'phases.1.hooks.worldTurn.3')).toEqual({
      kind: 'hook',
      phase: 1,
      hook: 'worldTurn',
    })
    expect(resolveNode(flow, 'phases.0.hooks.worldTurn.0')).toEqual({
      kind: 'hook',
      phase: 0,
      hook: 'worldTurn',
    })
    expect(resolveNode(flow, 'phases.2.selection')).toEqual({ kind: 'settings' })
  })
})

describe('the trail of a node', () => {
  const flow = draft({ name: ' ' }, { name: 'Exploration', phaseClosing: [step] })

  it('names the phase by its number and name, or its key when it has no name', () => {
    expect(nodeTrail(flow, { kind: 'settings' })).toEqual(['Flow settings'])
    expect(nodeTrail(flow, { kind: 'phase', phase: 0 })).toEqual(['1. p0'])
    expect(nodeTrail(flow, { kind: 'selection', phase: 1 })).toEqual([
      '2. Exploration',
      'Selection',
    ])
    expect(nodeTrail(flow, { kind: 'step', phase: 1, hook: 'phaseClosing', step: 0 })).toEqual([
      '2. Exploration',
      'Phase closing',
      'Step 1',
    ])
  })
})

describe('the node an answer is about', () => {
  it('is none when nothing is said or only the page is told', () => {
    expect(answerNode(flowAnswer('flows[1].name: nope', 0, 1))).toBeNull()
    expect(answerNode(flowAnswer('flows[0].phases[7].key: nope', 0, 1))).toBeNull()
  })

  it('is the flow settings for a flow field', () => {
    expect(answerNode(flowAnswer('flows[0].name: nope', 0, 2))).toEqual({ kind: 'settings' })
  })

  it('is the phase for a phase field, the selection for a selection message', () => {
    expect(answerNode(flowAnswer('flows[0].phases[1].key: nope', 0, 2))).toEqual({
      kind: 'phase',
      phase: 1,
    })
    expect(
      answerNode(flowAnswer('flows[0].phases[0].selection.sceneTypes[0]: nope', 0, 2)),
    ).toEqual({ kind: 'selection', phase: 0 })
  })

  it('is the step for a step message and the hook for a message about the list', () => {
    expect(answerNode(flowAnswer('flows[0].phases[1].worldTurn[2].title: nope', 0, 2))).toEqual({
      kind: 'step',
      phase: 1,
      hook: 'worldTurn',
      step: 2,
    })
    expect(answerNode(flowAnswer('flows[0].phases[1].sceneClosing: nope', 0, 2))).toEqual({
      kind: 'hook',
      phase: 1,
      hook: 'sceneClosing',
    })
  })

  it('is the first place of a draft the checks refuse: the earliest phase, its fields before its selection', () => {
    const flow = draft(
      {},
      { key: ' ', selection: { rule: 'sequence', sceneTypes: [] } },
      { selection: { rule: 'sequence', sceneTypes: [] } },
    )
    expect(answerNode(flowChecks(flow) ?? flowAnswer('', 0, 0))).toEqual({
      kind: 'phase',
      phase: 1,
    })
    const second = draft({}, { selection: { rule: 'sequence', sceneTypes: [] } })
    expect(answerNode(flowChecks(second) ?? flowAnswer('', 0, 0))).toEqual({
      kind: 'selection',
      phase: 1,
    })
  })
})
