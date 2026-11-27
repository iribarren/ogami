import { flowDiagramFromReleaseContent } from '@/shared/flowDiagram'
import { loadPreset, sceneType, step, table } from '@/shared/flowDiagram/testSupport'

import type { BandDraft } from '../steps/stepDraft'
import { flowToDraft, setHook, type FlowDraft } from './flowDraft'
import { flowDiagramFromDraft, mapPhaseKeys, mergeIssues } from './flowMapDiagram'

type Json = Record<string, unknown>

function draftOf(phases: Json[], rest: Json = {}): FlowDraft {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases,
    ...rest,
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft
}

const phaseOf = (key: string, rest: Json = {}): Json => ({
  key,
  name: key.toUpperCase(),
  mode: 'once',
  selection: { rule: 'sequence', sceneTypes: [] },
  ...rest,
})

const named = (...definitions: Json[]) => definitions.map((definition) => ({ definition }))

const nextTo = (key: string) => step('s', { effects: [{ kind: 'nextScene', sceneType: key }] })

describe('flowDiagramFromDraft', () => {
  const sceneTypes = named(sceneType('a', { play: [nextTo('b')] }), sceneType('b'), sceneType('c'))

  it('draws sequence, player and oracle phases with their transitions', () => {
    const tables = named(
      table('fate', [
        { min: 1, max: 3, text: 'x', sceneType: 'a' },
        { min: 4, max: 6, text: 'y', sceneType: 'c' },
      ]),
    )
    const draft = draftOf([
      phaseOf('one', { selection: { rule: 'sequence', sceneTypes: ['a', 'b'] } }),
      phaseOf('two', { mode: 'loop', selection: { rule: 'player', sceneTypes: ['b', 'c'] } }),
      phaseOf('three', { selection: { rule: 'oracle', table: 'fate' } }),
    ])

    const { diagram, issues, phaseKeys } = flowDiagramFromDraft(draft, sceneTypes, tables)

    expect(issues).toEqual({})
    expect(phaseKeys).toEqual(['one', 'two', 'three'])
    expect(diagram.phases.map(({ key, mode, selection }) => [key, mode, selection.rule])).toEqual([
      ['one', 'once', 'sequence'],
      ['two', 'loop', 'player'],
      ['three', 'once', 'oracle'],
    ])
    expect(diagram.phases[2]?.selection.sceneTypes.map(({ key }) => key)).toEqual(['a', 'c'])
    // `a` is in phases one and three: its transition to `b` is drawn once for each.
    expect(diagram.transitions.map(({ phase, to }) => [phase, to])).toEqual([
      ['one', { type: 'sceneType', sceneType: 'b' }],
      ['three', { type: 'sceneType', sceneType: 'b' }],
    ])
  })

  it('counts the hooks that have steps', () => {
    const draft = draftOf([
      phaseOf('one', {
        selection: { rule: 'sequence', sceneTypes: ['a'] },
        worldTurn: [step('w'), step('x')],
      }),
    ])

    const { diagram } = flowDiagramFromDraft(draft, sceneTypes, [])

    expect(diagram.phases[0]?.hooks).toEqual([{ hook: 'worldTurn', label: 'World turn', steps: 2 }])
  })

  it('leaves out a Scene Type the draft does not have and says so on the node that lists it', () => {
    const draft = draftOf([
      phaseOf('one', { selection: { rule: 'sequence', sceneTypes: ['a', 'gone'] } }),
      phaseOf('two', { selection: { rule: 'player', sceneTypes: ['gone', 'b'] } }),
    ])

    const { diagram, issues } = flowDiagramFromDraft(draft, sceneTypes, [])

    expect(
      diagram.phases.map(({ selection }) => selection.sceneTypes.map(({ key }) => key)),
    ).toEqual([['a'], ['b']])
    expect(issues['phase:one']).toEqual(['Scene Type "gone" is not in the draft'])
    expect(issues['choice:two']).toEqual(['Scene Type "gone" is not in the draft'])
  })

  it('leaves out a transition to a Scene Type the draft does not have, on the node it starts from', () => {
    const draft = draftOf([phaseOf('one', { selection: { rule: 'sequence', sceneTypes: ['a'] } })])
    const lost = named(sceneType('a', { play: [nextTo('gone')] }))

    const { diagram, issues } = flowDiagramFromDraft(draft, lost, [])

    expect(diagram.transitions).toEqual([])
    expect(issues).toEqual({ 'scene:one:a': ['Scene Type "gone" is not in the draft'] })
  })

  it('draws an oracle phase whose table is missing, with the reason on the table node', () => {
    const draft = draftOf([
      phaseOf('one', { selection: { rule: 'oracle', table: 'nope' } }),
      phaseOf('two', { selection: { rule: 'oracle', table: '' } }),
    ])

    const { diagram, issues } = flowDiagramFromDraft(draft, sceneTypes, [])

    expect(diagram.phases.map(({ selection }) => selection.rule)).toEqual(['oracle', 'oracle'])
    expect(issues['table:one']).toEqual(['table "nope" is not in the draft'])
    expect(issues['table:two']).toEqual(['table "" is not in the draft'])
  })

  it('keeps drawing around a hook whose steps cannot be built yet', () => {
    const roll = { key: 'r', kind: 'roll', title: 'R', dice: '1d6', bands: [{ upTo: 3 }, {}] }
    const draft = draftOf([
      phaseOf('one', {
        selection: { rule: 'sequence', sceneTypes: ['a'] },
        phaseClosing: [roll],
        worldTurn: [step('w')],
      }),
    ])
    const phase = draft.phases[0]
    const [rolled] = phase?.hooks.phaseClosing ?? []
    if (phase === undefined || rolled?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const bad: BandDraft = {
      upTo: { form: 'integer', text: '1.5' },
      label: '',
      next: '',
      effects: [],
      extra: {},
    }
    const edited = {
      ...draft,
      phases: [setHook(phase, 'phaseClosing', [{ ...rolled, bands: [bad, ...rolled.bands] }])],
    }

    const { diagram, issues } = flowDiagramFromDraft(edited, sceneTypes, [])

    expect(diagram.phases[0]?.hooks.map(({ hook, steps }) => [hook, steps])).toEqual([
      ['phaseClosing', 1],
      ['worldTurn', 1],
    ])
    expect(issues).toEqual({ 'hook:one:phaseClosing': ['Use a whole number.'] })
  })

  it('numbers a phase with no key and one with a key another phase has', () => {
    const draft = draftOf([
      phaseOf('', { name: 'Blank' }),
      phaseOf('same'),
      phaseOf('same'),
      phaseOf(' same '),
    ])

    expect(mapPhaseKeys(draft)).toEqual(['phase-1', 'same', 'same-2', 'same-3'])
    const { diagram } = flowDiagramFromDraft(draft, [], [])
    expect(diagram.phases.map(({ key }) => key)).toEqual(['phase-1', 'same', 'same-2', 'same-3'])
  })

  it('draws a flow with no name, no key and no phases', () => {
    const draft = draftOf([], { key: '', name: '' })

    const { diagram, issues } = flowDiagramFromDraft(draft, [], [])

    expect(diagram).toMatchObject({ key: 'flow', name: 'flow', phases: [], transitions: [] })
    expect(issues).toEqual({})
  })

  it('reads a legacy phase with no optional field (no act, hooks or description)', () => {
    const draft = draftOf([
      { key: 'p', name: 'P', mode: 'once', selection: { rule: 'player', sceneTypes: ['a'] } },
    ])

    const { diagram, issues } = flowDiagramFromDraft(draft, sceneTypes, [])

    expect(issues).toEqual({})
    expect(diagram.phases[0]).toMatchObject({ act: null, hooks: [] })
  })

  it('draws the Cold Berth preset read as a draft exactly as the release adapter does', () => {
    const preset = loadPreset('dogwatch') as {
      sceneTypes: Json[]
      oracles: { tables: Json[] }
      flows: Json[]
    }
    const flow = preset.flows.find(({ key }) => key === 'cold-berth')
    const draft = flowToDraft(flow ?? {})
    if (draft === null) {
      throw new Error('Expected the preset flow to be readable.')
    }

    const { diagram, issues } = flowDiagramFromDraft(
      draft,
      named(...preset.sceneTypes),
      named(...preset.oracles.tables),
    )

    expect(issues).toEqual({})
    expect(diagram).toEqual(flowDiagramFromReleaseContent(preset, 'cold-berth'))
  })
})

describe('mergeIssues', () => {
  it('joins the messages of a node without repeating one', () => {
    expect(mergeIssues({ a: ['x'], b: ['y'] }, { a: ['x', 'z'] }, {})).toEqual({
      a: ['x', 'z'],
      b: ['y'],
    })
  })
})
