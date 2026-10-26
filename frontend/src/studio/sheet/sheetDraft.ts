import type { components } from '@/shared/api/schema'

import { slugOf } from '../checks/checkDraft'

/** A declared group of the sheet: its name, and how many columns of fields it has. */
export type SheetGroup = Pick<
  components['schemas']['SheetGroupResponse2'],
  'name' | 'columns' | 'span'
>

/** A field as stored in the draft: the publisher validates it, the editor keeps nothing else. */
export type StoredField = Record<string, unknown>

export const fieldTypes = ['number', 'text', 'list', 'track', 'conditions', 'derived'] as const
export type FieldType = (typeof fieldTypes)[number]

/** How a track's max is set (ADR 0040): a fixed number, by each Character, or by a formula when the Character is created. */
export const maxModes = ['fixed', 'perCharacter', 'startFormula'] as const
export type MaxMode = (typeof maxModes)[number]

/** The limits of a conditions field's options, as the publisher. */
export const optionLimits = { count: 20, label: 60 }

/** One option of a conditions field; while `follow` is set its key follows its label. */
export interface OptionDraft {
  key: string
  label: string
  follow?: boolean
}

/** An option added in the form: its key follows its label until the key is typed. */
export const newOption = (): OptionDraft => ({ key: '', label: '', follow: true })

/** The option with a new label; the key follows it while the option has not had its key typed. */
export function editOptionLabel(option: OptionDraft, label: string): OptionDraft {
  return option.follow === true ? { ...option, label, key: slugOf(label) } : { ...option, label }
}

/** The option with a key typed by hand, which stops it following the label. */
export function editOptionKey(option: OptionDraft, key: string): OptionDraft {
  return { ...option, key, follow: false }
}

/** The messages of one option's inputs. */
export type OptionErrors = Partial<Record<'key' | 'label', string>>

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
  /** A number's dice default, as notation: stored as `{dice}`. */
  roll: string
  /** A track's start max formula: stored as `{formula}`. */
  startMax: string
  /** Which of a track's `max` and `startMax` is written; neither for each Character. */
  maxMode: MaxMode
  /** A conditions field's options, in order. */
  options: OptionDraft[]
}

/** A message per input, keyed like the draft; `option` holds those of each option's inputs, by position. */
export type FieldErrors = Partial<Record<keyof FieldDraft, string>> & {
  option?: Record<number, OptionErrors>
}

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
    roll: '',
    startMax: '',
    maxMode: 'fixed',
    options: [],
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
  'roll',
  'startMax',
  'maxMode',
  'options',
] as const

/** The stored properties each type writes besides the common ones. */
const propertiesOf: Record<
  FieldType,
  readonly Exclude<(typeof typeProperties)[number], 'maxMode'>[]
> = {
  conditions: ['options'],
  number: ['min', 'max', 'default', 'roll'],
  text: ['multiline', 'maxLength'],
  list: ['itemLabel', 'maxItems'],
  track: ['max', 'startMax'],
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
  // `{dice: text}` or `{formula: text}` as the text of its one property.
  const wrapped = (name: 'roll' | 'startMax', property: 'dice' | 'formula') => {
    const value = stored[name]
    if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
      const inner = (value as Record<string, unknown>)[property]
      if (typeof inner === 'string') {
        draft[name] = inner
        return
      }
    }
    if (value !== undefined && value !== null) {
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
    if (name === 'options') {
      const options = readOptions(stored.options)
      if (options === null) {
        readable = false
      } else {
        draft.options = options
      }
    } else if (name === 'multiline') {
      if (typeof stored.multiline === 'boolean') {
        draft.multiline = stored.multiline
      } else if (stored.multiline !== undefined && stored.multiline !== null) {
        readable = false
      }
    } else if (name === 'itemLabel' || name === 'formula') {
      text(name)
    } else if (name === 'roll') {
      wrapped(name, 'dice')
    } else if (name === 'startMax') {
      wrapped(name, 'formula')
    } else {
      integer(name)
    }
  }

  if (draft.type === 'track') {
    draft.maxMode =
      draft.startMax !== '' ? 'startFormula' : draft.max !== '' ? 'fixed' : 'perCharacter'
  }

  return readable && draft.key !== '' && draft.label !== '' ? draft : null
}

/** The stored options as form state, or null when they are not a list of `{key, label}` objects. */
function readOptions(stored: unknown): OptionDraft[] | null {
  if (!Array.isArray(stored)) {
    return null
  }
  const options: OptionDraft[] = []
  for (const option of stored as unknown[]) {
    if (typeof option !== 'object' || option === null || Array.isArray(option)) {
      return null
    }
    const { key, label, ...rest } = option as Record<string, unknown>
    if (typeof key !== 'string' || typeof label !== 'string' || Object.keys(rest).length > 0) {
      return null
    }
    options.push({ key, label, follow: false })
  }
  return options
}

/** The options to send, or null with the messages found: 1 to 20, each a key (unique) and a label up to 60. */
function writeOptions(
  options: OptionDraft[],
  errors: FieldErrors,
): { key: string; label: string }[] | null {
  if (options.length < 1 || options.length > optionLimits.count) {
    errors.options = `A conditions field has 1 to ${String(optionLimits.count)} options.`
  }
  const found: Record<number, OptionErrors> = {}
  const written = options.map(({ key, label }, index) => {
    const trimmedKey = key.trim()
    const trimmedLabel = label.trim()
    const mine: OptionErrors = {}
    if (trimmedKey === '') {
      mine.key = 'Give the option a key.'
    } else if (options.findIndex((other) => other.key.trim() === trimmedKey) !== index) {
      mine.key = `The key "${trimmedKey}" is used by an earlier option.`
    }
    if (trimmedLabel === '') {
      mine.label = 'Give the option a label.'
    } else if (trimmedLabel.length > optionLimits.label) {
      mine.label = `The label is at most ${String(optionLimits.label)} characters.`
    }
    if (Object.keys(mine).length > 0) {
      found[index] = mine
    }
    return { key: trimmedKey, label: trimmedLabel }
  })
  if (Object.keys(found).length > 0) {
    errors.option = found
  }
  return errors.options === undefined && errors.option === undefined ? written : null
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
      const columns = groups.find((declared) => declared.name === group)?.columns ?? 1
      const span = Number(draft.span)
      if (wholeNumber.test(draft.span) && span >= 1 && span <= columns) {
        field.span = span
      } else {
        errors.span = `Must be a whole number from 1 to ${String(columns)}.`
      }
    }
  } else if (group !== '') {
    field.group = group
  }
  field.type = draft.type

  for (const name of propertiesOf[draft.type]) {
    // A track writes the one of its max and start max that its mode names, and neither for each Character.
    if (
      draft.type === 'track' &&
      ((name === 'max' && draft.maxMode !== 'fixed') ||
        (name === 'startMax' && draft.maxMode !== 'startFormula'))
    ) {
      continue
    }
    if (name === 'options') {
      const options = writeOptions(draft.options, errors)
      if (options !== null) {
        field.options = options
      }
      continue
    }
    const value = draft[name]
    if (typeof value === 'boolean') {
      if (value) {
        field[name] = true
      }
      continue
    }
    const text = value.trim()
    if (text === '') {
      if (name === 'formula' || name === 'startMax') {
        errors[name] = 'Write the formula.'
      }
    } else if (name === 'roll') {
      field.roll = { dice: text }
    } else if (name === 'startMax') {
      field.startMax = { formula: text }
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
