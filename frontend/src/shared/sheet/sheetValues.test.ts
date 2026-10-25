import { fromDraft, markedLabels, summarize, toDraft, valueOrDefault } from './sheetValues'
import type { SheetFieldView } from './types'

const gear: SheetFieldView = {
  key: 'gear',
  label: 'Gear',
  hint: null,
  group: null,
  type: 'list',
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula: null,
  span: 1,
}

describe('summarize a list field', () => {
  it('keeps the count for an empty list', () => {
    expect(summarize(gear, [])).toBe('Gear: 0')
    expect(summarize(gear, undefined)).toBe('Gear: 0')
  })

  it('shows the only item', () => {
    expect(summarize(gear, ['Rapier'])).toBe('Gear: Rapier')
  })

  it('joins the items that fit and counts the rest', () => {
    expect(summarize(gear, ['Rapier', 'Cloak', 'Lockpicks'])).toBe('Gear: Rapier, Cloak, Lockpicks')
    expect(
      summarize(gear, ['Rapier', 'Cloak', 'Lockpicks', 'Grappling hook and rope', 'Rope', 'Map']),
    ).toBe('Gear: Rapier, Cloak, Lockpicks +3')
  })

  it('cuts a very long first item like a text field and counts the others', () => {
    const long = 'x'.repeat(60)

    expect(summarize(gear, [long])).toBe(`Gear: ${'x'.repeat(40)}…`)
    expect(summarize(gear, [long, 'Cloak'])).toBe(`Gear: ${'x'.repeat(40)}… +1`)
  })

  it('fits exactly 40 characters and cuts at 41', () => {
    expect(summarize(gear, ['x'.repeat(40)])).toBe(`Gear: ${'x'.repeat(40)}`)
    expect(summarize(gear, ['x'.repeat(41)])).toBe(`Gear: ${'x'.repeat(40)}…`)
  })

  it('skips blank items, and a list of only blank items reads like an empty one', () => {
    expect(summarize(gear, ['Rapier', '  ', '', 'Cloak'])).toBe('Gear: Rapier, Cloak')
    expect(summarize(gear, ['', ' '])).toBe('Gear: 0')
  })
})

const wounds: SheetFieldView = {
  ...gear,
  key: 'wounds',
  label: 'Wounds',
  type: 'conditions',
  options: [
    { key: 'hurt', label: 'Hurt' },
    { key: 'shaken', label: 'Shaken' },
    { key: 'tired', label: 'Tired' },
  ],
}

describe('a conditions field', () => {
  it('reads nothing marked for a Character saved before the field existed', () => {
    expect(valueOrDefault(wounds, undefined)).toEqual([])
    expect(toDraft(wounds, undefined)).toEqual([])
    expect(summarize(wounds, undefined)).toBe('Wounds: none')
  })

  it('sends the marked keys as the draft holds them', () => {
    expect(fromDraft(wounds, toDraft(wounds, ['shaken', 'hurt']))).toEqual(['shaken', 'hurt'])
  })

  it('names the marked options by label, in declared order', () => {
    expect(markedLabels(wounds, ['tired', 'hurt'])).toEqual(['Hurt', 'Tired'])
    expect(summarize(wounds, ['tired', 'hurt'])).toBe('Wounds: Hurt, Tired')
  })

  it('leaves out a key the field no longer declares', () => {
    expect(markedLabels(wounds, ['gone', 'hurt'])).toEqual(['Hurt'])
    expect(summarize(wounds, ['gone'])).toBe('Wounds: none')
  })
})
