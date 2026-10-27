import {
  DISJOINT_DIFFICULTY,
  checkDifficulty,
  newStep,
  producibleSpecials,
  setBandOutcome,
  setCheckOutcome,
  stepGuides,
  stepKey,
  stepsToDrafts,
  stepsToItems,
  type BandDraft,
  type CheckOutcomeDraft,
  type EditedStep,
  type StepDraft,
} from './stepDraft'
import type { EffectDraft } from './effectDraft'
import { stepPickers } from '../../fixtures'
import type { StepContext } from './stepContext'

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

const oracle = {
  key: 'ask',
  kind: 'oracle',
  title: 'Does it go wrong?',
  oracle: 'fate',
  likelihood: 'likely',
  branches: {
    yes: { next: 'end', effects: [{ kind: 'endPhase' }], mood: 'grim' },
    exceptionalNo: { effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
    maybe: { next: 'end' },
  },
}
const table = {
  key: 'event',
  kind: 'table',
  title: 'What happens?',
  table: 'complications',
  branches: [
    { entry: 'patrol', next: 'end', weight: 2 },
    { entry: 'lucky-break', effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
  ],
  otherwise: { next: 'end', note: 'x' },
}
const choice = {
  key: 'pick',
  kind: 'choice',
  title: 'Which way?',
  mandatory: true,
  options: [
    { key: 'left', label: 'Left', next: 'end', hint: 'x' },
    { key: 'right', label: 'Right', effects: [{ kind: 'endPhase' }] },
  ],
  skip: 'left',
}

const pick = {
  key: 'who',
  kind: 'pick',
  title: 'Who turns up?',
  of: 'npc',
  mode: 'choose',
  tags: ['gang'],
  count: { min: 1, max: 3, spare: true },
  branches: {
    found: { next: 'end', effects: [{ kind: 'endPhase' }], mood: 'grim' },
    none: { effects: [{ kind: 'sceneTitle', title: 'Nobody' }] },
    maybe: { next: 'end' },
  },
}
const check = {
  key: 'lock',
  kind: 'check',
  title: 'Pick the lock',
  check: 'skill',
  difficulty: { tracker: 'heat' },
  outcomes: [
    { band: 'fail', next: 'end', note: 'x' },
    { band: 'success', effects: [{ kind: 'sheet', field: 'stress', op: 'add', value: 1 }] },
  ],
}

const sheetEffect = { kind: 'sheet', field: 'stress', op: 'add', value: 1 }
const checkSet = {
  key: 'lock',
  kind: 'check',
  title: 'Pick the lock',
  checks: ['skill', 'finesse'],
  difficulty: 12,
  outcomes: [{ band: 'fail', next: 'end' }],
}
const sceneTable = {
  key: 'event',
  kind: 'table',
  title: 'What happens?',
  table: 'complications',
  tableBySceneType: { hideout: 'places', chase: 'moods' },
  otherwise: { next: 'end' },
}

function draftOf<K extends EditedStep['kind']>(stored: unknown, kind: K) {
  const [draft] = stepsToDrafts([stored])
  if (draft?.kind !== kind) {
    throw new Error(`Expected a ${kind} step.`)
  }
  return draft as Extract<EditedStep, { kind: K }>
}

/** The band or special result an outcome is for. */
const named = (outcome: CheckOutcomeDraft) =>
  'special' in outcome ? outcome.special : outcome.band

function round(stored: Record<string, unknown>) {
  return stepsToItems(stepsToDrafts([stored]))
}

describe('a step draft', () => {
  it.each([
    prompt,
    roll,
    condition,
    { key: 'who', kind: 'character', title: 'Who?', extra: 1 },
    oracle,
    table,
    choice,
    pick,
    check,
    { ...check, difficulty: 12 },
    { ...check, difficulty: { formula: 'heat + 1' } },
    checkSet,
    sceneTable,
    {
      ...choice,
      options: [
        { key: 'left', label: 'Left' },
        { key: 'right', label: 'Right', effects: [sheetEffect] },
      ],
    },
    { ...prompt, effects: [sheetEffect] },
  ])('gives a $kind step back as it was stored, unknown fields included', (stored) => {
    expect(round(stored)).toEqual({ steps: [stored] })
  })

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
      bands: [{ upTo: null, label: '', next: '', effects: [], extra: {} }],
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

  it('reads a band label back and sends it only when it holds text', () => {
    const labelled = {
      key: 'roll',
      kind: 'roll',
      title: 'Heat check',
      dice: '1d6',
      bands: [{ upTo: 3, label: 'Quiet' }, { label: 'Loud' }],
    }
    const [draft] = stepsToDrafts([labelled])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }

    expect(draft.bands.map((band) => band.label)).toEqual(['Quiet', 'Loud'])
    expect(stepsToItems([draft])).toEqual({ steps: [labelled] })

    const [first, last] = draft.bands
    const emptied = [
      { ...first, label: '' },
      { ...last, label: '  ' },
    ] as BandDraft[]
    expect(stepsToItems([{ ...draft, bands: emptied }])).toEqual({
      steps: [{ ...labelled, bands: [{ upTo: 3 }, {}] }],
    })
  })

  it('keeps a step whose band label is not text exactly as stored', () => {
    const stored = {
      key: 'roll',
      kind: 'roll',
      title: 'Heat check',
      dice: '1d6',
      bands: [{ label: 7 }],
    }

    expect(stepsToItems(stepsToDrafts([stored]))).toEqual({ steps: [stored] })
  })

  it('refuses a band before the last that has no limit instead of saving it without one', () => {
    const [draft] = stepsToDrafts([roll])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const open: BandDraft = { upTo: null, label: '', next: '', effects: [], extra: {} }

    expect(stepsToItems([{ ...draft, bands: [open, ...draft.bands] }])).toEqual({
      errors: { '0.bands.0.upTo': 'Set a limit: only the last band catches the rest.' },
    })
  })

  it('keeps a step of another kind exactly as stored, and so a step it cannot read', () => {
    const others = [
      { key: 'ask', kind: 'pick', title: 'Ask', of: 'npc', unknown: true },
      { key: 'p1', kind: 'pick', title: 'P', of: 'monster', mode: 'random' },
      { key: 'p2', kind: 'pick', title: 'P', of: 'npc', mode: 'one' },
      { key: 'p3', kind: 'pick', title: 'P', of: 'npc', mode: 'random', tags: 'gang' },
      { key: 'p4', kind: 'pick', title: 'P', of: 'npc', mode: 'random', count: 2 },
      { key: 'p5', kind: 'pick', title: 'P', of: 'npc', mode: 'random', count: { min: 2 } },
      {
        key: 'p6',
        kind: 'pick',
        title: 'P',
        of: 'npc',
        mode: 'random',
        count: { min: 1, max: 'x' },
      },
      { key: 'p7', kind: 'pick', title: 'P', of: 'npc', mode: 'random', branches: [] },
      { key: 'p8', kind: 'pick', title: 'P', of: 'npc', mode: 'random', branches: { found: 3 } },
      { key: 'k1', kind: 'check', title: 'K' },
      { key: 'k2', kind: 'check', title: 'K', check: 'skill', difficulty: 1.5 },
      { key: 'k3', kind: 'check', title: 'K', check: 'skill', difficulty: { dice: '1d6' } },
      { key: 'k4', kind: 'check', title: 'K', check: 'skill', outcomes: 'none' },
      { key: 'k5', kind: 'check', title: 'K', check: 'skill', outcomes: [{ next: 'end' }] },
      {
        key: 'k6',
        kind: 'check',
        title: 'K',
        check: 'skill',
        outcomes: [{ band: 'fail', next: 3 }],
      },
      { key: 'x', kind: 'mystery', title: 'X' },
      { key: 'o1', kind: 'oracle', title: 'O' },
      { key: 'o2', kind: 'oracle', title: 'O', oracle: 'fate', branches: 'none' },
      { key: 'o3', kind: 'oracle', title: 'O', oracle: 'fate', branches: { yes: { next: 3 } } },
      { key: 't1', kind: 'table', title: 'T', table: 'x', branches: [{ next: 'end' }] },
      { key: 't2', kind: 'table', title: 'T', table: 'x', otherwise: 'end' },
      { key: 't3', kind: 'table', title: 'T', table: 'x', branches: 'none' },
      { key: 't4', kind: 'table', title: 'T', table: 'x', branches: { patrol: {} } },
      { key: 'o4', kind: 'oracle', title: 'O', oracle: 'fate', branches: [{ next: 'end' }] },
      { key: 'c4', kind: 'choice', title: 'C', options: 'none' },
      { key: 'c1', kind: 'choice', title: 'C' },
      { key: 'c2', kind: 'choice', title: 'C', options: [{ key: 'a' }] },
      { key: 'c3', kind: 'choice', title: 'C', options: [], skip: 4 },
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

  it('reads a pick count as the default when absent or empty, and omits the default on save', () => {
    const base = { key: 'who', kind: 'pick', title: 'Who?', of: 'thread', mode: 'random' }

    expect(draftOf(base, 'pick')).toMatchObject({ min: '1', max: '1' })
    expect(draftOf({ ...base, count: {} }, 'pick')).toMatchObject({ min: '1', max: '1' })
    expect(round({ ...base, count: {} })).toEqual({ steps: [base] })
    expect(round({ ...base, count: { min: 1, max: 1 } })).toEqual({ steps: [base] })
    expect(round({ ...base, count: { min: 2, max: 2 } })).toEqual({
      steps: [{ ...base, count: { min: 2, max: 2 } }],
    })
    expect(round({ ...base, count: { spare: 1 } })).toEqual({
      steps: [{ ...base, count: { spare: 1 } }],
    })
  })

  it('omits empty tags and empty found and none outcomes of a pick', () => {
    const emptied = { ...pick, tags: [], count: undefined, branches: { found: {}, none: {} } }

    expect(round(emptied)).toEqual({
      steps: [{ key: 'who', kind: 'pick', title: 'Who turns up?', of: 'npc', mode: 'choose' }],
    })
  })

  it('says which pick counts are not whole numbers and sends nothing', () => {
    const [draft] = stepsToDrafts([pick])
    if (draft?.kind !== 'pick') {
      throw new Error('Expected a pick step.')
    }

    expect(stepsToItems([{ ...draft, min: '1.5', max: '' }])).toEqual({
      errors: { '0.count.min': 'Use a whole number.', '0.count.max': 'Use a whole number.' },
    })
  })

  it('keeps an outcome for a band of no check, and a repeated one, and omits empty outcomes and difficulty', () => {
    const stray = [{ band: 'ghost', next: 'end' }, { band: 'fail' }, { band: 'fail', next: 'end' }]
    const stored = { ...check, outcomes: stray }

    expect(draftOf(stored, 'check').outcomes.map(named)).toEqual(['ghost', 'fail', 'fail'])
    expect(round(stored)).toEqual({
      steps: [{ ...stored, outcomes: [stray[0], stray[2]] }],
    })
    expect(round({ ...check, difficulty: undefined, outcomes: [{ band: 'fail' }] })).toEqual({
      steps: [{ key: 'lock', kind: 'check', title: 'Pick the lock', check: 'skill' }],
    })
  })

  it('says which difficulty is not a whole number and sends nothing', () => {
    const [draft] = stepsToDrafts([check])
    if (draft?.kind !== 'check') {
      throw new Error('Expected a check step.')
    }

    expect(stepsToItems([{ ...draft, difficulty: { form: 'integer', text: '1.5' } }])).toEqual({
      errors: { '0.difficulty': 'Use a whole number.' },
    })
  })

  it('refuses a mark with no option in an outcome, where only a push cost lets the player pick', () => {
    const marking = {
      ...check,
      outcomes: [
        { band: 'success', effects: [{ kind: 'sheet', field: 'stress', op: 'mark' }] },
        { band: 'fail', next: 'end' },
      ],
    }

    expect(round(marking)).toEqual({
      errors: { '0.outcomes.0.effects.0.value': 'Pick the option to mark.' },
    })
  })

  it('writes the outcome of a band in the check band order, keeping where each had been', () => {
    const bands = ['fail', 'partial', 'success']
    const { outcomes } = draftOf(check, 'check')
    const edited = { next: 'end', effects: [], extra: {} }

    const inserted = setBandOutcome(outcomes, bands, 'partial', edited)
    expect(inserted.outcomes.map(named)).toEqual(['fail', 'partial', 'success'])
    expect(inserted.origin).toEqual([0, null, 1])

    const reordered = setBandOutcome([...outcomes].reverse(), bands, 'fail', edited)
    expect(reordered.outcomes.map(named)).toEqual(['fail', 'success'])
    expect(reordered.origin).toEqual([1, 0])

    const stray = setBandOutcome([{ ...edited, band: 'ghost' }, ...outcomes], bands, 'fail', edited)
    expect(stray.outcomes.map(named)).toEqual(['fail', 'success', 'ghost'])
  })

  describe('with special results', () => {
    const special = {
      ...check,
      outcomes: [
        { band: 'fail', next: 'end' },
        { special: 'bestialFailure', next: 'end', note: 'x' },
        { special: 'critical', effects: [sheetEffect] },
      ],
    }
    const bands = ['fail', 'success']
    const blank = { next: 'end', effects: [], extra: {} }

    it('reads an entry naming a special and writes the step back as stored', () => {
      expect(draftOf(special, 'check').outcomes).toMatchObject([
        { band: 'fail' },
        { special: 'bestialFailure' },
        { special: 'critical' },
      ])
      expect(round(special)).toEqual({ steps: [special] })
    })

    it('keeps a step as stored when an entry names both, neither or an unknown special', () => {
      for (const entry of [
        { band: 'fail', special: 'critical' },
        { next: 'end' },
        { special: 'epic' },
        { special: 3 },
      ]) {
        const odd = { ...check, outcomes: [entry] }
        const [draft] = stepsToDrafts([odd])

        expect(draft?.kind).toBe('stored')
        expect(round(odd)).toEqual({ steps: [odd] })
      }
    })

    it('omits an empty special outcome like an empty band outcome', () => {
      expect(round({ ...check, outcomes: [{ special: 'critical' }] })).toEqual({
        steps: [
          {
            key: 'lock',
            kind: 'check',
            title: 'Pick the lock',
            check: 'skill',
            difficulty: check.difficulty,
          },
        ],
      })
    })

    it('writes bands first in the check band order, then the specials in their fixed order, strays last', () => {
      const { outcomes } = draftOf(
        { ...check, outcomes: [{ band: 'ghost', next: 'end' }, ...special.outcomes] },
        'check',
      )

      const added = setCheckOutcome(outcomes, bands, { special: 'messyCritical' }, blank)
      expect(added.outcomes.map(named)).toEqual([
        'fail',
        'critical',
        'messyCritical',
        'bestialFailure',
        'ghost',
      ])
      expect(added.origin).toEqual([1, 3, null, 2, 0])

      const edited = setCheckOutcome(outcomes, bands, { band: 'success' }, blank)
      expect(edited.outcomes.map(named)).toEqual([
        'fail',
        'success',
        'critical',
        'bestialFailure',
        'ghost',
      ])
    })

    it('replaces the outcome of a special instead of adding another, and does not confuse it with a band', () => {
      const { outcomes } = draftOf(special, 'check')
      const edited = setCheckOutcome(outcomes, bands, { special: 'critical' }, blank)

      expect(edited.outcomes).toHaveLength(3)
      expect(edited.outcomes[1]).toEqual({ ...blank, special: 'critical' })
      expect(edited.origin).toEqual([0, 2, 1])
    })

    describe('and the pools of the checks', () => {
      const withSpecials = (...specials: StepContext['checks'][number]['specials'][]) =>
        ({
          ...stepPickers,
          checks: specials.map((names, at) => ({
            ...stepPickers.checks[0],
            key: `c${String(at)}`,
            specials: names,
          })),
        }) as StepContext
      const stepOf = (...checks: string[]) =>
        draftOf(
          checks.length === 1
            ? { ...check, check: checks[0], outcomes: [] }
            : { ...check, check: undefined, checks, outcomes: [] },
          'check',
        )

      it('offers a check its own specials, and none when it names no check', () => {
        const context = withSpecials(['critical', 'messyCritical', 'bestialFailure'], [])

        expect(producibleSpecials(stepOf('c0'), context)).toEqual([
          'critical',
          'messyCritical',
          'bestialFailure',
        ])
        expect(producibleSpecials(stepOf('c1'), context)).toEqual([])
        expect(producibleSpecials({ ...stepOf('c0'), check: '' }, context)).toEqual([])
      })

      it('offers a set only the specials every check of it can produce', () => {
        const context = withSpecials(
          ['critical', 'messyCritical', 'bestialFailure'],
          ['critical', 'bestialFailure'],
          ['bestialFailure'],
        )

        expect(producibleSpecials(stepOf('c0', 'c1'), context)).toEqual([
          'critical',
          'bestialFailure',
        ])
        expect(producibleSpecials(stepOf('c0', 'c1', 'c2'), context)).toEqual(['bestialFailure'])
      })

      it('does not say when a check is unknown or its pool was not loaded', () => {
        expect(producibleSpecials(stepOf('c0'), withSpecials(undefined))).toBeNull()
        expect(producibleSpecials(stepOf('gone'), withSpecials(['critical']))).toBeNull()
        expect(
          producibleSpecials(stepOf('c0', 'c1'), withSpecials(['critical'], undefined)),
        ).toBeNull()
      })

      it('flags a special the check cannot produce and one given twice, and keeps both', () => {
        const stored = {
          ...check,
          check: 'c0',
          outcomes: [
            { special: 'critical', next: 'end' },
            { special: 'bestialFailure', next: 'end' },
            { special: 'critical', next: 'end' },
          ],
        }
        const [guide] = stepGuides(stepsToDrafts([stored]), withSpecials(['critical']))

        expect(guide?.items).toEqual({
          1: 'This check cannot produce this special result.',
          2: 'Another outcome has this special result.',
        })
        expect(round(stored)).toEqual({ steps: [stored] })
      })

      it('flags nothing when the pools are unknown', () => {
        const stored = { ...check, check: 'c0', outcomes: [{ special: 'critical', next: 'end' }] }

        expect(stepGuides(stepsToDrafts([stored]), withSpecials(undefined))[0]?.items).toEqual({})
      })
    })
  })

  it('says which limits are not whole numbers and sends nothing', () => {
    const [draft] = stepsToDrafts([roll])
    if (draft?.kind !== 'roll') {
      throw new Error('Expected a roll step.')
    }
    const bad: BandDraft = {
      upTo: { form: 'integer', text: '2x' },
      label: '',
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

  it('starts a new pick and check step with the defaults', () => {
    expect(newStep('pick', [])).toMatchObject({ of: 'npc', mode: 'random', min: '1', max: '1' })
    expect(newStep('check', [])).toMatchObject({ check: '', outcomes: [] })
  })

  it('starts a new oracle, table and choice step empty, the choice with two options', () => {
    expect(newStep('oracle', [])).toMatchObject({ key: 'oracle-1', oracle: '', level: '' })
    expect(newStep('table', [])).toMatchObject({ table: '', branches: [], otherwise: { next: '' } })
    expect(newStep('choice', [])).toMatchObject({
      skip: '',
      options: [
        { key: 'option-1', label: '' },
        { key: 'option-2', label: '' },
      ],
    })
  })

  it('omits empty outcomes, an empty branch list and an empty level, like the canonical form', () => {
    const emptied = { ...oracle, likelihood: undefined, branches: { yes: {}, no: { next: '' } } }

    expect(stepsToItems([draftOf(emptied, 'oracle')])).toEqual({
      steps: [{ key: 'ask', kind: 'oracle', title: 'Does it go wrong?', oracle: 'fate' }],
    })
    expect(round({ ...table, branches: [], otherwise: {} })).toEqual({
      steps: [{ key: 'event', kind: 'table', title: 'What happens?', table: 'complications' }],
    })
    expect(round({ ...choice, skip: '' })).toEqual({
      steps: [{ ...choice, skip: undefined }],
    })
  })

  it('writes branches and options in the order they have after a move', () => {
    const tableDraft = draftOf(table, 'table')
    const choiceDraft = draftOf(choice, 'choice')
    const result = stepsToItems([
      { ...tableDraft, branches: [...tableDraft.branches].reverse() },
      { ...choiceDraft, options: [...choiceDraft.options].reverse() },
    ])

    expect(result).toEqual({
      steps: [
        { ...table, branches: [...table.branches].reverse() },
        { ...choice, options: [...choice.options].reverse() },
      ],
    })
  })

  it('says which values of an outcome are not whole numbers, keyed by where they sit', () => {
    const value = { form: 'integer' as const, text: 'x' }
    const effect: EffectDraft = { kind: 'tracker', tracker: 'heat', op: 'add', value, extra: {} }
    const outcome = { next: '', effects: [effect], extra: {} }
    const o = draftOf(oracle, 'oracle')
    const t = draftOf(table, 'table')
    const c = draftOf(choice, 'choice')

    expect(
      stepsToItems([
        { ...o, branches: { ...o.branches, yes: outcome } },
        { ...t, branches: [{ ...outcome, entry: 'a', extra: {} }], otherwise: outcome },
        { ...c, options: [{ ...outcome, key: 'a', label: 'A' }] },
      ]),
    ).toEqual({
      errors: {
        '0.branches.yes.effects.0.value': 'Use a whole number.',
        '1.branches.0.effects.0.value': 'Use a whole number.',
        '1.otherwise.effects.0.value': 'Use a whole number.',
        '2.options.0.effects.0.value': 'Use a whole number.',
      },
    })
  })
})

describe('a check set', () => {
  const set = (checks: string[]) => ({ ...draftOf(checkSet, 'check'), checks })

  it('is written as checks and no check, and read back as a set', () => {
    const draft = draftOf(checkSet, 'check')

    expect(draft).toMatchObject({ check: '', checks: ['skill', 'finesse'] })
    expect(stepsToItems([set(['luck', 'skill', 'finesse'])])).toEqual({
      steps: [{ ...checkSet, checks: ['luck', 'skill', 'finesse'] }],
    })
  })

  it('leaves a single check as it was, with no checks', () => {
    expect(draftOf(check, 'check')).toMatchObject({ check: 'skill', checks: null })
    expect(newStep('check', [])).toMatchObject({ check: '', checks: null })
  })

  it.each([
    ['fewer than 2', ['skill']],
    ['none', []],
    ['more than 10', Array.from({ length: 11 }, (_, at) => `check-${String(at)}`)],
    ['a repeat', ['skill', 'skill']],
  ])('refuses a set with %s and sends nothing', (_, checks) => {
    expect(stepsToItems([set(checks)])).toEqual({
      errors: { '0.checks': 'A check set needs 2 to 10 distinct checks.' },
    })
  })

  it('keeps a step with both check and checks, or checks that are not names, as stored', () => {
    expect(stepsToDrafts([{ ...checkSet, check: 'skill' }])[0]?.kind).toBe('stored')
    expect(stepsToDrafts([{ ...checkSet, checks: ['skill', 2] }])[0]?.kind).toBe('stored')
  })

  const guideOf = (stored: Record<string, unknown>) =>
    stepGuides(stepsToDrafts([stored]), stepPickers)[0]

  it('is fine with checks that share their band keys, in any order', () => {
    expect(guideOf(checkSet)?.checks).toBeUndefined()
    expect(guideOf({ ...checkSet, checks: ['finesse', 'skill'] })?.checks).toBeUndefined()
  })

  it('flags a check with other band keys, a check the game system lacks and a bad size', () => {
    expect(guideOf({ ...checkSet, checks: ['skill', 'luck'] })?.checks).toBe(
      'Check "luck" has other band keys than the first check of the set.',
    )
    expect(guideOf({ ...checkSet, checks: ['skill', 'gone'] })?.checks).toBe(
      'Not a check of this game system: gone.',
    )
    expect(guideOf({ ...checkSet, checks: ['skill'] })?.checks).toBe(
      'A check set needs 2 to 10 distinct checks.',
    )
  })

  it('checks the difficulty against every check of the set', () => {
    expect(guideOf({ ...checkSet, difficulty: 12 })?.difficulty).toBeUndefined()
    // Valid for skill (9 to 29) but not for finesse (5 to 15).
    expect(guideOf({ ...checkSet, difficulty: 20 })?.difficulty).toBe(
      'Outside the range of check "finesse": 5 to 15.',
    )
    expect(guideOf({ ...checkSet, difficulty: 7 })?.difficulty).toBe(
      'Outside the range of check "skill": 9 to 29.',
    )
  })

  it('has no range, and says so, when the checks of the set share no difficulty', () => {
    const apart = {
      ...stepPickers,
      checks: stepPickers.checks.map((one) =>
        one.key === 'skill' ? { ...one, difficulty: { default: 22, min: 20, max: 29 } } : one,
      ),
    }
    const [draft] = stepsToDrafts([{ ...checkSet, difficulty: undefined }])
    const [typed] = stepsToDrafts([{ ...checkSet, difficulty: 12 }])
    if (draft?.kind !== 'check' || typed?.kind !== 'check') {
      throw new Error('a check step was expected')
    }

    expect(checkDifficulty(draft, apart)).toMatchObject({ range: null, disjoint: true, show: true })
    expect(checkDifficulty(draft, stepPickers)).toMatchObject({
      range: { min: 9, max: 15 },
      disjoint: false,
    })
    // Empty is fine; a typed value is flagged, however it sits against each range.
    expect(stepGuides([draft], apart)[0]?.difficulty).toBeUndefined()
    expect(stepGuides([typed], apart)[0]?.difficulty).toBe(DISJOINT_DIFFICULTY)
  })

  it('flags an outcome for a band the shared bands lack', () => {
    const stored = { ...checkSet, outcomes: [{ band: 'ghost' }] }

    expect(guideOf(stored)?.items).toEqual({ 0: 'Not a band of this check.' })
  })
})

describe('a table by Scene Type', () => {
  const rows = (...pairs: [string, string][]) =>
    pairs.map(([sceneType, table]) => ({ sceneType, table }))
  const mapped = (bySceneType: ReturnType<typeof rows> | null, extra = {}) => ({
    ...draftOf(sceneTable, 'table'),
    bySceneType,
    ...extra,
  })

  it('is read as rows in the stored order and written as a map', () => {
    expect(draftOf(sceneTable, 'table').bySceneType).toEqual(
      rows(['hideout', 'places'], ['chase', 'moods']),
    )
    expect(draftOf(table, 'table').bySceneType).toBeNull()
    expect(stepsToItems([mapped(rows(['chase', 'moods']))])).toEqual({
      steps: [{ ...sceneTable, tableBySceneType: { chase: 'moods' } }],
    })
  })

  it('sends no map for a step without one, and a new table step has none', () => {
    expect(round(table)).toEqual({ steps: [table] })
    expect(newStep('table', [])).toMatchObject({ bySceneType: null })
  })

  it('keeps a map that is not text to text as stored', () => {
    expect(stepsToDrafts([{ ...sceneTable, tableBySceneType: { chase: 3 } }])[0]?.kind).toBe(
      'stored',
    )
    expect(stepsToDrafts([{ ...sceneTable, tableBySceneType: ['chase'] }])[0]?.kind).toBe('stored')
  })

  it.each([
    ['an empty map', rows(), 'Add a Scene Type, or turn the table by Scene Type off.'],
    [
      'a row with no Scene Type',
      rows(['', 'moods']),
      'Pick a Scene Type and a table for every row.',
    ],
    ['a row with no table', rows(['chase', '']), 'Pick a Scene Type and a table for every row.'],
    [
      'a Scene Type twice',
      rows(['chase', 'moods'], ['chase', 'places']),
      'Each Scene Type can appear once.',
    ],
  ])('refuses %s and sends nothing', (_, bySceneType, message) => {
    expect(stepsToItems([mapped(bySceneType)])).toEqual({
      errors: { '0.tableBySceneType': message },
    })
  })

  it('refuses branches with a map, and writes none', () => {
    const branches = draftOf(table, 'table').branches

    expect(stepsToItems([mapped(rows(['chase', 'moods']), { branches })])).toEqual({
      errors: {
        '0.branches': 'A table by Scene Type has no branches: delete them or turn the map off.',
      },
    })
  })

  it('writes the branches again once the map is off', () => {
    const branches = draftOf(table, 'table').branches

    expect(stepsToItems([mapped(null, { branches })])).toEqual({
      steps: [{ ...sceneTable, tableBySceneType: undefined, branches: table.branches }],
    })
  })

  const guides = (stored: Record<string, unknown>) =>
    stepGuides(stepsToDrafts([stored]), stepPickers)[0]?.scenes

  it('flags a Scene Type or table the game system lacks and a Scene Type given twice', () => {
    expect(guides(sceneTable)).toEqual({})
    expect(
      guides({
        ...sceneTable,
        tableBySceneType: { ghost: 'places', chase: 'nowhere' },
      }),
    ).toEqual({
      0: { sceneType: 'Not a Scene Type of this game system.' },
      1: { table: 'Not an oracle table of this game system.' },
    })
    expect(
      stepGuides([mapped(rows(['chase', 'moods'], ['chase', 'places']))], stepPickers)[0]
        ?.scenes[1],
    ).toEqual({ sceneType: 'Another row has this Scene Type.' })
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

  describe('of the branching kinds', () => {
    const guidesOf = (...stored: Record<string, unknown>[]) =>
      stepGuides(stepsToDrafts(stored), stepPickers)

    it('flags a level the chosen oracle does not have, and keeps it', () => {
      const guides = guidesOf(
        { key: 'a', kind: 'oracle', title: 'A', oracle: 'fate', likelihood: 'likely' },
        { key: 'b', kind: 'oracle', title: 'B', oracle: 'fate', likelihood: 'certain' },
        { key: 'c', kind: 'oracle', title: 'C', oracle: 'gone', likelihood: 'likely' },
        { key: 'd', kind: 'oracle', title: 'D', oracle: 'gone' },
      )

      expect(guides.map((guide) => guide.level !== undefined)).toEqual([false, true, true, false])
      expect(
        round({ key: 'b', kind: 'oracle', title: 'B', oracle: 'fate', likelihood: 'certain' }),
      ).toEqual({
        steps: [{ key: 'b', kind: 'oracle', title: 'B', oracle: 'fate', likelihood: 'certain' }],
      })
    })

    it('flags an entry used twice and one the table does not have', () => {
      const [guide] = guidesOf({
        ...table,
        branches: [{ entry: 'patrol' }, { entry: 'patrol' }, { entry: 'ghost' }, { entry: '' }],
      })

      expect(guide?.items).toEqual({
        1: 'Another branch has this entry.',
        2: 'Not an entry of this table.',
      })
    })

    it('flags repeated option keys, a skip naming no option and a suggested choice without skip', () => {
      const [repeated, gone, suggested, fine] = guidesOf(
        { ...choice, options: [choice.options[0], { key: 'left', label: 'Again' }] },
        { ...choice, skip: 'middle' },
        { ...choice, mandatory: false, skip: undefined },
        { ...choice, mandatory: true, skip: undefined },
      )

      expect(repeated?.items).toEqual({ 1: 'Another option has this key.' })
      expect(gone?.skip).toBe('No option has this key.')
      expect(suggested?.skip).toBe(
        'A suggested choice must name the option taken when it is skipped.',
      )
      expect(fine?.skip).toBeUndefined()
    })

    it('flags a next that goes backward in any outcome', () => {
      const back = 'Pick a later step: next only goes forward.'
      const later = { key: 'z', kind: 'character', title: 'Z' }
      const guides = guidesOf(
        { key: 'a', kind: 'character', title: 'A' },
        { ...oracle, key: 'o', branches: { yes: { next: 'a' }, exceptionalNo: { next: 'a' } } },
        {
          ...table,
          key: 't',
          branches: [{ entry: 'patrol', next: 'a' }],
          otherwise: { next: 'o' },
        },
        {
          ...choice,
          key: 'c',
          options: [
            { key: 'x', label: 'X', next: 'a' },
            { key: 'y', label: 'Y', next: 'z' },
          ],
        },
        later,
      )

      expect(guides[1]?.nexts).toEqual({ yes: back, exceptionalNo: back })
      expect(guides[2]?.nexts).toEqual({ 0: back, otherwise: back })
      expect(guides[3]?.nexts).toEqual({ 0: back })
    })
  })

  describe('of pick and check', () => {
    const guidesOf = (...stored: Record<string, unknown>[]) =>
      stepGuides(stepsToDrafts(stored), stepPickers)

    it('flags a pick count whose max is below its min, and keeps it', () => {
      const low = { ...pick, count: { min: 3, max: 2 } }
      const [fine, flagged] = guidesOf(pick, low)

      expect(fine?.count).toBeUndefined()
      expect(flagged?.count).toBe('Max must be at least min.')
      expect(round(low)).toEqual({ steps: [low] })
    })

    it('flags a check the game system does not have, and keeps it', () => {
      const gone = { ...check, check: 'gone', outcomes: [{ band: 'fail', next: 'end' }] }
      const [fine, flagged] = guidesOf(check, gone)

      expect(fine?.check).toBeUndefined()
      expect(flagged?.check).toBe('Not a check of this game system.')
      expect(round(gone)).toEqual({ steps: [gone] })
    })

    it('flags a difficulty outside the range of its check, or of a check with none', () => {
      const [inside, outside, tracker, none] = guidesOf(
        { ...check, difficulty: 12 },
        { ...check, difficulty: 40 },
        check,
        { ...check, check: 'luck', difficulty: 5 },
      )

      expect(inside?.difficulty).toBeUndefined()
      expect(outside?.difficulty).toBe('Outside the range of this check: 9 to 29.')
      expect(tracker?.difficulty).toBeUndefined()
      expect(none?.difficulty).toBe('This check declares no difficulty.')
    })

    it('flags outcomes for a band the check lacks and a band given twice', () => {
      const [guide] = guidesOf({
        ...check,
        outcomes: [{ band: 'fail' }, { band: 'ghost' }, { band: 'fail' }],
      })

      expect(guide?.items).toEqual({
        1: 'Not a band of this check.',
        2: 'Another outcome has this band.',
      })
    })

    it('flags a next that goes backward in a pick branch and a check outcome', () => {
      const back = 'Pick a later step: next only goes forward.'
      const guides = guidesOf(
        { key: 'a', kind: 'character', title: 'A' },
        { ...pick, key: 'p', branches: { found: { next: 'a' }, none: { next: 'z' } } },
        { ...check, key: 'k', outcomes: [{ band: 'fail', next: 'a' }, { band: 'success' }] },
        { key: 'z', kind: 'character', title: 'Z' },
      )

      expect(guides[1]?.nexts).toEqual({ found: back })
      expect(guides[2]?.nexts).toEqual({ 0: back })
    })
  })
})

describe('the Night Court world turn roll', () => {
  // The step as stored in backend/presets/vtm-night-court.json: a band bound to the Masquerade tracker, then the open band.
  const inquisition = {
    key: 'inquisition',
    kind: 'roll',
    title: 'Does the Second Inquisition move?',
    dice: '1d10',
    bands: [
      {
        upTo: { tracker: 'masquerade' },
        effects: [{ kind: 'nextScene', sceneType: 'inquisition-raid' }],
      },
      {},
    ],
  }

  it('is editable and saves back byte-identical', () => {
    const drafts = stepsToDrafts([inquisition])

    expect(drafts[0]?.kind).toBe('roll')
    expect(stepsToItems(drafts)).toEqual({ steps: [inquisition] })
  })

  it('is editable when the server sends the empty last band as an empty array', () => {
    const [draft] = stepsToDrafts([
      { ...inquisition, bands: [...inquisition.bands.slice(0, 1), []] },
    ])

    expect(draft?.kind).toBe('roll')
    // After a server save the canonical step comes back with the empty last band as {}.
    expect(stepsToItems(draft ? [draft] : [])).toEqual({ steps: [inquisition] })
  })
})
