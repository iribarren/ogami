import { slugOf } from '../checks/checkDraft'
import {
  blankField,
  fieldLimits,
  writeField,
  type FieldErrors,
  type FieldType,
  type SheetGroup,
  type StoredField,
} from './sheetDraft'

/** The types a paste offers: those valid with a key, a label and a group alone. */
export const pasteTypes = ['number', 'text', 'list'] as const satisfies readonly FieldType[]
export type PasteType = (typeof pasteTypes)[number]

/** What every pasted field shares. `min` and `max` are a number's bounds, blank for none. */
export interface PasteSettings {
  type: PasteType
  group: string
  min: string
  max: string
}

/** One non-blank pasted line: the name, the key it derives, and why it cannot be added (null when it can). */
export interface PastedLine {
  /** The line's number in the pasted text, from 1. */
  line: number
  name: string
  key: string
  error: string | null
}

/** The key a pasted name derives: its slug, cut to the publisher's limit without a trailing hyphen. */
export const keyOfName = (name: string) => slugOf(name).slice(0, fieldLimits.key).replace(/-$/, '')

/**
 * One field per non-blank line, the line being its label. Each line says what stops it, so the
 * author sees every problem before anything is sent: a name that derives no key, a name past
 * the label limit, a key an earlier line or the sheet already has.
 */
export function parseFieldPaste(input: string, existingKeys: string[]): PastedLine[] {
  const taken = new Set(existingKeys)
  const seen = new Map<string, number>()
  const lines: PastedLine[] = []

  input.split(/\r?\n/).forEach((raw, offset) => {
    const name = raw.trim()
    if (name === '') {
      return
    }
    const line = offset + 1
    const key = keyOfName(name)
    let error: string | null = null
    if (key === '') {
      error = 'The name needs a letter or a digit to derive a key from.'
    } else if (name.length > fieldLimits.label) {
      error = `The name is over ${String(fieldLimits.label)} characters.`
    } else if (taken.has(key)) {
      error = `The key "${key}" is already a field of this sheet.`
    } else if (seen.has(key)) {
      error = `The key "${key}" is already used by line ${String(seen.get(key))}.`
    }
    if (key !== '' && !seen.has(key)) {
      seen.set(key, line)
    }
    lines.push({ line, name, key, error })
  })

  return lines
}

/**
 * The field a pasted line becomes: through the form's own writer, so it is exactly what the New
 * field dialog would send. A message per shared setting when the settings are not valid.
 */
export function pastedField(
  { name, key }: Pick<PastedLine, 'name' | 'key'>,
  settings: PasteSettings,
  groups: SheetGroup[] | null,
): { field: StoredField } | { errors: FieldErrors } {
  const draft = blankField(groups)
  draft.key = key
  draft.label = name
  draft.type = settings.type
  draft.group = settings.group
  if (settings.type === 'number') {
    draft.min = settings.min
    draft.max = settings.max
  }
  return writeField(draft, groups)
}
