import {
  blankField,
  moveTarget,
  readField,
  sheetSections,
  writeField,
  type FieldDraft,
  type SheetGroup,
} from './sheetDraft'

const groups: SheetGroup[] = [
  { name: 'Stats', columns: 2, span: 1 },
  { name: 'Health', columns: 1, span: 1 },
]

const filled = (changes: Partial<FieldDraft>): FieldDraft => ({
  ...blankField(null),
  key: 'luck',
  label: 'Luck',
  ...changes,
})

describe('reading a stored field', () => {
  it('reads every property of a field as form text', () => {
    expect(
      readField({
        key: 'pow',
        label: 'Power',
        hint: 'Raw strength',
        group: 'Stats',
        span: 2,
        type: 'number',
        min: 0,
        max: 10,
        default: 3,
      }),
    ).toEqual({
      ...blankField(null),
      key: 'pow',
      label: 'Power',
      hint: 'Raw strength',
      group: 'Stats',
      span: '2',
      type: 'number',
      min: '0',
      max: '10',
      default: '3',
    })
    expect(
      readField({ key: 'notes', label: 'Notes', type: 'text', multiline: true }),
    ).toMatchObject({ type: 'text', multiline: true, maxLength: '' })
  })

  it.each([
    ['an unknown type', { key: 'a', label: 'A', type: 'gauge' }],
    ['a key that is not text', { key: 4, label: 'A', type: 'text' }],
    ['a number that is text', { key: 'a', label: 'A', type: 'number', min: 'low' }],
    ['a multiline that is not a boolean', { key: 'a', label: 'A', type: 'text', multiline: 1 }],
    ['a hint that is not text', { key: 'a', label: 'A', type: 'text', hint: 4 }],
  ])('cannot read %s', (_name, stored) => {
    expect(readField(stored)).toBeNull()
  })
})

describe('writing a field', () => {
  it('omits every empty optional input instead of sending a default', () => {
    expect(writeField(filled({ type: 'number' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'number' },
    })
    expect(writeField(filled({ type: 'text', multiline: false }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'text' },
    })
  })

  it('sends the properties of the chosen type only', () => {
    expect(
      writeField(
        filled({ type: 'number', min: '0', max: '10', default: '-1', maxItems: '4' }),
        null,
      ),
    ).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'number', min: 0, max: 10, default: -1 },
    })
    expect(writeField(filled({ type: 'text', multiline: true, maxLength: '200' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'text', multiline: true, maxLength: 200 },
    })
    expect(writeField(filled({ type: 'list', itemLabel: 'Item', maxItems: '5' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'list', itemLabel: 'Item', maxItems: 5 },
    })
    expect(writeField(filled({ type: 'track', max: '6' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'track', max: 6 },
    })
    expect(writeField(filled({ type: 'derived', formula: '{pow} + 1' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'derived', formula: '{pow} + 1' },
    })
  })

  it('sends the hint, group and span when they are filled, trimmed', () => {
    expect(
      writeField(filled({ type: 'track', hint: ' Hold on ', group: ' Stats ', span: '2' }), groups),
    ).toEqual({
      field: {
        key: 'luck',
        label: 'Luck',
        type: 'track',
        hint: 'Hold on',
        group: 'Stats',
        span: 2,
      },
    })
  })

  it.each(['1.5', 'x', '0', '3', '-1'])(
    'flags the span %s: a whole number from 1 to the group columns',
    (span) => {
      expect(writeField(filled({ type: 'track', group: 'Stats', span }), groups)).toEqual({
        errors: { span: 'Must be a whole number from 1 to 2.' },
      })
    },
  )

  it('limits the span to the columns of the group', () => {
    expect(writeField(filled({ type: 'track', group: 'Health', span: '2' }), groups)).toEqual({
      errors: { span: 'Must be a whole number from 1 to 1.' },
    })
  })

  it('flags what is blank or not a whole number, field by field', () => {
    expect(
      writeField(filled({ key: ' ', label: '', type: 'number', min: '1.5', max: 'x' }), null),
    ).toEqual({
      errors: {
        key: 'Give the field a key.',
        label: 'Give the field a label.',
        min: 'Must be a whole number.',
        max: 'Must be a whole number.',
      },
    })
    expect(writeField(filled({ type: 'derived' }), null)).toEqual({
      errors: { formula: 'Write the formula.' },
    })
  })

  it('needs a declared group when the sheet has groups, and none otherwise', () => {
    expect(writeField(filled({ type: 'track', group: '' }), groups)).toEqual({
      errors: { group: 'Pick a group.' },
    })
    expect(writeField(filled({ type: 'track', group: 'Nowhere' }), groups)).toEqual({
      errors: { group: 'Pick a group.' },
    })
    expect(writeField(filled({ type: 'track', group: '', span: '2' }), null)).toEqual({
      field: { key: 'luck', label: 'Luck', type: 'track' },
    })
  })
})

describe('the sections of a sheet', () => {
  const fields = [
    { key: 'pow', group: 'Stats' },
    { key: 'hp', group: 'Health' },
    { key: 'dex', group: 'Stats' },
  ]

  it('lists declared groups in order, empty ones too, and fields in list order within their group', () => {
    const sections = sheetSections(fields, [...groups, { name: 'Notes', columns: 1, span: 1 }])

    expect(
      sections.map((section) => [section.heading, section.rows.map((row) => row.index)]),
    ).toEqual([
      ['Stats', [0, 2]],
      ['Health', [1]],
      ['Notes', []],
    ])
  })

  it('does not hide a field whose group is not declared', () => {
    const sections = sheetSections([...fields, { key: 'odd', group: 'Ghost' }], groups)

    expect(sections.at(-1)).toMatchObject({ heading: 'Ghost', rows: [{ index: 3 }] })
  })

  it('starts a section whenever the group of consecutive fields changes, as Play renders them', () => {
    const sections = sheetSections(
      [{ key: 'a', group: 'Stats' }, { key: 'b' }, { key: 'c' }, { key: 'd', group: 'Stats' }],
      null,
    )

    expect(
      sections.map((section) => [section.heading, section.rows.map((row) => row.index)]),
    ).toEqual([
      ['Stats', [0]],
      [null, [1, 2]],
      ['Stats', [3]],
    ])
  })
})

describe('where a field moves to', () => {
  const fields = [
    { key: 'pow', group: 'Stats' },
    { key: 'hp', group: 'Health' },
    { key: 'dex', group: 'Stats' },
    { key: 'san', group: 'Health' },
  ]

  it('is the whole-list index of the neighbour in the same group when groups are declared', () => {
    expect(moveTarget(fields, 2, 'up', true)).toBe(0)
    expect(moveTarget(fields, 0, 'down', true)).toBe(2)
    expect(moveTarget(fields, 1, 'down', true)).toBe(3)
  })

  it('has none at the edge of the group', () => {
    expect(moveTarget(fields, 0, 'up', true)).toBeNull()
    expect(moveTarget(fields, 2, 'down', true)).toBeNull()
  })

  it('is the next or previous index of the list without declared groups', () => {
    expect(moveTarget(fields, 2, 'up', false)).toBe(1)
    expect(moveTarget(fields, 2, 'down', false)).toBe(3)
    expect(moveTarget(fields, 0, 'up', false)).toBeNull()
    expect(moveTarget(fields, 3, 'down', false)).toBeNull()
  })
})
