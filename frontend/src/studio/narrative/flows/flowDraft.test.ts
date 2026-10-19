import {
  flowToDraft,
  flowToItem,
  newFlow,
  newFlowToItem,
  newPhase,
  refreshNewFlow,
  setHook,
  withRule,
  type SelectionDraft,
} from './flowDraft'
import { stepsToDrafts, type BandDraft } from '../steps/stepDraft'

// The shapes of the three preset flows: Kingsport (introduction, a player phase with hooks),
// Night job (sceneOpening hooks) and Mythic-style (journal view, no introduction).
const kingsport = {
  key: 'kingsport-mystery',
  name: 'The Kingsport Mystery',
  description: 'A one-shot investigation.',
  introduction: 'Solo play means you are the whole table.\n\nYou play one investigator.',
  default: true,
  defaultView: 'focus',
  oracles: ['fate', 'names'],
  trackers: ['clues', 'dread'],
  phases: [
    {
      key: 'prologue',
      name: 'Prologue',
      mode: 'once',
      description: 'Create your investigator.',
      selection: { rule: 'sequence', sceneTypes: ['setup'] },
    },
    {
      key: 'investigation',
      name: 'Investigation',
      mode: 'loop',
      selection: { rule: 'player', sceneTypes: ['archive', 'interview'] },
      sceneClosing: [{ key: 'clue', kind: 'prompt', title: 'A clue?' }],
      worldTurn: [{ key: 'dread', kind: 'prompt', title: 'Dread' }],
    },
  ],
}

const nightJob = {
  key: 'heist',
  name: 'Heist',
  default: true,
  defaultView: 'focus',
  oracles: ['fate'],
  trackers: ['alarm', 'edge'],
  phases: [
    {
      key: 'legwork',
      name: 'Legwork',
      act: 'Act two',
      mode: 'loop',
      selection: { rule: 'player', sceneTypes: ['social'] },
      sceneOpening: [{ key: 'scene', kind: 'prompt', title: 'Scene' }],
    },
  ],
}

const mythic = {
  key: 'mythic',
  name: 'Mythic-style adventure',
  description: 'Expected scenes.',
  defaultView: 'journal',
  oracles: [],
  trackers: ['chaos'],
  phases: [
    {
      key: 'adventure',
      name: 'Adventure',
      mode: 'loop',
      selection: { rule: 'oracle', table: 'scenes' },
      sessionOpening: [],
    },
  ],
}

describe('a flow as form state', () => {
  it.each([
    ['Kingsport', kingsport],
    ['Night job', nightJob],
    ['Mythic-style', mythic],
  ])('sends the %s flow back unchanged', (_name, stored) => {
    const draft = flowToDraft(stored)

    expect(draft).not.toBeNull()
    expect(draft && flowToItem(draft)).toEqual({ item: stored })
  })

  it('reads the fields and the phases', () => {
    const draft = flowToDraft(kingsport)

    expect(draft).toMatchObject({
      key: 'kingsport-mystery',
      default: true,
      defaultView: 'focus',
      trackers: ['clues', 'dread'],
    })
    expect(draft?.phases.map(({ key, mode }) => [key, mode])).toEqual([
      ['prologue', 'once'],
      ['investigation', 'loop'],
    ])
    expect(draft?.phases[1]?.selection).toMatchObject({
      rule: 'player',
      sceneTypes: ['archive', 'interview'],
    })
    expect(Object.keys(draft?.phases[1]?.hooks ?? {})).toEqual(['sceneClosing', 'worldTurn'])
  })

  it('keeps what it does not know, in the flow, a phase and a selection', () => {
    const stored = {
      ...nightJob,
      futureFlow: { a: 1 },
      phases: [
        {
          ...nightJob.phases[0],
          futurePhase: [1],
          selection: { rule: 'player', sceneTypes: ['social'], futureSelection: true },
        },
      ],
    }
    const draft = flowToDraft(stored)

    expect(draft && flowToItem(draft)).toEqual({ item: stored })
  })

  it('keeps a missing optional field missing and a false default out', () => {
    const draft = flowToDraft({ ...mythic, default: false })

    expect(draft?.default).toBe(false)
    expect(draft && flowToItem(draft)).toEqual({ item: mythic })
  })

  it.each([
    ['a flow with no phases list', { ...mythic, phases: undefined }],
    ['phases that are not a list', { ...mythic, phases: {} }],
    ['an unknown view', { ...mythic, defaultView: 'map' }],
    ['a default that is not a boolean', { ...mythic, default: 'yes' }],
    ['a name that is not text', { ...mythic, name: 3 }],
    ['oracles that are not keys', { ...mythic, oracles: [1] }],
    ['a phase that is not an object', { ...mythic, phases: ['adventure'] }],
    ['an unknown mode', { ...mythic, phases: [{ ...mythic.phases[0], mode: 'twice' }] }],
    ['no selection', { ...mythic, phases: [{ ...mythic.phases[0], selection: undefined }] }],
    [
      'an unknown selection rule',
      { ...mythic, phases: [{ ...mythic.phases[0], selection: { rule: 'dice' } }] },
    ],
    [
      'a sequence with no Scene Types list',
      {
        ...mythic,
        phases: [{ ...mythic.phases[0], selection: { rule: 'sequence', sceneTypes: 1 } }],
      },
    ],
    [
      'an oracle selection with no table',
      { ...mythic, phases: [{ ...mythic.phases[0], selection: { rule: 'oracle' } }] },
    ],
    ['a hook that is not a list', { ...mythic, phases: [{ ...mythic.phases[0], worldTurn: 'x' }] }],
  ])('cannot read %s', (_name, stored) => {
    expect(flowToDraft(stored)).toBeNull()
  })
})

const phaseOf = (stored: unknown) => {
  const draft = flowToDraft(stored as Record<string, unknown>)
  const phase = draft?.phases[0]
  if (draft === null || phase === undefined) {
    throw new Error('Unreadable flow.')
  }
  return { draft, phase }
}

describe('the hooks of a phase', () => {
  it('reads each as steps and leaves a hook the phase lacks missing', () => {
    const { phase } = phaseOf({ ...kingsport, phases: [kingsport.phases[1]] })

    expect(Object.keys(phase.hooks)).toEqual(['sceneClosing', 'worldTurn'])
    expect(phase.hooks.worldTurn).toMatchObject([{ kind: 'prompt', title: 'Dread' }])
  })

  it('does not write a hook the phase never had, nor an empty list for one the author emptied', () => {
    const { draft, phase } = phaseOf({ ...kingsport, phases: [kingsport.phases[1]] })
    const emptied = setHook(phase, 'worldTurn', [])
    const saved = flowToItem({ ...draft, phases: [emptied] })

    expect(Object.keys(emptied.hooks)).toEqual(['sceneClosing'])
    expect('item' in saved && saved.item.phases).toEqual([
      { ...kingsport.phases[1], worldTurn: undefined },
    ])
    expect('item' in saved && JSON.stringify(saved.item.phases)).not.toContain('worldTurn')
  })

  it('keeps a stored empty list as stored until the author touches it', () => {
    const { draft } = phaseOf(mythic)

    expect(flowToItem(draft)).toEqual({ item: mythic })
  })

  it('adds a hook in schema order and sends the edited steps', () => {
    const { draft, phase } = phaseOf(nightJob)
    const edited = setHook(
      setHook(phase, 'worldTurn', stepsToDrafts([{ key: 'tick', kind: 'prompt', title: 'Tick' }])),
      'sessionOpening',
      stepsToDrafts([{ key: 'hello', kind: 'prompt', title: 'Hello' }]),
    )
    const saved = flowToItem({ ...draft, phases: [edited] })
    const sent = 'item' in saved ? (saved.item.phases as Record<string, unknown>[]) : []

    expect(
      Object.keys(sent[0] ?? {}).filter((key) => key.endsWith('Opening') || key === 'worldTurn'),
    ).toEqual(['sessionOpening', 'sceneOpening', 'worldTurn'])
    expect(sent[0]?.worldTurn).toEqual([{ key: 'tick', kind: 'prompt', title: 'Tick' }])
  })

  it('keeps a step kind it does not know as stored', () => {
    const odd = { key: 'x', kind: 'future', title: 'X', extra: [1] }
    const { draft } = phaseOf({
      ...kingsport,
      phases: [{ ...kingsport.phases[0], sceneOpening: [odd] }],
    })

    expect(flowToItem(draft)).toMatchObject({
      item: { phases: [{ sceneOpening: [odd] }] },
    })
  })

  it('refuses the whole save, by phase and hook, when a step has a number it cannot send', () => {
    const roll = { key: 'r', kind: 'roll', title: 'R', dice: '1d6', bands: [{ upTo: 3 }, {}] }
    const { draft, phase } = phaseOf({
      ...kingsport,
      phases: [{ ...kingsport.phases[0], phaseClosing: [roll] }],
    })
    const [step] = phase.hooks.phaseClosing ?? []
    if (step?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const bad: BandDraft = {
      upTo: { form: 'integer', text: '1.5' },
      label: '',
      next: '',
      effects: [],
      extra: {},
    }
    const edited = setHook(phase, 'phaseClosing', [{ ...step, bands: [bad, ...step.bands] }])

    expect(flowToItem({ ...draft, phases: [edited] })).toEqual({
      errors: { 0: { phaseClosing: { '0.bands.0.upTo': 'Use a whole number.' } } },
    })
  })
})

describe('the selection rule', () => {
  const sequence: SelectionDraft = { rule: 'sequence', sceneTypes: ['a', 'b'], extra: { f: 1 } }

  it('keeps the Scene Types between sequence and player and drops the unknown fields', () => {
    expect(withRule(sequence, 'player')).toEqual({
      rule: 'player',
      sceneTypes: ['a', 'b'],
      extra: {},
    })
    expect(withRule(withRule(sequence, 'player'), 'sequence')).toEqual({
      rule: 'sequence',
      sceneTypes: ['a', 'b'],
      extra: {},
    })
  })

  it('starts the other field empty when going to or from an oracle', () => {
    const oracle = withRule(sequence, 'oracle')

    expect(oracle).toEqual({ rule: 'oracle', table: '', extra: {} })
    expect(withRule({ rule: 'oracle', table: 'places', extra: {} }, 'player')).toEqual({
      rule: 'player',
      sceneTypes: [],
      extra: {},
    })
  })

  it('returns the selection itself, extra fields included, while the rule is the same', () => {
    expect(withRule(sequence, 'sequence')).toBe(sequence)
  })
})

describe('a new flow', () => {
  const choices = [
    { key: 'ambush', name: 'Ambush' },
    { key: 'quiet', name: 'Quiet' },
  ]

  it('starts with the first Scene Type', () => {
    expect(newFlow(choices)).toMatchObject({ key: '', name: '', sceneType: 'ambush' })
    expect(newFlow([]).sceneType).toBe('')
  })

  it('is the smallest flow the publisher accepts', () => {
    expect(
      newFlowToItem({ ...newFlow(choices), key: ' heist ', name: ' Heist ', sceneType: 'quiet' }),
    ).toEqual({
      item: {
        key: 'heist',
        name: 'Heist',
        defaultView: 'focus',
        oracles: [],
        trackers: [],
        phases: [
          {
            key: 'main',
            name: 'Main',
            mode: 'once',
            selection: { rule: 'sequence', sceneTypes: ['quiet'] },
          },
        ],
      },
    })
  })

  it('offers the reloaded Scene Types and keeps the chosen one only while it exists', () => {
    const chosen = { ...newFlow(choices), key: 'heist', sceneType: 'quiet' }

    expect(refreshNewFlow(chosen, choices.slice(1))).toMatchObject({
      key: 'heist',
      sceneType: 'quiet',
      sceneTypes: choices.slice(1),
    })
    expect(refreshNewFlow(chosen, choices.slice(0, 1)).sceneType).toBe('ambush')
    expect(refreshNewFlow(chosen, []).sceneType).toBe('')
  })
})

describe('a new phase', () => {
  const phases = flowToDraft({
    key: 'heist',
    name: 'Heist',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: [
      { key: 'phase-2', name: 'Two', mode: 'once', selection: { rule: 'oracle', table: 'places' } },
    ],
  })?.phases

  it('is the smallest phase the publisher accepts, with a key no phase has', () => {
    expect(newPhase(phases ?? [], 'quiet')).toEqual({
      key: 'phase-3',
      name: 'Phase 2',
      act: '',
      description: '',
      mode: 'once',
      selection: { rule: 'sequence', sceneTypes: ['quiet'], extra: {} },
      hooks: {},
      extra: {},
    })
    expect(newPhase([], 'quiet').key).toBe('phase-1')
  })
})
