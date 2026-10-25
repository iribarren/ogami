import type { SheetFieldView, SheetValue } from './types'

/**
 * What a field holds while it is edited: text as typed, so a number can be empty for a moment
 * (the browser then refuses the form), the rows of a list, the marked option keys of a conditions
 * field, a track's current and max.
 */
export type Draft = string | string[] | { current: string; max: string }

/**
 * The fields a Character stores a value for: every field but the derived ones, which the API
 * computes and refuses a value for.
 */
export function storedFields(sheet: SheetFieldView[]): SheetFieldView[] {
  return sheet.filter((field) => field.type !== 'derived')
}

/** The value of `field`: the stored one, or its default when there is none or it has another shape. */
export function valueOrDefault(field: SheetFieldView, stored: SheetValue | undefined): SheetValue {
  switch (field.type) {
    case 'number':
      return typeof stored === 'number' ? stored : (field.default ?? 0)
    case 'text':
      return typeof stored === 'string' ? stored : ''
    case 'list':
    case 'conditions':
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

export function toDraft(field: SheetFieldView, stored: SheetValue | undefined): Draft {
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
export function fromDraft(field: SheetFieldView, draft: Draft): SheetValue {
  if (typeof draft === 'string') {
    return field.type === 'number' ? Number(draft) : draft
  }
  if (Array.isArray(draft)) {
    return draft.map((item) => item.trim()).filter((item) => item !== '')
  }
  return { current: Number(draft.current), max: Number(draft.max) }
}

const summaryLength = 40

function cut(text: string): string {
  return text.length > summaryLength ? `${text.slice(0, summaryLength)}…` : text
}

/**
 * The items of a list that fit the summary budget, joined; an item that alone exceeds it is cut,
 * and `+N` counts the items left out.
 */
function summarizeItems(items: string[]): string {
  const shown: string[] = []
  let length = 0
  for (const item of items) {
    length += item.length + (shown.length > 0 ? 2 : 0)
    if (length > summaryLength) {
      break
    }
    shown.push(item)
  }
  if (shown.length === 0) {
    shown.push(cut(items[0] ?? ''))
  }
  const rest = items.length - shown.length
  return rest > 0 ? `${shown.join(', ')} +${String(rest)}` : shown.join(', ')
}

/** The labels of the options a conditions field marks, in declared order; a key the field no longer declares is left out. */
export function markedLabels(field: SheetFieldView, marked: string[]): string[] {
  return (field.options ?? []).filter(({ key }) => marked.includes(key)).map(({ label }) => label)
}

/** One short phrase for a field's value, as the Characters list shows it. */
export function summarize(
  field: SheetFieldView,
  stored: SheetValue | undefined,
  derived: Record<string, number | null> = {},
): string | null {
  if (field.type === 'derived') {
    // Computed by the server, never here.
    return `${field.label}: ${String(derived[field.key] ?? '—')}`
  }
  const value = valueOrDefault(field, stored)
  if (typeof value === 'string') {
    return value === '' ? null : `${field.label}: ${cut(value)}`
  }
  if (field.type === 'conditions' && Array.isArray(value)) {
    const labels = markedLabels(field, value)
    return `${field.label}: ${labels.length === 0 ? 'none' : summarizeItems(labels)}`
  }
  if (Array.isArray(value)) {
    const items = value.filter((item) => item.trim() !== '')
    return `${field.label}: ${items.length === 0 ? '0' : summarizeItems(items)}`
  }
  if (typeof value === 'object') {
    return `${field.label}: ${String(value.current)}/${String(value.max)}`
  }
  return `${field.label}: ${String(value)}`
}
