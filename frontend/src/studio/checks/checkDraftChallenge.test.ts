import {
  blankCheck,
  editChallenge,
  modeOf,
  readCheck,
  sameCheck,
  switchMode,
  writeCheck,
  type CheckDraft,
  type StoredCheck,
} from './checkDraft'

const action: StoredCheck = {
  key: 'edge',
  label: 'Edge',
  dice: '1d6',
  modifier: '{edge}',
  challenge: { count: 2, sides: 10, scoreMax: 10, momentum: 'momentum' },
  push: {
    label: 'Burn momentum',
    effects: [{ kind: 'sheet', field: 'momentum', set: { formula: '0' } }],
  },
  bands: [
    { key: 'miss', label: 'Miss', upTo: 0 },
    { key: 'weak', label: 'Weak hit', upTo: 1 },
    { key: 'strong', label: 'Strong hit' },
  ],
}

const progress: StoredCheck = {
  key: 'fulfill',
  label: 'Fulfill a vow',
  modifier: '{vow}/4',
  challenge: { count: 2, sides: 10 },
  bands: [
    { key: 'miss', label: 'Miss', upTo: 0 },
    { key: 'hit', label: 'Hit' },
  ],
}

function drafted(check: StoredCheck): CheckDraft {
  const draft = readCheck(check)
  if (draft === null) {
    throw new Error('Expected a readable check.')
  }
  return draft
}

describe('reading a challenge check', () => {
  it('reads an action roll with its momentum and burn', () => {
    const draft = drafted(action)

    expect(modeOf(draft)).toBe('challenge')
    expect(draft.challenge).toEqual({
      count: '2',
      sides: '10',
      scoreMax: '10',
      momentum: 'momentum',
      kind: 'action',
    })
    expect(draft.dice).toBe('1d6')
    expect(draft.push?.label).toBe('Burn momentum')
  })

  it('reads a check with no dice as a progress roll', () => {
    const draft = drafted(progress)

    expect(draft.challenge).toEqual({
      count: '2',
      sides: '10',
      scoreMax: '',
      momentum: '',
      kind: 'progress',
    })
    expect(draft.dice).toBe('')
  })

  it('reads a null challenge as not set, and a dice check as before', () => {
    const draft = drafted({ ...stored(), challenge: null })

    expect(modeOf(draft)).toBe('dice')
    expect(draft.storedNullChallenge).toBe(true)
  })

  it('cannot hold a challenge with unknown properties, a pool beside it, or odd dice', () => {
    expect(readCheck({ ...action, challenge: { count: 2, sides: 10, wild: 1 } })).toBeNull()
    expect(readCheck({ ...action, challenge: { count: '2', sides: 10 } })).toBeNull()
    expect(readCheck({ ...action, challenge: { count: 2, sides: 10, momentum: 4 } })).toBeNull()
    expect(readCheck({ ...action, pool: { count: 2, sides: 6, successAt: 5 } })).toBeNull()
    expect(readCheck({ ...action, dice: 7 })).toBeNull()
    expect(readCheck({ ...action, challenge: [] })).toBeNull()
  })
})

describe('writing a challenge check', () => {
  it('round trips an action roll with a burn and a progress roll unchanged', () => {
    for (const check of [action, progress]) {
      const result = writeCheck(drafted(check))

      expect(result).toHaveProperty('check')
      expect('check' in result && sameCheck(result.check, check)).toBe(true)
    }
  })

  it('writes a challenge: null back while the check has none', () => {
    const check = { ...stored(), challenge: null }
    const result = writeCheck(drafted(check))

    expect('check' in result && sameCheck(result.check, check)).toBe(true)
  })

  it('sends no dice for a progress roll and no difficulty for any challenge check', () => {
    const draft = {
      ...drafted(progress),
      dice: '1d6',
      difficulty: { label: 'Difficulty', default: '3', min: '', max: '' },
    }
    const result = writeCheck(draft)

    expect(result).toHaveProperty('check')
    if ('check' in result) {
      expect(result.check).not.toHaveProperty('dice')
      expect(result.check).not.toHaveProperty('difficulty')
    }
  })

  it('omits an empty cap and momentum, and trims the numbers', () => {
    const draft = drafted(action)
    const result = writeCheck({
      ...draft,
      push: null,
      challenge: { count: ' 3 ', sides: '8', scoreMax: '', momentum: '', kind: 'action' },
    })

    expect('check' in result && result.check.challenge).toEqual({ count: 3, sides: 8 })
  })

  it('asks for the count and sides of the limits of the publisher', () => {
    const draft = drafted(action)
    const write = (count: string, sides: string, scoreMax: string) =>
      writeCheck({
        ...draft,
        challenge: { count, sides, scoreMax, momentum: 'momentum', kind: 'action' },
      })

    expect(write('', '', '')).toHaveProperty('errors.challengeCount', 'Give the count of dice.')
    expect(write('1', '10', '')).toHaveProperty('errors.challengeCount')
    expect(write('11', '10', '')).toHaveProperty('errors.challengeCount')
    expect(write('2.5', '10', '')).toHaveProperty('errors.challengeCount')
    expect(write('2', '1', '')).toHaveProperty('errors.challengeSides')
    expect(write('2', '1001', '')).toHaveProperty('errors.challengeSides')
    expect(write('2', '', '')).toHaveProperty('errors.challengeSides', 'Give the sides.')
    expect(write('2', '10', '0')).toHaveProperty('errors.challengeScoreMax')
    expect(write('2', '10', '1001')).toHaveProperty('errors.challengeScoreMax')
    expect(write('2', '10', 'x')).toHaveProperty('errors.challengeScoreMax')
    expect(write('10', '1000', '1000')).toHaveProperty('check')
    expect(write('2', '2', '1')).toHaveProperty('check')
  })

  it('asks for the dice of an action roll and the modifier of a progress roll', () => {
    expect(writeCheck({ ...drafted(action), dice: ' ' })).toHaveProperty('errors.dice')
    expect(writeCheck({ ...drafted(progress), modifier: ' ' })).toHaveProperty(
      'errors.modifier',
      'Give the modifier: it is the score of a progress roll.',
    )
  })

  it('sends the burn only on an action roll with a momentum', () => {
    const draft = drafted(action)
    const noMomentum = { ...draft, challenge: { ...(draft.challenge ?? never()), momentum: '' } }

    expect(writeCheck(noMomentum)).not.toHaveProperty('check.push')
    expect(writeCheck(draft)).toHaveProperty('check.push.label', 'Burn momentum')
  })

  it('asks for the cost label of a burn', () => {
    const draft = drafted(action)

    expect(writeCheck({ ...draft, push: { label: ' ', effects: [] } })).toHaveProperty(
      'errors.pushLabel',
    )
  })
})

describe('editing a challenge', () => {
  it('starts empty as an action roll, with no difficulty, pool or push', () => {
    const draft = switchMode(
      { ...blankCheck(), difficulty: { label: 'D', default: '1', min: '', max: '' } },
      'challenge',
    )

    expect(draft.challenge).toEqual({
      count: '',
      sides: '',
      scoreMax: '',
      momentum: '',
      kind: 'action',
    })
    expect(draft.difficulty).toBeNull()
    expect(draft.pool).toBeNull()
    expect(draft.push).toBeNull()
  })

  it('drops the challenge and its burn on switching to dice or to a pool', () => {
    const challenged = drafted(action)

    const dice = switchMode(challenged, 'dice')
    expect(modeOf(dice)).toBe('dice')
    expect(dice.challenge).toBeNull()
    expect(dice.push).toBeNull()

    const pool = switchMode(challenged, 'pool')
    expect(modeOf(pool)).toBe('pool')
    expect(pool.challenge).toBeNull()
    expect(pool.push).toBeNull()
  })

  it('drops the challenge, the dice-only fields and the burn when a pool becomes a challenge', () => {
    const pooled = drafted({
      key: 'p',
      label: 'P',
      pool: { count: 2, sides: 6, successAt: 5 },
      push: { label: 'Pay' },
      bands: [{ key: 'win', label: 'Win' }],
    })

    const draft = switchMode(pooled, 'challenge')

    expect(draft.pool).toBeNull()
    expect(draft.push).toBeNull()
  })

  it('clears the dice, the momentum and the burn on switching to a progress roll', () => {
    const draft = editChallenge(drafted(action), { kind: 'progress' })

    expect(draft.dice).toBe('')
    expect(draft.challenge?.momentum).toBe('')
    expect(draft.push).toBeNull()
    expect(draft.challenge?.kind).toBe('progress')
  })

  it('does not bring back what a progress roll dropped on switching to an action roll again', () => {
    const draft = editChallenge(editChallenge(drafted(action), { kind: 'progress' }), {
      kind: 'action',
    })

    expect(draft.challenge?.kind).toBe('action')
    expect(draft.dice).toBe('')
    expect(draft.push).toBeNull()
  })

  it('drops the burn when the momentum is cleared, and keeps it on another momentum', () => {
    const kept = editChallenge(drafted(action), { momentum: 'grit' })
    expect(kept.push?.label).toBe('Burn momentum')

    const cleared = editChallenge(drafted(action), { momentum: '' })
    expect(cleared.push).toBeNull()
    expect(cleared.storedNullPush).toBeFalsy()
  })

  it('changes nothing in a draft without a challenge', () => {
    const draft = blankCheck()

    expect(editChallenge(draft, { count: '3' })).toBe(draft)
  })
})

function stored(): StoredCheck {
  return {
    key: 'skill',
    label: 'Skill',
    dice: '1d10',
    bands: [{ key: 'win', label: 'Win' }],
  }
}

function never(): never {
  throw new Error('Expected a challenge.')
}
