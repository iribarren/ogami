import {
  blankCheck,
  describeBands,
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

describe('describeBands', () => {
  it('says each band bound as stored', () => {
    expect(
      describeBands([
        { key: 'a', label: 'A', upTo: 7 },
        { key: 'b', label: 'B', upTo: { tracker: 'alarm' } },
        { key: 'c', label: 'C', upTo: { formula: '{pow}+1' } },
        { key: 'd', label: 'D' },
        'odd',
      ]),
    ).toEqual([
      { key: 'a', label: 'A', bound: 'up to 7' },
      { key: 'b', label: 'B', bound: 'up to the tracker alarm' },
      { key: 'c', label: 'C', bound: 'up to {pow}+1' },
      { key: 'd', label: 'D', bound: 'catches the rest' },
      { key: '?', label: '?', bound: 'unreadable' },
    ])
  })
})
