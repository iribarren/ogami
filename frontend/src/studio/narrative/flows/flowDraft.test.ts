import { flowToDraft, flowToItem, newFlow, newFlowToItem } from './flowDraft'

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
    expect(draft && flowToItem(draft).item).toEqual(mythic)
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
})
