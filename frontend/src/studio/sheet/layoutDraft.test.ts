import {
  addGroup,
  fieldsHeldBy,
  moveGroup,
  readLayout,
  removeGroup,
  writeLayout,
  type GroupDraft,
  type LayoutDraft,
} from './layoutDraft'

function at(draft: LayoutDraft, index: number): GroupDraft {
  const group = draft.groups[index]
  if (group === undefined) {
    throw new Error(`The draft has no group ${String(index)}.`)
  }
  return group
}

const stored = {
  columns: 2,
  groups: [
    { name: 'Stats', columns: 2, span: 1 },
    { name: 'Health', columns: 1, span: 2 },
  ],
}

function readStored(): LayoutDraft {
  const draft = readLayout(stored)
  if (draft === null) {
    throw new Error('The stored layout should be readable.')
  }
  return draft
}

const names = (draft: LayoutDraft) => draft.groups.map((group) => group.name)

describe('reading the stored layout', () => {
  it('holds the columns and every group, remembering the stored name', () => {
    const draft = readLayout(stored)

    expect(draft?.columns).toBe(2)
    expect(
      draft?.groups.map(({ name, previousName, columns, span }) => [
        name,
        previousName,
        columns,
        span,
      ]),
    ).toEqual([
      ['Stats', 'Stats', 2, 1],
      ['Health', 'Health', 1, 2],
    ])
  })

  it.each([
    ['no groups', { columns: 1, groups: null }],
    ['sheet columns out of range', { ...stored, columns: 3 }],
    [
      'a fractional group column count',
      { ...stored, groups: [{ name: 'A', columns: 1.5, span: 1 }] },
    ],
    ['group columns out of range', { ...stored, groups: [{ name: 'A', columns: 5, span: 1 }] }],
    ['a span out of range', { ...stored, groups: [{ name: 'A', columns: 1, span: 0 }] }],
    [
      'a span wider than the stored sheet columns',
      { columns: 1, groups: [{ name: 'A', columns: 1, span: 2 }] },
    ],
    ['a group without a name', { ...stored, groups: [{ columns: 1, span: 1 }] }],
  ])('refuses %s instead of guessing', (_name, sheet) => {
    expect(readLayout(sheet as never)).toBeNull()
  })
})

describe('editing the groups', () => {
  const draft = readStored()

  it('adds a blank group at the end, with no stored name', () => {
    const added = addGroup(draft)

    expect(names(added)).toEqual(['Stats', 'Health', ''])
    expect(at(added, 2)).toMatchObject({ previousName: null, columns: 1, span: 1 })
  })

  it('moves a group up or down and stops at the edges', () => {
    expect(names(moveGroup(draft, 1, 'up'))).toEqual(['Health', 'Stats'])
    expect(names(moveGroup(draft, 0, 'down'))).toEqual(['Health', 'Stats'])
    expect(moveGroup(draft, 0, 'up')).toBe(draft)
    expect(moveGroup(draft, 1, 'down')).toBe(draft)
  })

  it('removes a group', () => {
    expect(names(removeGroup(draft, 0))).toEqual(['Health'])
  })

  it('counts the fields a stored group holds, none for a new group', () => {
    const fields = [{ group: 'Stats' }, { group: 'Stats' }, { group: 'Health' }, {}]

    expect(fieldsHeldBy(at(draft, 0), fields)).toBe(2)
    expect(fieldsHeldBy(at(draft, 1), fields)).toBe(1)
    expect(fieldsHeldBy(at(addGroup(draft), 2), fields)).toBe(0)
  })
})

describe('writing the layout', () => {
  const draft = readStored()
  const rename = (changes: Partial<LayoutDraft['groups'][number]>[]): LayoutDraft => ({
    ...draft,
    groups: draft.groups.map((group, at) => ({ ...group, ...changes[at] })),
  })

  it('sends the columns and every group in order, previousName only for a rename', () => {
    expect(writeLayout(rename([{ name: ' Attributes ' }, {}]))).toEqual({
      request: {
        columns: 2,
        groups: [
          { name: 'Attributes', previousName: 'Stats', columns: 2, span: 1 },
          { name: 'Health', columns: 1, span: 2 },
        ],
      },
    })
  })

  it('sends a new group without previousName and follows the reorder', () => {
    const added = addGroup(moveGroup(draft, 1, 'up'))
    at(added, 2).name = 'Notes'

    expect(writeLayout(added)).toEqual({
      request: {
        columns: 2,
        groups: [
          { name: 'Health', columns: 1, span: 2 },
          { name: 'Stats', columns: 2, span: 1 },
          { name: 'Notes', columns: 1, span: 1 },
        ],
      },
    })
  })

  it('leaves out a dropped group', () => {
    const result = writeLayout(removeGroup(draft, 1))

    expect('request' in result && result.request.groups.map((group) => group.name)).toEqual([
      'Stats',
    ])
  })

  it('flags a blank, too long and repeated name, group by group', () => {
    const result = writeLayout(rename([{ name: ' ' }, { name: 'x'.repeat(61) }]))
    expect(result).toEqual({
      errors: {
        columns: null,
        form: null,
        groups: { 0: { name: 'Give the group a name.' }, 1: { name: 'At most 60 characters.' } },
      },
    })

    expect(writeLayout(rename([{ name: 'Same' }, { name: 'Same' }]))).toMatchObject({
      errors: { groups: { 1: { name: 'Another group has this name.' } } },
    })
  })

  it('tells names apart by case, as the publisher does', () => {
    expect(writeLayout(rename([{ name: 'Stats' }, { name: 'stats' }]))).toHaveProperty('request')
  })

  it('flags columns and spans out of range, and a span above the sheet columns', () => {
    const result = writeLayout({
      columns: 1,
      groups: [
        { ...at(draft, 0), columns: 5 },
        { ...at(draft, 1), span: 2 },
      ],
    })

    expect(result).toMatchObject({
      errors: {
        groups: {
          0: { columns: 'Pick 1 to 4.' },
          1: { span: 'Pick 1.' },
        },
      },
    })
    expect(writeLayout({ ...draft, columns: 3 })).toMatchObject({
      errors: { columns: 'Pick 1 to 2.' },
    })
  })

  it('needs a group and at most 20', () => {
    expect(writeLayout({ ...draft, groups: [] })).toMatchObject({
      errors: { form: 'Declare at least one group.' },
    })
    const many = Array.from({ length: 21 }, (_, number) => ({
      ...at(draft, 0),
      id: 100 + number,
      name: `G${String(number)}`,
    }))
    expect(writeLayout({ ...draft, groups: many })).toMatchObject({
      errors: { form: 'A sheet has at most 20 groups.' },
    })
  })
})
