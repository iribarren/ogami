import { gateNote, keepThreeRoll, skillCheckEntry, sneakCheckEntry } from '../journal/fixtures'
import { latestRoll } from './latestRoll'

describe('latestRoll', () => {
  it('is null for a journal with no roll', () => {
    expect(latestRoll([])).toBeNull()
    expect(latestRoll([gateNote])).toBeNull()
  })

  it('reads a free roll with the dice and the total the server returned', () => {
    const roll = latestRoll([gateNote, keepThreeRoll])

    expect(roll).toMatchObject({ id: keepThreeRoll.id, label: '4d6kh3+2', total: 17 })
    expect(roll?.groups[0]?.sides).toBe(6)
    expect(roll?.groups[0]?.dice).toHaveLength(4)
  })

  it('reads the roll of a check, labelled with the check, its total counting the modifier', () => {
    const roll = latestRoll([keepThreeRoll, skillCheckEntry])

    expect(roll).toMatchObject({ id: skillCheckEntry.id, label: 'Skill check: 1d10', total: 15 })
    expect(roll?.groups[0]?.dice).toEqual([{ value: 7, kept: true }])
  })

  it('shows the dice of a pool check, which has no dice groups', () => {
    const roll = latestRoll([sneakCheckEntry])

    expect(roll?.groups[0]).toMatchObject({ sides: 6, subtotal: 2 })
    expect(roll?.groups[0]?.dice.map(({ value }) => value)).toEqual([6, 2, 5])
  })

  it('is the last roll in recorded order, whatever follows it', () => {
    expect(latestRoll([skillCheckEntry, keepThreeRoll, gateNote])?.id).toBe(keepThreeRoll.id)
  })

  it('skips a check recorded without dice', () => {
    const diceless = {
      ...skillCheckEntry,
      id: 'no-dice',
      content: { ...skillCheckEntry.content, groups: [] },
    } as typeof skillCheckEntry

    expect(latestRoll([keepThreeRoll, diceless])?.id).toBe(keepThreeRoll.id)
  })
})
