import { sceneType, step, table } from '@/shared/flowDiagram/testSupport'

import { flowToDraft, type FlowDraft } from './flowDraft'
import { noAnswer, type FlowAnswer } from './flowError'
import { flowDiagramFromDraft } from './flowMapDiagram'
import {
  answerIssues,
  insertionIndex,
  mapTarget,
  selectedMapId,
  selectedScene,
} from './flowMapNodes'

const draftOf = (phases: Record<string, unknown>[]): FlowDraft => {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases,
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft
}

const phase = (key: string, selection: Record<string, unknown>) => ({
  key,
  name: key,
  mode: 'once',
  selection,
})

const draft = draftOf([
  phase('one', { rule: 'sequence', sceneTypes: ['a', 'b'] }),
  phase('two', { rule: 'player', sceneTypes: ['a'] }),
  phase('three', { rule: 'oracle', table: 't' }),
  phase('empty', { rule: 'sequence', sceneTypes: [] }),
])
const keys = ['one', 'two', 'three', 'empty']
const catalog = ['a', 'b', 'b:c', 'c'].map((key) => ({ definition: sceneType(key) }))
const mapOf = (of: FlowDraft) => flowDiagramFromDraft(of, catalog, [{ definition: table('t', []) }])
const { diagram } = mapOf(draft)

describe('mapTarget', () => {
  it.each([
    ['phase:two', 'phase', { type: 'node', node: { kind: 'phase', phase: 1 } }],
    ['choice:two', 'choice', { type: 'node', node: { kind: 'selection', phase: 1 } }],
    ['table:three', 'table', { type: 'node', node: { kind: 'selection', phase: 2 } }],
    [
      'hook:one:worldTurn',
      'hook',
      { type: 'node', node: { kind: 'hook', phase: 0, hook: 'worldTurn' } },
    ],
    ['scene:two:a', 'sceneType', { type: 'sceneType', sceneType: 'a' }],
  ] as const)('reads %s', (id, kind, target) => {
    expect(mapTarget(id, kind, keys, diagram)).toEqual(target)
  })

  it('reads ids of a phase whose key has colons from what the drawing holds', () => {
    // `a:b:c` could be Scene Type `b:c` of phase `a`, or Scene Type `c` of phase `a:b`.
    const odd = draftOf([
      phase('a', { rule: 'sequence', sceneTypes: ['b:c'] }),
      phase('a:b', { rule: 'sequence', sceneTypes: ['a'] }),
    ])
    const { diagram: drawn, phaseKeys } = mapOf(odd)

    expect(mapTarget('scene:a:b:c', 'sceneType', phaseKeys, drawn)).toEqual({
      type: 'sceneType',
      sceneType: 'b:c',
    })
    expect(mapTarget('scene:a:b:a', 'sceneType', phaseKeys, drawn)).toEqual({
      type: 'sceneType',
      sceneType: 'a',
    })
    expect(mapTarget('hook:a:b:phaseOpening', 'hook', phaseKeys, drawn)).toEqual({
      type: 'node',
      node: { kind: 'hook', phase: 1, hook: 'phaseOpening' },
    })
  })

  it('is null for a Scene Type id that two phases could own', () => {
    const odd = draftOf([
      phase('a', { rule: 'sequence', sceneTypes: ['b:c'] }),
      phase('a:b', { rule: 'sequence', sceneTypes: ['c'] }),
    ])
    const { diagram: drawn, phaseKeys } = mapOf(odd)

    expect(mapTarget('scene:a:b:c', 'sceneType', phaseKeys, drawn)).toBeNull()
  })

  it('is null for an id the draft does not have', () => {
    expect(mapTarget('phase:gone', 'phase', keys, diagram)).toBeNull()
    expect(mapTarget('hook:one:nope', 'hook', keys, diagram)).toBeNull()
    expect(mapTarget('scene:gone:a', 'sceneType', keys, diagram)).toBeNull()
    expect(mapTarget('scene:one:gone', 'sceneType', keys, diagram)).toBeNull()
    expect(mapTarget('choice:gone', 'choice', keys, diagram)).toBeNull()
  })
})

describe('selectedMapId', () => {
  it('names the diagram node of the selected editor node', () => {
    const at = (node: Parameters<typeof selectedMapId>[2]) => selectedMapId(draft, keys, node)
    expect(at({ kind: 'settings' })).toBeUndefined()
    expect(at({ kind: 'phase', phase: 0 })).toBe('phase:one')
    expect(at({ kind: 'selection', phase: 0 })).toBeUndefined()
    expect(at({ kind: 'selection', phase: 1 })).toBe('choice:two')
    expect(at({ kind: 'selection', phase: 2 })).toBe('table:three')
    expect(at({ kind: 'hook', phase: 0, hook: 'worldTurn' })).toBe('hook:one:worldTurn')
    expect(at({ kind: 'step', phase: 0, hook: 'worldTurn', step: 1 })).toBe('hook:one:worldTurn')
    expect(at({ kind: 'phase', phase: 9 })).toBeUndefined()
  })
})

describe('answerIssues', () => {
  const said = (phases: FlowAnswer['phases']): FlowAnswer => ({ ...noAnswer, phases })
  const blank = { fields: {}, messages: [], selection: [], hooks: {} }

  it('puts a phase field and a message on its box', () => {
    const issues = answerIssues(
      draft,
      keys,
      said([{ ...blank, fields: { key: 'Enter a key.' }, messages: ['Odd.'] }]),
    )

    expect(issues).toEqual({ 'phase:one': ['Enter a key.', 'Odd.'] })
  })

  it('puts a selection message on the nodes that draw the selection', () => {
    const picked = (message: string) => ({ ...blank, selection: [message] })

    const issues = answerIssues(
      draft,
      keys,
      said([picked('a'), picked('b'), picked('c'), picked('d')]),
    )

    expect(issues).toEqual({
      'scene:one:a': ['a'],
      'scene:one:b': ['a'],
      'choice:two': ['b'],
      'table:three': ['c'],
      'phase:empty': ['d'],
    })
  })

  it('falls back to the phase box when every Scene Type of a sequence is missing', () => {
    const gone = draftOf([phase('one', { rule: 'sequence', sceneTypes: ['ghost', 'spook'] })])
    const map = mapOf(gone)

    const issues = answerIssues(
      gone,
      map.phaseKeys,
      said([{ ...blank, selection: ['x'] }]),
      map.drawn,
    )

    expect(issues).toEqual({ 'phase:one': ['x'] })
  })

  it('keeps a selection message to the Scene Types that are drawn, once each', () => {
    const partly = draftOf([phase('one', { rule: 'sequence', sceneTypes: ['a', 'ghost', 'a'] })])
    const map = mapOf(partly)

    const issues = answerIssues(
      partly,
      map.phaseKeys,
      said([{ ...blank, selection: ['x'] }]),
      map.drawn,
    )

    expect(issues).toEqual({ 'scene:one:a': ['x'] })
  })

  it('puts the step errors of a hook on its chip', () => {
    const error = {
      index: 0,
      band: null,
      effect: null,
      field: null,
      message: 'Use a whole number.',
    }

    const issues = answerIssues(
      draft,
      keys,
      said([
        { ...blank, hooks: { sceneClosing: [error, { ...error, index: null, message: 'List.' }] } },
      ]),
    )

    expect(issues).toEqual({ 'hook:one:sceneClosing': ['Use a whole number.', 'List.'] })
  })

  it('leaves out what has no node: the flow, the page, a phase the draft lacks', () => {
    const answer: FlowAnswer = {
      ...said([]),
      fields: { key: 'Enter a key.' },
      page: 'Saving failed.',
    }
    answer.phases[9] = { ...blank, messages: ['x'] }

    expect(answerIssues(draft, keys, answer)).toEqual({})
    expect(answerIssues(draft, keys, noAnswer)).toEqual({})
  })
})

describe('selectedScene', () => {
  it('names the phase and selection of a Scene Type node the selection lists', () => {
    expect(selectedScene('scene:one:b', draft, keys, diagram)).toEqual({
      phase: 0,
      sceneType: 'b',
      selection: { rule: 'sequence', sceneTypes: ['a', 'b'], extra: {} },
    })
    expect(selectedScene('scene:two:a', draft, keys, diagram)?.selection.rule).toBe('player')
  })

  it('is null for an oracle phase, an id no phase owns and a Scene Type only a transition reaches', () => {
    // Scene Type a's play step sends the flow to b, which phase `one` does not list.
    const reached = draftOf([phase('one', { rule: 'sequence', sceneTypes: ['a'] })])
    const reaching = flowDiagramFromDraft(
      reached,
      [
        {
          definition: sceneType('a', {
            play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'b' }] })],
          }),
        },
        { definition: sceneType('b') },
      ],
      [],
    )

    expect(selectedScene('scene:three:a', draft, keys, diagram)).toBeNull()
    expect(selectedScene('scene:one:zzz', draft, keys, diagram)).toBeNull()
    expect(selectedScene('scene:one:b', reached, ['one'], reaching.diagram)).toBeNull()
  })
})

describe('insertionIndex', () => {
  const layout = mapOf(draft).layout
  const rectOf = (id: string) => layout?.nodes.find((node) => node.id === id)?.rect
  const a = rectOf('scene:one:a')
  const b = rectOf('scene:one:b')

  it('is the first listed Scene Type whose centre lies past the point, else null for the end', () => {
    if (layout === null || a === undefined || b === undefined) {
      throw new Error('Expected a layout with both nodes.')
    }
    const at = (x: number) => insertionIndex(layout, 'one', ['a', 'b'], { x, y: a.y })

    expect(at(a.x - 1)).toBe(0)
    expect(at(a.x + a.width / 2 + 1)).toBe(1)
    expect(at(b.x + b.width / 2 + 1)).toBeNull()
  })

  it('passes over a listed key the drawing has no node for', () => {
    if (layout === null || b === undefined) {
      throw new Error('Expected a layout.')
    }

    expect(insertionIndex(layout, 'one', ['ghost', 'b'], { x: b.x, y: b.y })).toBe(1)
  })
})
