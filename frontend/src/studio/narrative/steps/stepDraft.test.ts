import {
  newStep,
  stepGuides,
  stepKey,
  stepsToDrafts,
  stepsToItems,
  summariseEffects,
  type BandDraft,
  type StepDraft,
} from './stepDraft'

const prompt = {
  key: 'intro',
  kind: 'prompt',
  title: 'Set the scene',
  prompt: 'What is the place like?',
  tip: 'Be brief.',
  mandatory: true,
  next: 'roll',
  inspiration: ['places', 'moods'],
  effects: [{ kind: 'endPhase' }],
  futureField: { nested: [1] },
}
const roll = {
  key: 'roll',
  kind: 'roll',
  title: 'Heat check',
  dice: '1d6',
  bands: [
    { upTo: 2, next: 'end', mood: 'calm' },
    { upTo: { tracker: 'heat' }, effects: [{ kind: 'nextScene', sceneType: 'chase' }] },
    { upTo: { formula: 'heat + 1' } },
    {},
  ],
}
const condition = {
  key: 'crowd',
  kind: 'condition',
  title: 'Crowded?',
  count: { of: 'npc', tags: ['gang'], scope: 'scene' },
  bands: [{ upTo: 1 }, { next: 'end' }],
}

function round(stored: Record<string, unknown>) {
  return stepsToItems(stepsToDrafts([stored]))
}

describe('a step draft', () => {
  it.each([prompt, roll, condition, { key: 'who', kind: 'character', title: 'Who?', extra: 1 }])(
    'gives a $kind step back as it was stored, unknown fields included',
    (stored) => {
      expect(round(stored)).toEqual({ steps: [stored] })
    },
  )

  it('keeps unknown fields of a step, of a band and of a count', () => {
    const result = round({ ...condition, bands: [{ upTo: 1, colour: 'red' }, {}] })

    expect(result).toEqual({
      steps: [
        {
          ...condition,
          bands: [{ upTo: 1, colour: 'red' }, {}],
        },
      ],
    })
  })

  it('omits empty optionals like the canonical form but keeps an empty band', () => {
    const [draft] = stepsToDrafts([roll])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const emptied: StepDraft = {
      ...draft,
      prompt: '',
      mandatory: false,
      effects: [],
      bands: [{ upTo: null, next: '', effects: [], extra: {} }],
    }

    expect(stepsToItems([{ ...emptied, bands: [] }])).toEqual({
      steps: [{ key: 'roll', kind: 'roll', title: 'Heat check', dice: '1d6' }],
    })
    expect(stepsToItems([emptied])).toEqual({
      steps: [{ key: 'roll', kind: 'roll', title: 'Heat check', dice: '1d6', bands: [{}] }],
    })
  })

  it('never marks a condition mandatory', () => {
    const drafts = stepsToDrafts([{ ...condition, mandatory: true }])

    expect(stepsToItems(drafts)).not.toHaveProperty('steps.0.mandatory')
  })

  it('keeps the limit of a stored last band on an unchanged save, and flags it', () => {
    const stored = {
      key: 'r',
      kind: 'roll',
      title: 'R',
      dice: '1d6',
      bands: [{ upTo: 2 }, { upTo: { tracker: 'heat' }, next: 'end' }],
    }

    expect(round(stored)).toEqual({ steps: [stored] })
    expect(stepGuides(stepsToDrafts([stored]))[0]?.bands).toEqual({
      1: { upTo: 'The last band catches the rest: remove its limit.' },
    })
  })

  it('refuses a band before the last that has no limit instead of saving it without one', () => {
    const [draft] = stepsToDrafts([roll])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const open: BandDraft = { upTo: null, next: '', effects: [], extra: {} }

    expect(stepsToItems([{ ...draft, bands: [open, ...draft.bands] }])).toEqual({
      errors: { '0.bands.0.upTo': 'Set a limit: only the last band catches the rest.' },
    })
  })

  it('keeps a step of another kind exactly as stored, and so a step it cannot read', () => {
    const others = [
      { key: 'ask', kind: 'oracle', title: 'Ask', oracle: 'likelihood', unknown: true },
      { key: 'x', kind: 'mystery', title: 'X' },
      { key: 'bad', kind: 'prompt', title: 'Bad', inspiration: 'places' },
      {
        key: 'both',
        kind: 'condition',
        title: 'B',
        tracker: 'heat',
        count: { of: 'npc' },
        bands: [],
      },
      { key: 'none', kind: 'condition', title: 'N', bands: [{}] },
      { key: 'odd', kind: 'roll', title: 'O', dice: '1d6', bands: [{ upTo: 1.5 }] },
      { key: 'effects', kind: 'character', title: 'E', effects: 'none' },
      'not a step',
    ]
    const drafts = stepsToDrafts(others)

    expect(drafts.map((draft) => draft.kind)).toEqual(others.map(() => 'stored'))
    expect(stepsToItems(drafts)).toEqual({ steps: others })
  })

  it('says which limits are not whole numbers and sends nothing', () => {
    const [draft] = stepsToDrafts([roll])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const bad: BandDraft = {
      upTo: { form: 'integer', text: '2x' },
      next: '',
      effects: [],
      extra: {},
    }
    const bands = [bad, ...draft.bands]

    expect(stepsToItems([{ ...draft, bands }])).toEqual({
      errors: { '0.bands.0.upTo': 'Use a whole number.' },
    })
  })

  it('starts a new step of each kind with a free key', () => {
    const existing = stepsToDrafts([{ key: 'roll-1', kind: 'roll', title: 'A', dice: '1d6' }])

    expect(newStep('roll', existing)).toMatchObject({ key: 'roll-2', dice: '1d6', bands: [] })
    expect(newStep('prompt', existing).key).toBe('prompt-1')
    expect(newStep('condition', existing)).toMatchObject({
      source: 'tracker',
      bands: [{ upTo: null }],
    })
    expect(stepsToDrafts([{ key: 'k', kind: 'oracle' }]).map(stepKey)).toEqual(['k'])
  })

  it('summarises effects', () => {
    expect(summariseEffects([])).toBe('')
    expect(summariseEffects([{ kind: 'endPhase' }])).toBe('1 effect: endPhase')
    expect(summariseEffects([{ kind: 'tracker' }, { kind: 'nextScene' }, 5])).toBe(
      '3 effects: tracker, nextScene, unknown',
    )
  })
})

describe('the guides', () => {
  const steps = (...stored: Record<string, unknown>[]) => stepGuides(stepsToDrafts(stored))

  it('flags a next that points at the same or an earlier step, and accepts later ones and end', () => {
    const guides = steps(
      { key: 'a', kind: 'character', title: 'A', next: 'b' },
      { key: 'b', kind: 'character', title: 'B', next: 'a' },
      { key: 'c', kind: 'character', title: 'C', next: 'c' },
      { key: 'd', kind: 'character', title: 'D', next: 'end' },
      { key: 'e', kind: 'character', title: 'E', next: 'nowhere' },
    )

    expect(guides.map((guide) => guide.next !== undefined)).toEqual([
      false,
      true,
      true,
      false,
      false,
    ])
  })

  it('flags a band next that goes backward, and limits that do not increase', () => {
    const guides = steps(
      { key: 'a', kind: 'character', title: 'A' },
      {
        key: 'b',
        kind: 'roll',
        title: 'B',
        dice: '1d6',
        bands: [{ upTo: 3 }, { upTo: { tracker: 'heat' } }, { upTo: 3, next: 'a' }, {}],
      },
    )

    expect(guides[1]?.bands).toEqual({
      2: {
        upTo: 'Must be above the previous limit.',
        next: 'Pick a later step: next only goes forward.',
      },
    })
  })

  it('flags a repeated key on every step but the first, and the reserved end', () => {
    const guides = steps(
      { key: 'a', kind: 'character', title: 'A' },
      { key: 'a', kind: 'oracle', title: 'B' },
      { key: 'end', kind: 'character', title: 'C' },
    )

    expect(guides.map((guide) => guide.key)).toEqual([
      undefined,
      'Another step has this key.',
      'end is reserved for the end of the list.',
    ])
  })
})
