import {
  creationNotes,
  creationPlan,
  derivedOnForm,
  outOfRange,
  type PointBudget,
} from './creation'
import {
  creationBudget,
  creationSheet,
  initiativeField,
  loreField,
  type SheetField,
} from './fixtures'

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

describe('derivedOnForm', () => {
  // Strength is rolled by roll; Lore is always the player's.
  const budgets = [{ key: 'points', ...creationBudget, label: null }]
  const derived = (key: string, formula: string): SheetField => ({
    ...initiativeField,
    key,
    formula,
  })
  const sheet = [
    ...creationSheet,
    derived('bonus', '{str} / 5'),
    derived('twice', '{bonus} * 2'),
    loreField,
    derived('loreup', '{lore} + 1'),
    derived('hpmax', '{hp.max} + 1'),
  ]
  const on = (method: 'roll' | 'points', drafts: Record<string, string>) =>
    derivedOnForm(creationPlan(sheet, budgets, method), sheet, drafts, [])

  it('says a derived value reading a rolled field is set at creation', () => {
    // The draft holds the default of the rolled field: a number from it would be wrong.
    expect(on('roll', { str: '1', con: '1' }).bonus).toEqual({ reason: 'set at creation' })
  })

  it('says so for any kind of reference to a field the server sets', () => {
    expect(on('roll', {}).hpmax).toEqual({ reason: 'set at creation' })
  })

  it('computes a derived value that reads only what the player sets, as typed', () => {
    expect(on('roll', { lore: '3' }).loreup).toEqual({ value: 4 })
  })

  it('keeps depends on for a derived value over one set at creation', () => {
    expect(on('roll', {}).twice).toEqual({ reason: 'depends on bonus' })
  })

  it('computes the value when the player sets the field, by points', () => {
    expect(on('points', { str: '40', con: '60' }).bonus).toEqual({ value: 8 })
    expect(on('points', { str: '40', con: '60' }).twice).toEqual({ value: 16 })
  })
})
