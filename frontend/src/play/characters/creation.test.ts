import { outOfRange, type PointBudget } from './creation'
import { loreField } from './fixtures'

describe('outOfRange', () => {
  // The skill points of `creationBudgets`: Lore, at most 3.
  const skills: PointBudget = {
    key: 'skills',
    label: 'Skill points',
    total: 8,
    fields: ['lore'],
    min: null,
    max: 3,
  }
  const bounded = new Map([['lore', skills]])

  it('flags a draft above the budget max with the narrowed range', () => {
    expect(outOfRange([loreField], bounded, { lore: '4' })).toEqual([
      { field: loreField, range: 'between 0 and 3' },
    ])
  })

  it('passes a draft inside the range and a blank one', () => {
    expect(outOfRange([loreField], bounded, { lore: '3' })).toEqual([])
    expect(outOfRange([loreField], bounded, { lore: ' ' })).toEqual([])
  })

  it('flags a non-numeric draft, so it cannot be saved', () => {
    expect(outOfRange([loreField], bounded, { lore: 'abc' })).toEqual([
      { field: loreField, range: 'between 0 and 3' },
    ])
  })
})
