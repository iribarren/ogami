import { creationNotes, creationPlan, outOfRange, type PointBudget } from './creation'
import { creationBudget, creationSheet, loreField } from './fixtures'

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

describe('creationNotes', () => {
  // Strength and Constitution are rolled (3d6*5, (2d6+6)*5); Hit points start at ({str} + {con}) / 10.
  const budgets = [{ key: 'points', ...creationBudget, label: null }]
  const hp = (method: 'roll' | 'points' | null, drafts: Record<string, string>) => {
    const plan = creationPlan(creationSheet, budgets, method)
    return creationNotes(plan, creationSheet, drafts, []).hp
  }

  it('tells the value of a start max the player decides, as it is typed', () => {
    expect(hp('points', { str: '40', con: '60' })).toBe('Set at creation: 10')
  })

  it('names what the start max comes from when the server rolls a field it reads', () => {
    // The drafts hold the default of the rolled fields: reading them would show a wrong value.
    expect(hp('roll', { str: '15', con: '15' })).toBe(
      'Set at creation from Strength and Constitution',
    )
  })

  it('reads as by roll until a method is chosen', () => {
    expect(hp(null, { str: '15', con: '15' })).toBe(
      'Set at creation from Strength and Constitution',
    )
  })

  it('falls back to the explanation when a draft is not a number yet', () => {
    expect(hp('points', { str: '', con: '60' })).toBe(
      'Set at creation from Strength and Constitution',
    )
  })

  it('clamps the value as the server does', () => {
    const sheet = creationSheet.map((field) =>
      field.key === 'hp' ? { ...field, startMax: '{str} - 100' } : field,
    )
    const plan = creationPlan(sheet, budgets, 'points')
    expect(creationNotes(plan, sheet, { str: '40', con: '60' }, []).hp).toBe('Set at creation: 0')
  })

  it('names a single field plainly', () => {
    const sheet = creationSheet.map((field) =>
      field.key === 'hp' ? { ...field, startMax: '{con} * 2' } : field,
    )
    const plan = creationPlan(sheet, budgets, 'roll')
    expect(creationNotes(plan, sheet, {}, []).hp).toBe('Set at creation from Constitution')
  })

  it('leaves the rolled number fields as the plan says', () => {
    const plan = creationPlan(creationSheet, budgets, 'roll')
    expect(creationNotes(plan, creationSheet, {}, []).str).toBe('Rolled at creation: 3d6*5')
  })
})
