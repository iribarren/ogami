import type { components } from '@/shared/api/schema'

/** A declared group of the sheet: its name, and how many columns of fields it has. */
export type SheetGroup = Pick<
  components['schemas']['SheetGroupResponse2'],
  'name' | 'columns' | 'span'
>

/** A field as stored in the draft: the publisher validates it, the editor keeps nothing else. */
export type StoredField = Record<string, unknown>

export const fieldTypes = ['number', 'text', 'list', 'track', 'derived'] as const
export type FieldType = (typeof fieldTypes)[number]

/** The form state of one field: every input is text, and what is empty is not sent. */
export interface FieldDraft {
  key: string
  label: string
  hint: string
  type: FieldType
  group: string
  span: string
  /** A number's or a track's `max`. */
  max: string
  min: string
  default: string
  multiline: boolean
  maxLength: string
  itemLabel: string
  maxItems: string
  formula: string
}

/** A message per input, keyed like the draft. */
export type FieldErrors = Partial<Record<keyof FieldDraft, string>>

export function blankField(groups: SheetGroup[] | null): FieldDraft {
  return {
    key: '',
    label: '',
    hint: '',
    type: 'number',
    group: groups?.[0]?.name ?? '',
    span: '',
    max: '',
    min: '',
    default: '',
    multiline: false,
    maxLength: '',
    itemLabel: '',
    maxItems: '',
    formula: '',
  }
}

/** The inputs only a type has: changing the type starts them again. */
export const typeProperties = [
  'min',
  'max',
  'default',
  'multiline',
  'maxLength',
  'itemLabel',
  'maxItems',
  'formula',
] as const

/** The stored properties each type writes besides the common ones. */
const propertiesOf: Record<FieldType, readonly (typeof typeProperties)[number][]> = {
  number: ['min', 'max', 'default'],
  text: ['multiline', 'maxLength'],
  list: ['itemLabel', 'maxItems'],
  track: ['max'],
  derived: ['formula'],
}

const integerProperties = new Set<string>([
  'min',
  'max',
  'default',
  'maxLength',
  'maxItems',
  'span',
])

/** The stored field as form state, or null when a property has a shape the form cannot hold. */
export function readField(stored: StoredField): FieldDraft | null {
  const draft = blankField(null)
  const { type } = stored
  if (!fieldTypes.includes(type as FieldType)) {
    return null
  }
  draft.type = type as FieldType

  let readable = true
  const text = (name: 'key' | 'label' | 'hint' | 'group' | 'itemLabel' | 'formula') => {
    const value = stored[name]
    if (typeof value === 'string') {
      draft[name] = value
    } else if (value !== undefined && value !== null) {
      readable = false
    }
  }
  const integer = (name: 'span' | 'min' | 'max' | 'default' | 'maxLength' | 'maxItems') => {
    const value = stored[name]
    if (Number.isInteger(value)) {
      draft[name] = String(value)
    } else if (value !== undefined && value !== null) {
      readable = false
    }
  }

  for (const name of ['key', 'label', 'hint', 'group'] as const) {
    text(name)
  }
  integer('span')
  for (const name of propertiesOf[draft.type]) {
    if (name === 'multiline') {
      if (typeof stored.multiline === 'boolean') {
        draft.multiline = stored.multiline
      } else if (stored.multiline !== undefined && stored.multiline !== null) {
        readable = false
      }
    } else if (name === 'itemLabel' || name === 'formula') {
      text(name)
    } else {
      integer(name)
    }
  }

  return readable && draft.key !== '' && draft.label !== '' ? draft : null
}

const wholeNumber = /^-?\d+$/

/**
 * The field to send, or what is wrong with the form. Empty optional inputs are left out, never
 * replaced by a default; the publisher checks the rest (ranges, key format, references).
 */
export function writeField(
  draft: FieldDraft,
  groups: SheetGroup[] | null,
): { field: StoredField } | { errors: FieldErrors } {
  const errors: FieldErrors = {}
  const field: StoredField = {}

  const key = draft.key.trim()
  if (key === '') {
    errors.key = 'Give the field a key.'
  }
  const label = draft.label.trim()
  if (label === '') {
    errors.label = 'Give the field a label.'
  }
  field.key = key
  field.label = label
  const hint = draft.hint.trim()
  if (hint !== '') {
    field.hint = hint
  }

  const group = draft.group.trim()
  if (groups !== null) {
    if (!groups.some((declared) => declared.name === group)) {
      errors.group = 'Pick a group.'
    }
    field.group = group
    if (draft.span !== '') {
      field.span = Number(draft.span)
    }
  } else if (group !== '') {
    field.group = group
  }
  field.type = draft.type

  for (const name of propertiesOf[draft.type]) {
    const value = draft[name]
    if (typeof value === 'boolean') {
      if (value) {
        field[name] = true
      }
      continue
    }
    const text = value.trim()
    if (text === '') {
      if (name === 'formula') {
        errors.formula = 'Write the formula.'
      }
    } else if (!integerProperties.has(name)) {
      field[name] = text
    } else if (wholeNumber.test(text)) {
      field[name] = Number(text)
    } else {
      errors[name] = 'Must be a whole number.'
    }
  }

  return Object.keys(errors).length > 0 ? { errors } : { field }
}

/** A run of fields under one heading; `rows` keep the field's index in the whole list. */
export interface Section<Field> {
  heading: string | null
  rows: { field: Field; index: number }[]
}

/**
 * Declared groups in order (empty ones too) with their fields in list order. Without declared
 * groups, a new section whenever the group of consecutive fields changes, as Play renders them.
 * A field of a group that is not declared is still listed, after the declared ones.
 */
export function sheetSections<Field extends { group?: unknown }>(
  fields: Field[],
  groups: SheetGroup[] | null,
): Section<Field>[] {
  const group = (field: Field) => (typeof field.group === 'string' ? field.group : null)
  const sections: Section<Field>[] = []

  if (groups === null) {
    fields.forEach((field, index) => {
      const last = sections.at(-1)
      if (last?.heading === group(field)) {
        last.rows.push({ field, index })
      } else {
        sections.push({ heading: group(field), rows: [{ field, index }] })
      }
    })
    return sections
  }

  const byName = new Map<string, Section<Field>>(
    groups.map((declared) => [declared.name, { heading: declared.name, rows: [] }]),
  )
  fields.forEach((field, index) => {
    const name = group(field) ?? ''
    let section = byName.get(name)
    if (section === undefined) {
      section = { heading: name === '' ? null : name, rows: [] }
      byName.set(name, section)
    }
    section.rows.push({ field, index })
  })
  return [...byName.values()]
}

/**
 * The whole-list index a move should send: the neighbour's, in the same group when groups are
 * declared (fields render in list order within their group), in the list otherwise. Null at the
 * edge.
 */
export function moveTarget(
  fields: { group?: unknown }[],
  index: number,
  direction: 'up' | 'down',
  grouped: boolean,
): number | null {
  const step = direction === 'up' ? -1 : 1
  for (let next = index + step; next >= 0 && next < fields.length; next += step) {
    if (!grouped || fields[next]?.group === fields[index]?.group) {
      return next
    }
  }
  return null
}
