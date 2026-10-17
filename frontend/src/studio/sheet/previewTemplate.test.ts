import { sheet } from '../fixtures'
import { readLayout, type LayoutDraft } from './layoutDraft'
import { previewTemplate } from './previewTemplate'
import type { GameSystemSheet } from './useGameSystemSheet'

const stored = sheet as GameSystemSheet

function savedDraft(): LayoutDraft {
  const draft = readLayout(stored)
  if (draft === null) {
    throw new Error('The fixture layout should be readable.')
  }
  return draft
}

describe('the preview template', () => {
  it('reads the stored fields and layout', () => {
    const template = previewTemplate(stored, null)

    expect(
      template.fields.map((field) => [field.key, field.type, field.group, field.span]),
    ).toEqual([
      ['pow', 'number', 'Stats', 2],
      ['hp', 'track', 'Health', 1],
      ['dex', 'number', 'Stats', 1],
      ['bonus', 'derived', 'Health', 1],
    ])
    expect(template.fields[0]).toMatchObject({ min: 0, max: 18, hint: null, formula: null })
    expect(template.layout?.columns).toBe(2)
    expect(template.layout?.groups.map((group) => group.name)).toEqual(['Stats', 'Health', 'Notes'])
  })

  it('has no layout when the sheet declares none or holds one the form cannot read', () => {
    expect(previewTemplate({ ...stored, groups: null }, null).layout).toBeNull()
    expect(
      previewTemplate({ ...stored, groups: [{ name: 'A', columns: 9, span: 1 }] }, null).layout,
    ).toBeNull()
  })

  it('leaves out what is not a keyed, labelled field of a known type, or repeats a key', () => {
    const template = previewTemplate(
      {
        ...stored,
        fields: [
          { key: 'a', label: 'A', type: 'number' },
          { key: 'a', label: 'Again', type: 'number' },
          { label: 'No key', type: 'number' },
          { key: 'b', type: 'number' },
          { key: 'c', label: 'C', type: 'colour' },
          { key: 'd', label: 'D', type: 'text', group: 4, span: 'wide', multiline: 'yes' },
        ],
      },
      null,
    )

    expect(template.fields.map((field) => field.label)).toEqual(['A', 'D'])
    expect(template.fields[1]).toMatchObject({ group: null, span: 1, multiline: null })
  })

  it.each([0, -1, 1.5, 3, 'wide'])('reads a stored field span of %s as 1', (span) => {
    // The fixture's Stats group has 2 columns.
    const template = previewTemplate(
      { ...stored, fields: [{ key: 'a', label: 'A', type: 'number', group: 'Stats', span }] },
      null,
    )

    expect(template.fields[0]?.span).toBe(1)
  })

  it('keeps a stored field span that fits its group', () => {
    const template = previewTemplate(
      { ...stored, fields: [{ key: 'a', label: 'A', type: 'number', group: 'Stats', span: 2 }] },
      null,
    )

    expect(template.fields[0]?.span).toBe(2)
  })

  it('shows a layout draft instead of the saved layout, fields following a renamed group', () => {
    const draft = savedDraft()
    const [first] = draft.groups
    if (first === undefined) {
      throw new Error('The fixture has groups.')
    }
    const edited: LayoutDraft = {
      columns: 1,
      groups: [{ ...first, name: ' Attributes ', span: 1 }, ...draft.groups.slice(1)],
    }

    const template = previewTemplate(stored, edited)

    expect(template.layout?.columns).toBe(1)
    expect(template.layout?.groups[0]?.name).toBe('Attributes')
    expect(template.fields.find((field) => field.key === 'pow')?.group).toBe('Attributes')
  })

  it('falls back to the saved layout while the draft is invalid', () => {
    const invalid: LayoutDraft = { columns: 2, groups: [] }

    expect(previewTemplate(stored, invalid)).toEqual(previewTemplate(stored, null))
  })
})
