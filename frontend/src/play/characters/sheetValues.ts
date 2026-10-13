import type { SheetField, SheetValue } from './useCharacters'

/**
 * What a field holds while it is edited: text as typed, so a number can be empty for a moment
 * (the browser then refuses the form), the rows of a list, a track's current and max.
 */
export type Draft = string | string[] | { current: string; max: string }

/**
 * The fields a Character stores a value for: every field but the derived ones, which the API
 * computes and refuses a value for.
 */
export function storedFields(sheet: SheetField[]): SheetField[] {
  return sheet.filter((field) => field.type !== 'derived')
}

/** The value of `field`: the stored one, or its default when there is none or it has another shape. */
export function valueOrDefault(field: SheetField, stored: SheetValue | undefined): SheetValue {
  switch (field.type) {
    case 'number':
      return typeof stored === 'number' ? stored : (field.default ?? 0)
    case 'text':
      return typeof stored === 'string' ? stored : ''
    case 'list':
      return Array.isArray(stored) ? stored : []
    case 'track':
      return typeof stored === 'object' && !Array.isArray(stored)
        ? stored
        : { current: field.max ?? 0, max: field.max ?? 0 }
    case 'derived':
      // Never stored: storedFields() leaves derived fields out of the form and the summary.
      return 0
  }
}

export function toDraft(field: SheetField, stored: SheetValue | undefined): Draft {
  const value = valueOrDefault(field, stored)
  if (typeof value === 'number') {
    return String(value)
  }
  if (typeof value === 'object' && !Array.isArray(value)) {
    return { current: String(value.current), max: String(value.max) }
  }
  return value
}

/** The value to send for a draft; blank list rows are dropped. */
export function fromDraft(field: SheetField, draft: Draft): SheetValue {
  if (typeof draft === 'string') {
    return field.type === 'number' ? Number(draft) : draft
  }
  if (Array.isArray(draft)) {
    return draft.map((item) => item.trim()).filter((item) => item !== '')
  }
  return { current: Number(draft.current), max: Number(draft.max) }
}

/** One short phrase for a field's value, as the Characters list shows it. */
export function summarize(
  field: SheetField,
  stored: SheetValue | undefined,
  derived: Record<string, number | null> = {},
): string | null {
  if (field.type === 'derived') {
    // Computed by the server, never here.
    return `${field.label}: ${String(derived[field.key] ?? '—')}`
  }
  const value = valueOrDefault(field, stored)
  if (typeof value === 'string') {
    const shown = value.length > 40 ? `${value.slice(0, 40)}…` : value
    return value === '' ? null : `${field.label}: ${shown}`
  }
  if (Array.isArray(value)) {
    return `${field.label}: ${String(value.length)}`
  }
  if (typeof value === 'object') {
    return `${field.label}: ${String(value.current)}/${String(value.max)}`
  }
  return `${field.label}: ${String(value)}`
}
