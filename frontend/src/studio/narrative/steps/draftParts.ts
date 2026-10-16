import { parseInteger } from '../../oracles/tableDraft'
import type { FieldErrors } from '../narrativeDraft'
import type { StoredItem } from '../useGameSystemNarrative'

// What the step and effect drafts share: reading stored fields as form state without guessing, and
// the value forms of a band limit, a tracker effect and a sheet effect.

/** `dice` is a sheet effect value only; the publisher says where each form is allowed. */
export type ValueForm = 'integer' | 'tracker' | 'formula' | 'dice'

/** A value: a whole number, a tracker key, a formula or a dice expression, as text. */
export interface ValueDraft {
  form: ValueForm
  text: string
}

export const WHOLE = 'Use a whole number.'

export function isObject(value: unknown): value is StoredItem {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The text of an optional field: '' when absent, null when it holds something else. */
export function textOf(source: StoredItem, field: string): string | null {
  const value = source[field]
  if (value === undefined) {
    return ''
  }
  return typeof value === 'string' ? value : null
}

/** An optional list of strings: empty when absent, null when it holds something else. */
export function stringsOf(source: StoredItem, field: string): string[] | null {
  const value = source[field]
  if (value === undefined) {
    return []
  }
  return Array.isArray(value) && value.every((item) => typeof item === 'string') ? value : null
}

export function listOf(source: StoredItem, field: string): unknown[] | null {
  const value = source[field]
  if (value === undefined) {
    return []
  }
  return Array.isArray(value) ? (value as unknown[]) : null
}

export function unknownFields(source: StoredItem, known: readonly string[]): StoredItem {
  return Object.fromEntries(Object.entries(source).filter(([field]) => !known.includes(field)))
}

/** The value as form state when it takes one of `forms`; null for anything else. */
export function readValue(value: unknown, forms: readonly ValueForm[]): ValueDraft | null {
  if (typeof value === 'number' && Number.isInteger(value)) {
    return forms.includes('integer') ? { form: 'integer', text: String(value) } : null
  }
  if (isObject(value) && Object.keys(value).length === 1) {
    for (const form of ['tracker', 'formula', 'dice'] as const) {
      if (forms.includes(form) && typeof value[form] === 'string') {
        return { form, text: value[form] }
      }
    }
  }
  return null
}

export function valueToStored(value: ValueDraft, path: string, errors: FieldErrors): unknown {
  if (value.form !== 'integer') {
    return { [value.form]: value.text }
  }
  const whole = parseInteger(value.text.trim())
  if (whole === null) {
    errors[path] = WHOLE
  }
  return whole ?? 0
}

/** `items` with the one at `index` moved by `by` places. */
export function moveItem<T>(items: T[], index: number, by: number): T[] {
  const next = [...items]
  const [item] = next.splice(index, 1)
  if (item !== undefined) {
    next.splice(index + by, 0, item)
  }
  return next
}
