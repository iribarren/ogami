import {
  blankCheck,
  followBands,
  mergeBandErrors,
  readCheck,
  writeCheck,
  type CheckDraft,
  type StoredCheck,
} from './checkDraft'

const stored: StoredCheck = {
  key: 'skill',
  label: 'Skill',
  hint: 'Roll under',
  dice: '1d10',
  modifier: '{pow}',
  difficulty: { label: 'Difficulty', default: 13, min: 9, max: 29 },
  bands: [
    { key: 'fail', label: 'Fail', upTo: { formula: '{check:difficulty}-1' } },
    { key: 'win', label: 'Win' },
  ],
}

function drafted(check: StoredCheck): CheckDraft {
  const draft = readCheck(check)
  if (draft === null) {
    throw new Error('Expected a readable check.')
  }
  return draft
}

describe('readCheck', () => {
  it('reads a check into text inputs', () => {
    expect(drafted(stored)).toMatchObject({
      key: 'skill',
      label: 'Skill',
      hint: 'Roll under',
      dice: '1d10',
      modifier: '{pow}',
      difficulty: { label: 'Difficulty', default: '13', min: '9', max: '29' },
    })
  })

  it('reads a check without optionals, a null optional as absent', () => {
    const draft = drafted({
      key: 'luck',
      label: 'Luck',
      dice: 'd%',
      hint: null,
      modifier: null,
      bands: [{ key: 'only', label: 'Only' }],
    })

    expect(draft).toMatchObject({ hint: '', modifier: '', difficulty: null })
  })

  it.each([
    ['a key that is not text', { ...stored, key: 7 }],
    ['dice that are not text', { ...stored, dice: 6 }],
    ['a hint that is a number', { ...stored, hint: 5 }],
    ['a difficulty that is text', { ...stored, difficulty: 'hard' }],
    [
      'a difficulty default that is not an integer',
      { ...stored, difficulty: { label: 'D', default: 1.5 } },
    ],
    ['bands that are not a list', { ...stored, bands: 'hit' }],
  ])('cannot hold %s', (_, check) => {
    expect(readCheck(check)).toBeNull()
  })
})

describe('writeCheck', () => {
  it('round trips a stored check unchanged, bands included', () => {
    expect(writeCheck(drafted(stored))).toEqual({ check: stored })
  })

  it('round trips a check stored before the editor: tracker and formula bounds, no difficulty', () => {
    const legacy: StoredCheck = {
      key: 'luck',
      label: 'Luck',
      dice: 'd%',
      bands: [
        { key: 'bad', label: 'Bad', upTo: { tracker: 'alarm' } },
        { key: 'mid', label: 'Mid', upTo: 40 },
        { key: 'good', label: 'Good' },
      ],
    }

    const result = writeCheck(drafted(legacy))

    expect(result).toEqual({ check: legacy })
    expect(result).not.toHaveProperty('check.difficulty')
  })

  it('omits empty optionals and trims text', () => {
    const draft = { ...blankCheck(), key: ' grit ', label: ' Grit ', dice: ' 2d6 ', hint: '  ' }

    expect(writeCheck(draft)).toEqual({
      check: {
        key: 'grit',
        label: 'Grit',
        dice: '2d6',
        bands: [{ key: 'outcome', label: 'Outcome' }],
      },
    })
  })

  it('leaves out an empty difficulty min and max', () => {
    const draft = {
      ...drafted(stored),
      difficulty: { label: 'Level', default: '4', min: '', max: ' ' },
    }

    expect(writeCheck(draft)).toMatchObject({
      check: { difficulty: { label: 'Level', default: 4 } },
    })
    expect(writeCheck(draft)).not.toHaveProperty('check.difficulty.min')
    expect(writeCheck(draft)).not.toHaveProperty('check.difficulty.max')
  })

  it('asks for what the check needs', () => {
    expect(writeCheck(blankCheck())).toEqual({
      errors: {
        key: 'Give the check a key.',
        label: 'Give the check a label.',
        dice: 'Write the dice.',
      },
    })
  })

  it.each(['1.5', 'ten', '1e3', '--2', '9007199254740993'])(
    'keeps the difficulty text %s as a client error, never NaN',
    (text) => {
      const draft = {
        ...drafted(stored),
        difficulty: { label: 'D', default: text, min: text, max: text },
      }

      expect(writeCheck(draft)).toEqual({
        errors: {
          difficultyDefault: 'Must be a whole number.',
          difficultyMin: 'Must be a whole number.',
          difficultyMax: 'Must be a whole number.',
        },
      })
    },
  )

  it('asks for a difficulty label and default once the block is on', () => {
    const draft = {
      ...drafted(stored),
      difficulty: { label: '', default: '', min: '', max: '' },
    }

    expect(writeCheck(draft)).toEqual({
      errors: {
        difficultyLabel: 'Give the difficulty a label.',
        difficultyDefault: 'Give the difficulty a default.',
      },
    })
  })
})

describe('the bands', () => {
  const withBands = (bands: unknown[]): StoredCheck => ({ ...stored, bands })

  it('reads every kind of bound into the form', () => {
    expect(
      drafted(
        withBands([
          { key: 'a', label: 'A', upTo: 7 },
          { key: 'b', label: 'B', upTo: { tracker: 'alarm' } },
          { key: 'c', label: 'C', upTo: { formula: '{pow}+1' } },
          { key: 'd', label: 'D' },
        ]),
      ).bands,
    ).toEqual([
      { key: 'a', label: 'A', upTo: { kind: 'number', value: '7' } },
      { key: 'b', label: 'B', upTo: { kind: 'tracker', value: 'alarm' } },
      { key: 'c', label: 'C', upTo: { kind: 'formula', value: '{pow}+1' } },
      { key: 'd', label: 'D', upTo: { kind: 'none' } },
    ])
  })

  it.each([
    ['a number', 7],
    ['a negative number', -3],
    ['a tracker, known or not', { tracker: 'gone' }],
    ['a formula', { formula: '{check:difficulty}+4' }],
  ])('round trips a stored bound that is %s', (_, upTo) => {
    const check = withBands([
      { key: 'a', label: 'A', upTo },
      { key: 'b', label: 'B' },
    ])

    expect(writeCheck(drafted(check))).toEqual({ check })
  })

  it.each([
    ['a fraction', 2.5],
    ['text', 'high'],
    ['a tracker that is not text', { tracker: 3 }],
    ['an object with another property', { tracker: 'a', formula: 'b' }],
    ['an empty object', {}],
  ])('keeps a bound that is %s as stored, never dropping it', (_, upTo) => {
    const check = withBands([
      { key: 'a', label: 'A', upTo },
      { key: 'b', label: 'B' },
    ])

    expect(drafted(check).bands[0]?.upTo).toEqual({ kind: 'unknown', raw: upTo })
    expect(writeCheck(drafted(check))).toEqual({ check })
  })

  it.each([
    ['a band that is not an object', ['hit']],
    ['a band without a label', [{ key: 'a' }]],
    ['a band with a property of its own', [{ key: 'a', label: 'A', colour: 'red' }]],
  ])('cannot hold %s', (_, bands) => {
    expect(readCheck(withBands(bands))).toBeNull()
  })

  it('leaves out the bound of the last band, even one it kept from moving', () => {
    const draft = drafted(withBands([{ key: 'a', label: 'A' }]))
    draft.bands = [
      { key: 'a', label: 'A', upTo: { kind: 'none' } },
      { key: 'b', label: 'B', upTo: { kind: 'number', value: '9' } },
    ]
    draft.bands.reverse()

    expect(writeCheck(draft)).toMatchObject({
      check: {
        bands: [
          { key: 'b', label: 'B', upTo: 9 },
          { key: 'a', label: 'A' },
        ],
      },
    })
    expect(writeCheck(draft)).not.toHaveProperty('check.bands.1.upTo')
  })

  it('keeps the bands in the order of the form, with no bound dropped but the last', () => {
    const draft = drafted(stored)
    draft.bands = [
      { key: 'one', label: 'One', upTo: { kind: 'number', value: '1' } },
      { key: 'two', label: 'Two', upTo: { kind: 'formula', value: ' {pow} ' } },
      { key: 'three', label: 'Three', upTo: { kind: 'tracker', value: 'heat' } },
    ]

    expect(writeCheck(draft)).toMatchObject({
      check: {
        bands: [
          { key: 'one', label: 'One', upTo: 1 },
          { key: 'two', label: 'Two', upTo: { formula: '{pow}' } },
          { key: 'three', label: 'Three' },
        ],
      },
    })
  })

  it('asks for what each band needs, by position', () => {
    const draft = drafted(stored)
    draft.bands = [
      { key: '', label: ' ', upTo: { kind: 'none' } },
      { key: 'b', label: 'B', upTo: { kind: 'number', value: '' } },
      { key: 'c', label: 'C', upTo: { kind: 'tracker', value: '' } },
      { key: 'd', label: 'D', upTo: { kind: 'formula', value: ' ' } },
      { key: 'e', label: 'E', upTo: { kind: 'none' } },
    ]

    expect(writeCheck(draft)).toEqual({
      errors: {
        band: {
          0: {
            key: 'Give the band a key.',
            label: 'Give the band a label.',
            upTo: 'Choose a bound: only the last band catches the rest.',
          },
          1: { upTo: 'Give the band a number.' },
          2: { upTo: 'Choose a tracker.' },
          3: { upTo: 'Write the formula.' },
        },
      },
    })
  })

  it.each(['1.5', 'ten', '1e3', '9007199254740993'])(
    'refuses the band number %s instead of sending NaN',
    (value) => {
      const draft = drafted(stored)
      draft.bands = [
        { key: 'a', label: 'A', upTo: { kind: 'number', value } },
        { key: 'b', label: 'B', upTo: { kind: 'none' } },
      ]

      expect(writeCheck(draft)).toEqual({
        errors: { band: { 0: { upTo: 'Must be a whole number.' } } },
      })
    },
  )

  it('refuses no bands and more than ten', () => {
    const draft = drafted(stored)
    draft.bands = []
    expect(writeCheck(draft)).toEqual({ errors: { bands: 'A check has 1 to 10 bands.' } })

    draft.bands = Array.from({ length: 11 }, (_, index) => ({
      key: `b${String(index)}`,
      label: 'B',
      upTo: { kind: 'number' as const, value: String(index) },
    }))
    expect(writeCheck(draft)).toEqual({ errors: { bands: 'A check has 1 to 10 bands.' } })
  })
})

describe('the band messages', () => {
  const errors = { 0: { key: 'a' }, 2: { label: 'c' } }

  it('follow a band that moved, drop a removed one and leave a new band clean', () => {
    // Band 2 moved up to the front.
    expect(followBands(errors, [2, 0, 1])).toEqual({ 0: { label: 'c' }, 1: { key: 'a' } })
    // Band 0 removed: the later ones shift.
    expect(followBands(errors, [1, 2])).toEqual({ 1: { label: 'c' } })
    // A band added at the end.
    expect(followBands(errors, [0, 1, 2, null])).toEqual(errors)
  })

  it('merge field by field, the later one winning only for the same input', () => {
    expect(
      mergeBandErrors({ 0: { label: 'own', key: 'old' }, 1: { key: 'x' } }, { 0: { key: 'new' } }),
    ).toEqual({
      0: { label: 'own', key: 'new' },
      1: { key: 'x' },
    })
  })
})
