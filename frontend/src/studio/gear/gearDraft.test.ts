import { readGear, withName, writeGear, blankGear, type GearDraft } from './gearDraft'

const medkit = {
  key: 'medkit',
  name: 'Medkit',
  cost: 20,
  slots: 2,
  category: 'Medical',
  description: null,
}

const typed = (changes: Partial<GearDraft>): GearDraft => ({
  ...blankGear,
  key: 'rope',
  name: 'Rope',
  cost: '5',
  ...changes,
})

describe('reading an entry', () => {
  it('starts a new entry blank and an existing one from the whole entry', () => {
    expect(readGear(null)).toEqual(blankGear)
    expect(readGear(medkit)).toEqual({
      key: 'medkit',
      follow: false,
      name: 'Medkit',
      cost: '20',
      slots: '2',
      category: 'Medical',
      description: '',
    })
  })

  it('writes back the entry it read, key aside', () => {
    expect(writeGear(readGear(medkit), [])).toEqual({
      request: { name: 'Medkit', cost: 20, slots: 2, category: 'Medical' },
    })
  })
})

describe('writing an entry', () => {
  it('leaves out what is empty and trims the rest', () => {
    expect(
      writeGear(typed({ name: '  Rope ', category: ' Tools ', description: ' Hemp ' }), []),
    ).toEqual({
      request: { name: 'Rope', cost: 5, category: 'Tools', description: 'Hemp' },
    })
  })

  it('accepts a cost of 0 and slots of 0', () => {
    expect(writeGear(typed({ cost: '0', slots: '0' }), [])).toEqual({
      request: { name: 'Rope', cost: 0, slots: 0 },
    })
  })

  it('refuses a bad key, a taken key, a blank name and out of range numbers', () => {
    const result = writeGear(
      typed({ key: 'Bad Key', name: ' ', cost: '1000001', slots: '101', category: 'x'.repeat(41) }),
      [],
    )

    expect('errors' in result ? Object.keys(result.errors).sort() : []).toEqual([
      'category',
      'cost',
      'key',
      'name',
      'slots',
    ])
    expect(result).toHaveProperty('errors.key', expect.stringContaining('lower case') as string)
    expect(writeGear(typed({}), ['rope'])).toEqual({
      errors: { key: 'Another entry uses this key.' },
    })
  })

  it('refuses a cost that is empty, negative or not whole', () => {
    for (const cost of ['', '-1', '2.5', 'ten']) {
      expect(writeGear(typed({ cost }), [])).toHaveProperty('errors.cost')
    }
  })

  it('refuses a description over 500 characters', () => {
    expect(writeGear(typed({ description: 'x'.repeat(501) }), [])).toHaveProperty(
      'errors.description',
    )
  })
})

describe('the key of a new entry', () => {
  it('follows the name until it is typed', () => {
    expect(withName(blankGear, 'Used pistol').key).toBe('used-pistol')
    expect(withName({ ...blankGear, key: 'mine', follow: false }, 'Used pistol').key).toBe('mine')
  })
})
