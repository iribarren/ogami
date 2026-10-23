import { summarize } from './sheetValues'
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
})
