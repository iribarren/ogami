import {
  fromDraft,
  markedLabels,
  slotsUsed,
  stackText,
  summarize,
  toDraft,
  valueOrDefault,
} from './sheetValues'
import type { SheetEconomy, SheetFieldView, SheetGear, SheetStack, SheetValue } from './types'

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

describe('an inventory field (ADR 0045)', () => {
  const pack: SheetFieldView = { ...gear, key: 'pack', label: 'Pack', type: 'inventory' }
  const catalog: SheetGear[] = [
    { key: 'used-pistol', name: 'Used pistol', slots: 1, category: 'Weapons' },
    { key: 'katana', name: 'Katana', slots: 3, category: 'Weapons' },
  ]
  const economy: SheetEconomy = { gear: catalog, currency: { field: 'eddies', unit: 'eb' } }
  const stacks: SheetStack[] = [
    { gear: 'used-pistol', quantity: 2 },
    { name: 'Rope', quantity: 1, slots: 2 },
  ]

  it('starts empty and sends the stacks back as they are', () => {
    expect(valueOrDefault(pack, undefined)).toEqual([])
    expect(fromDraft(pack, toDraft(pack, undefined))).toEqual([])
    expect(toDraft(pack, stacks)).toEqual(stacks)
    expect(fromDraft(pack, toDraft(pack, stacks))).toEqual(stacks)
  })

  it('reads a malformed stored value as no stacks, and keeps only the well-formed ones', () => {
    expect(toDraft(pack, [{ gear: 4 }, 'Rope'] as unknown as SheetValue)).toEqual([])
    expect(toDraft(pack, [...stacks, { quantity: 1 }] as unknown as SheetValue)).toEqual(stacks)
    expect(fromDraft(pack, '[{"gear":"x","quantity":1}]')).toEqual([])
    expect(fromDraft(pack, ['Rope'])).toEqual([])
  })

  it('names stacks by the catalog, a free stack by itself and unknown gear by its key', () => {
    expect(stackText({ gear: 'used-pistol', quantity: 2 }, catalog)).toBe('Used pistol ×2')
    expect(stackText({ name: 'Rope', quantity: 1, slots: 2 }, catalog)).toBe('Rope')
    expect(stackText({ gear: 'gone', quantity: 1 }, catalog)).toBe('gone')
  })

  it('adds up the slots of every unit, free stacks and catalog gear alike', () => {
    expect(slotsUsed(stacks, catalog)).toBe(4)
    expect(slotsUsed([{ gear: 'gone', quantity: 5 }], catalog)).toBe(0)
  })

  it('summarizes the stacks by name like a list, and 0 when empty', () => {
    expect(summarize(pack, stacks, {}, economy)).toBe('Pack: Used pistol ×2, Rope')
    expect(summarize(pack, undefined)).toBe('Pack: 0')
  })

  it('puts the currency unit after the money of the currency field only', () => {
    const eddies: SheetFieldView = { ...gear, key: 'eddies', label: 'Eddies', type: 'number' }
    expect(summarize(eddies, 120, {}, economy)).toBe('Eddies: 120 eb')
    expect(summarize({ ...eddies, key: 'luck', label: 'Luck' }, 3, {}, economy)).toBe('Luck: 3')
    expect(summarize(eddies, 120)).toBe('Eddies: 120')
  })
})
