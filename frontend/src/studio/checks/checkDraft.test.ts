import {
  addBand,
  bandPresets,
  blankCheck,
  duplicateCheck,
  editBandKey,
  editBandLabel,
  followBands,
  newBand,
  mergeBandErrors,
  readCheck,
  sameCheck,
  slugOf,
  switchMode,
  writeCheck,
  type BandDraft,
  type CheckDraft,
  type StoredCheck,
} from './checkDraft'
import { effectsToDrafts } from '../narrative/steps/effectDraft'

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

describe('comparing checks', () => {
  it('ignores the order of properties but not their values', () => {
    expect(
      sameCheck(
        { key: 'a', bands: [{ key: 'x', label: 'X' }], difficulty: { label: 'D', default: 1 } },
        { difficulty: { default: 1, label: 'D' }, bands: [{ label: 'X', key: 'x' }], key: 'a' },
      ),
    ).toBe(true)
    expect(sameCheck({ key: 'a', hint: 'h' }, { key: 'a' })).toBe(false)
    expect(sameCheck({ bands: [1, 2] }, { bands: [2, 1] })).toBe(false)
  })

  it('finds a check that the form leaves as stored unchanged', () => {
    const written = writeCheck(drafted(stored))

    expect('check' in written && sameCheck(written.check, stored)).toBe(true)
  })
})

describe('duplicating a check', () => {
  it('prefills a copy from the source with a key and label that mark it as one', () => {
    const copy = duplicateCheck(drafted(stored), ['skill'])

    expect(copy).toMatchObject({ key: 'skill-copy', label: 'Skill (copy)', dice: '1d10' })
    expect(copy.bands).toEqual(drafted(stored).bands)
    expect(copy.difficulty).toEqual(drafted(stored).difficulty)
  })

  it('takes the first free copy key among the existing ones', () => {
    const source = drafted(stored)

    expect(duplicateCheck(source, ['skill', 'skill-copy']).key).toBe('skill-copy-2')
    expect(duplicateCheck(source, ['skill', 'skill-copy', 'skill-copy-2']).key).toBe('skill-copy-3')
    // A gap is filled: the first free one wins, not the next after the highest.
    expect(duplicateCheck(source, ['skill-copy', 'skill-copy-3']).key).toBe('skill-copy-2')
  })

  it('does not share its bands with the source', () => {
    const source = drafted(stored)
    const copy = duplicateCheck(source, [])

    expect(copy.bands[0]).not.toBe(source.bands[0])
  })
})

describe('the band presets', () => {
  const preset = (id: string): BandDraft[] => {
    const found = bandPresets.find((candidate) => candidate.id === id)
    if (found === undefined) {
      throw new Error(`No preset ${id}.`)
    }
    return found.bands()
  }

  it('has Miss / partial / full with its two bounds and a last band without one', () => {
    expect(preset('miss-partial-full')).toEqual([
      { key: 'miss', label: 'Miss', upTo: { kind: 'number', value: '6' } },
      { key: 'partial', label: 'Partial hit', upTo: { kind: 'number', value: '9' } },
      { key: 'full', label: 'Full hit', upTo: { kind: 'none' } },
    ])
  })

  it('has Failure / success with an empty number bound the person must fill in', () => {
    expect(preset('failure-success')).toEqual([
      { key: 'failure', label: 'Failure', upTo: { kind: 'number', value: '' } },
      { key: 'success', label: 'Success', upTo: { kind: 'none' } },
    ])
  })

  it('gives each use its own bands', () => {
    expect(preset('miss-partial-full')[0]).not.toBe(preset('miss-partial-full')[0])
  })
})

describe('a band key that follows its label', () => {
  it('slugs a label into a key', () => {
    expect(slugOf(' Partial hit! ')).toBe('partial-hit')
    expect(slugOf('Success (2+)')).toBe('success-2')
  })

  it('follows the label of an added band until the key is edited by hand', () => {
    const labelled = editBandLabel(newBand(), 'Full hit')
    expect(labelled.key).toBe('full-hit')

    const edited = editBandKey(labelled, 'win')
    expect(editBandLabel(edited, 'Victory').key).toBe('win')
  })

  it('never rewrites the key of a stored band when its label changes', () => {
    const bands = drafted({
      key: 'a',
      label: 'A',
      dice: '1d6',
      bands: [{ key: 'outcome', label: 'Outcome' }],
    }).bands

    expect(bands.map((band) => editBandLabel(band, 'Result').key)).toEqual(['outcome'])
  })

  it('starts a new band as a number bound, and gives a last band without one a number too', () => {
    const { bands, order } = addBand([
      { key: 'a', label: 'A', upTo: { kind: 'number', value: '3' } },
      { key: 'b', label: 'B', upTo: { kind: 'none' } },
    ])

    expect(bands[1]?.upTo).toEqual({ kind: 'number', value: '' })
    expect(bands[2]).toMatchObject({ key: '', label: '', upTo: { kind: 'number', value: '' } })
    expect(order).toEqual([0, 1, null])
  })

  it('leaves a bound the old last band has alone', () => {
    const { bands } = addBand([{ key: 'a', label: 'A', upTo: { kind: 'tracker', value: 'heat' } }])

    expect(bands[0]?.upTo).toEqual({ kind: 'tracker', value: 'heat' })
  })
})

describe('a pool check', () => {
  const pushed = {
    label: 'Take a Condition',
    effects: [{ sheet: { field: 'stress', op: 'add', value: 1 } }],
  }
  const pooled: StoredCheck = {
    key: 'sneak',
    label: 'Sneak',
    pool: { count: 4, sides: 6, successAt: 5 },
    push: pushed,
    bands: [
      { key: 'fail', label: 'Fail', upTo: 0 },
      { key: 'win', label: 'Win' },
    ],
  }

  it('reads an integer count and a formula count into the one count input', () => {
    expect(drafted(pooled)).toMatchObject({
      dice: '',
      pool: { count: '4', sides: '6', successAt: '5' },
    })
    expect(
      drafted({ ...pooled, pool: { count: { formula: '{agility} + 1' }, sides: 6, successAt: 6 } }),
    ).toMatchObject({ pool: { count: '{agility} + 1', sides: '6', successAt: '6' } })
  })

  it('reads a dice check with no pool', () => {
    expect(drafted(stored).pool).toBeNull()
  })

  it.each([
    ['dice and a pool', { ...pooled, dice: '1d10' }],
    ['neither dice nor a pool', { key: 'k', label: 'L', bands: [] }],
    ['a modifier', { ...pooled, modifier: '{pow}' }],
    ['a push without a pool', { ...stored, push: pushed }],
    ['a pool that is not an object', { ...pooled, pool: 4 }],
    [
      'a pool with another property',
      { ...pooled, pool: { count: 4, sides: 6, successAt: 5, x: 1 } },
    ],
    ['a count of another shape', { ...pooled, pool: { count: '4', sides: 6, successAt: 5 } }],
    [
      'a formula count with another property',
      { ...pooled, pool: { count: { formula: 'a', x: 1 }, sides: 6, successAt: 5 } },
    ],
    [
      'sides that are not a whole number',
      { ...pooled, pool: { count: 4, sides: 6.5, successAt: 5 } },
    ],
    ['no success threshold', { ...pooled, pool: { count: 4, sides: 6 } }],
  ])('cannot hold %s', (_name, check) => {
    expect(readCheck(check)).toBeNull()
  })

  it('round trips a pool check with an integer count, its push kept as stored', () => {
    const result = writeCheck(drafted(pooled))

    expect(result).toEqual({ check: pooled })
    expect(JSON.stringify((result as { check: StoredCheck }).check.push)).toBe(
      JSON.stringify(pushed),
    )
  })

  it('round trips a pool check with a formula count and no push', () => {
    const formula: StoredCheck = {
      ...pooled,
      pool: { count: { formula: '{agility} + {stealth}' }, sides: 10, successAt: 8 },
    }
    delete formula.push

    const result = writeCheck(drafted(formula))

    expect(result).toEqual({ check: formula })
    expect(result).not.toHaveProperty('check.push')
    expect(result).not.toHaveProperty('check.dice')
    expect(result).not.toHaveProperty('check.modifier')
  })

  it('sends the count as an integer when digits are typed and as a formula otherwise', () => {
    const typed = (count: string) => {
      const draft = drafted(pooled)
      const result = writeCheck({ ...draft, pool: { count, sides: '6', successAt: '5' } })
      return 'check' in result ? result.check.pool : result.errors
    }

    expect(typed(' 3 ')).toEqual({ count: 3, sides: 6, successAt: 5 })
    expect(typed(' {hands} ')).toEqual({ count: { formula: '{hands}' }, sides: 6, successAt: 5 })
  })

  it('refuses what the publisher would, with a message for each input', () => {
    const errors = (pool: { count: string; sides: string; successAt: string }) => {
      const result = writeCheck({ ...drafted(pooled), pool })
      return 'errors' in result ? result.errors : null
    }

    expect(errors({ count: '', sides: '', successAt: '' })).toMatchObject({
      poolCount: 'Give the pool a count.',
      poolSides: 'Give the pool its sides.',
      poolSuccessAt: 'Give the success threshold.',
    })
    expect(errors({ count: '0', sides: '6', successAt: '5' })).toMatchObject({
      poolCount: 'The count must be between 1 and 100, or a formula.',
    })
    expect(errors({ count: '101', sides: '6', successAt: '5' })?.poolCount).toContain('1 and 100')
    expect(errors({ count: 'x'.repeat(201), sides: '6', successAt: '5' })).toMatchObject({
      poolCount: 'The count formula is at most 200 characters.',
    })
    expect(errors({ count: '4', sides: '1', successAt: '1' })).toMatchObject({
      poolSides: 'Must be between 2 and 1000.',
    })
    expect(errors({ count: '4', sides: '1001', successAt: '5' })?.poolSides).toBe(
      'Must be between 2 and 1000.',
    )
    expect(errors({ count: '4', sides: 'six', successAt: '5' })?.poolSides).toBe(
      'Must be a whole number.',
    )
    expect(errors({ count: '4', sides: '6', successAt: '7' })).toMatchObject({
      poolSuccessAt: 'Must be between 1 and 6.',
    })
    expect(errors({ count: '4', sides: '6', successAt: '0' })?.poolSuccessAt).toBe(
      'Must be between 1 and 6.',
    )
    expect(errors({ count: '4', sides: '6', successAt: '5' })).toBeNull()
  })

  it('does not ask for dice in pool mode, nor for a pool in dice mode', () => {
    const asPool = writeCheck({ ...drafted(pooled), dice: '' })
    const asDice = writeCheck({ ...drafted(stored), dice: '' })

    expect(asPool).not.toHaveProperty('errors.dice')
    expect(asDice).toHaveProperty('errors.dice', 'Write the dice.')
    expect(asDice).not.toHaveProperty('errors.poolCount')
  })

  it('starts a pool empty when switching from dice, and drops the pool and its push going back', () => {
    const toPool = switchMode(drafted(stored), 'pool')
    expect(toPool.pool).toEqual({ count: '', sides: '', successAt: '' })

    const toDice = switchMode({ ...drafted(pooled), dice: '1d10' }, 'dice')
    expect(toDice.pool).toBeNull()
    expect(toDice.push).toBeNull()
    expect(writeCheck(toDice)).toHaveProperty('check.dice', '1d10')
    expect(writeCheck(toDice)).not.toHaveProperty('check.push')
    expect(writeCheck(toDice)).not.toHaveProperty('check.pool')
  })

  it('keeps the pool and its push when the mode is chosen again', () => {
    const draft = drafted(pooled)

    expect(switchMode(draft, 'pool')).toBe(draft)
  })

  it('reads a push into a label and its effects, keeping an effect it cannot edit as stored', () => {
    const draft = drafted({
      ...pooled,
      push: {
        label: 'Take a Condition',
        effects: [
          { kind: 'sheet', field: 'physical', op: 'mark' },
          { kind: 'tracker', tracker: 'dread', op: 'add', value: 1 },
          { kind: 'weird' },
        ],
      },
    })

    expect(draft.push?.label).toBe('Take a Condition')
    expect(draft.push?.effects.map((effect) => effect.kind)).toEqual(['sheet', 'tracker', 'stored'])
    expect(draft.push?.effects[0]).toMatchObject({ field: 'physical', op: 'mark', option: '' })
  })

  it.each([
    ['a push that is not an object', { ...pooled, push: 'Pay' }],
    ['a push without a label', { ...pooled, push: { effects: [] } }],
    ['a push label that is not text', { ...pooled, push: { label: 3 } }],
    ['push effects that are not a list', { ...pooled, push: { label: 'Pay', effects: 'none' } }],
    ['a push with another property', { ...pooled, push: { label: 'Pay', extra: 1 } }],
  ])('cannot hold %s', (_name, check) => {
    expect(readCheck(check)).toBeNull()
  })

  it('round trips a push with a label only, with effects of every shape of the cost, and with none', () => {
    const effects = [
      { kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' },
      { kind: 'sheet', field: 'physical', op: 'mark' },
      { kind: 'sheet', field: 'mental', op: 'clear' },
      { kind: 'sheet', field: 'physical', op: 'clear', value: 'hurt' },
      { kind: 'sheet', field: 'agility', op: 'subtract', value: { formula: '{agility} / 2' } },
      { kind: 'tracker', tracker: 'dread', op: 'add', value: 1 },
    ]
    for (const push of [{ label: 'Pay' }, { label: 'Take a Condition', effects }]) {
      const check = { ...pooled, push }

      expect(writeCheck(drafted(check))).toEqual({ check })
    }
  })

  it('writes the push label trimmed, and leaves empty effects out', () => {
    const draft = drafted(pooled)
    draft.push = { label: '  Take a Condition ', effects: [] }

    expect(writeCheck(draft)).toHaveProperty('check.push', { label: 'Take a Condition' })
  })

  it('asks for the cost label, within 100 characters, and for whole numbers in the effects', () => {
    const draft = drafted(pooled)
    draft.push = { label: ' ', effects: [] }
    expect(writeCheck(draft)).toHaveProperty('errors.pushLabel', 'Give the push a cost label.')

    draft.push = { label: 'x'.repeat(101), effects: [] }
    expect(writeCheck(draft)).toHaveProperty(
      'errors.pushLabel',
      'The label is at most 100 characters.',
    )

    const [effect] = effectsToDrafts([{ kind: 'tracker', tracker: 'dread', op: 'add', value: 1 }])
    if (effect?.kind !== 'tracker') {
      throw new Error('Expected a tracker effect.')
    }
    draft.push = { label: 'Pay', effects: [{ ...effect, value: { form: 'integer', text: 'x' } }] }
    const result = writeCheck(draft)
    expect(result).toHaveProperty('errors.pushEffects', [
      { index: null, band: null, effect: 0, field: 'value', message: 'Use a whole number.' },
    ])
    expect(result).not.toHaveProperty('errors.pushLabel')
  })

  it('writes no push for a check that is not a pool check, and a duplicate does not share its effects', () => {
    const dice = drafted(stored)
    expect(dice.push).toBeNull()
    expect(writeCheck({ ...dice, push: { label: 'Pay', effects: [] } })).not.toHaveProperty(
      'check.push',
    )

    const source = drafted({ ...pooled, push: { label: 'Pay', effects: [{ kind: 'endPhase' }] } })
    const copy = duplicateCheck(source, [])
    copy.push?.effects.push(...effectsToDrafts([{ kind: 'endPhase' }]))
    expect(source.push?.effects).toHaveLength(1)
  })

  it('keeps a stored null push on a pool check until the check is given one', () => {
    const nulled: StoredCheck = { ...pooled, push: null }
    const draft = drafted(nulled)

    expect(draft.push).toBeNull()
    expect(writeCheck(draft)).toEqual({ check: nulled })

    const given = writeCheck({ ...draft, push: { label: 'Pay', effects: [] } })
    expect(given).toHaveProperty('check.push', { label: 'Pay' })
    expect(writeCheck(switchMode(draft, 'dice'))).not.toHaveProperty('check.push')
  })

  it('writes no push for a pool check that had none stored', () => {
    const bare = { ...pooled, push: undefined }
    expect(writeCheck(drafted(bare))).not.toHaveProperty('check.push')
  })

  it('lets a push cost mark without an option, the player picks it', () => {
    const draft = drafted(pooled)
    draft.push = {
      label: 'Pay',
      effects: effectsToDrafts([{ kind: 'sheet', field: 'physical', op: 'mark' }]),
    }

    expect(writeCheck(draft)).toHaveProperty('check.push.effects', [
      { kind: 'sheet', field: 'physical', op: 'mark' },
    ])
  })

  it('leaves a dice check as it was, with no pool or push written', () => {
    const result = writeCheck(drafted(stored))

    expect(result).toEqual({ check: stored })
    expect(result).not.toHaveProperty('check.pool')
    expect(result).not.toHaveProperty('check.push')
  })
})
