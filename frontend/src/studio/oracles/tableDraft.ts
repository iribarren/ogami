import type { components } from '@/shared/api/schema'

export type OracleTable = components['schemas']['GameSystemOracleTable']
export type OracleEntry = components['schemas']['GameSystemOracleTableEntry']

/** The entry as stored: a field the editor does not know stays in it and is sent back. */
export type StoredEntry = Partial<OracleEntry> & Record<string, unknown>

/** The most totals a dice notation may span for the editor to compute coverage. */
const MAX_TOTALS = 10_000

/** A table to send: the API documents some entry fields as required that a plain entry lacks. */
export type SaveTable = Omit<OracleTable, 'entries'> & { entries: StoredEntry[] }

export interface DiceRange {
  min: number
  max: number
}

/**
 * The totals `NdM`, `dM` or `d%` can roll; null for any other notation (keep/drop selectors,
 * arithmetic): the server still validates those, the editor just cannot show coverage.
 */
export function diceRange(dice: string): DiceRange | null {
  const match = /^(\d*)d(\d+|%)$/i.exec(dice.trim())
  if (!match) {
    return null
  }
  const count = match[1] === '' ? 1 : Number(match[1])
  const sides = match[2] === '%' ? 100 : Number(match[2])
  if (count < 1 || sides < 1 || count * sides > MAX_TOTALS) {
    return null
  }
  return { min: count, max: count * sides }
}

/** A whole number, or null: the editor never guesses what unreadable text meant. */
export function parseInteger(text: string): number | null {
  return /^-?\d{1,9}$/.test(text) ? Number(text) : null
}

export interface Span {
  from: number
  to: number
}

export interface Coverage {
  /** Totals no entry covers. */
  gaps: Span[]
  /** Totals more than one entry covers. */
  overlaps: Span[]
  /** Indexes of entries reaching outside the dice. */
  outside: number[]
  /** Indexes of entries with a missing bound or min above max. */
  invalid: number[]
}

/** The runs of consecutive totals whose count satisfies `matches`. */
function runs(range: DiceRange, counts: number[], matches: (count: number) => boolean): Span[] {
  const spans: Span[] = []
  counts.forEach((count, offset) => {
    if (!matches(count)) {
      return
    }
    const total = range.min + offset
    const last = spans.at(-1)
    if (last?.to === total - 1) {
      last.to = total
    } else {
      spans.push({ from: total, to: total })
    }
  })
  return spans
}

/** How the entries' ranges cover the dice: gaps, overlaps and entries that do not fit. */
export function coverage(
  range: DiceRange,
  entries: { min: number | null; max: number | null }[],
): Coverage {
  const counts = Array.from({ length: range.max - range.min + 1 }, () => 0)
  const outside: number[] = []
  const invalid: number[] = []
  entries.forEach(({ min, max }, index) => {
    if (min === null || max === null || min > max) {
      invalid.push(index)
      return
    }
    if (min < range.min || max > range.max) {
      outside.push(index)
    }
    for (let total = Math.max(min, range.min); total <= Math.min(max, range.max); total += 1) {
      counts[total - range.min] = (counts[total - range.min] ?? 0) + 1
    }
  })
  return {
    gaps: runs(range, counts, (count) => count === 0),
    overlaps: runs(range, counts, (count) => count > 1),
    outside,
    invalid,
  }
}

export interface EntryDraft {
  /** Stable while the list is edited, for React keys. */
  id: number
  original: StoredEntry
  text: string
  min: string
  max: string
  weight: string
  table: string
  key: string
}

export interface TableDraft {
  key: string
  name: string
  ranged: boolean
  dice: string
  entries: EntryDraft[]
}

const text = (value: unknown) => (typeof value === 'string' ? value : '')
const number = (value: unknown) => (typeof value === 'number' ? String(value) : '')

export function toEntryDraft(entry: StoredEntry, id: number): EntryDraft {
  return {
    id,
    original: entry,
    text: text(entry.text),
    min: number(entry.min),
    max: number(entry.max),
    weight: number(entry.weight),
    table: text(entry.table),
    key: text(entry.key),
  }
}

export function toDraft(table: OracleTable): TableDraft {
  return {
    key: table.key,
    name: table.name,
    ranged: typeof table.dice === 'string',
    dice: text(table.dice),
    entries: table.entries.map((entry, index) => toEntryDraft(entry, index)),
  }
}

const defaultText = 'New entry'

/**
 * The table a new row starts from: ranged, one entry covering all the dice, or weighted, one
 * entry of weight 1, so the section stays valid. Null when the dice are not `NdM`.
 */
export function newTable(
  key: string,
  name: string,
  ranged: boolean,
  dice: string,
): SaveTable | null {
  if (!ranged) {
    return { key, name, entries: [{ weight: 1, text: defaultText }] }
  }
  const range = diceRange(dice)
  return range && { key, name, dice: dice.trim(), entries: [{ ...range, text: defaultText }] }
}

/** An empty field is absent; a field the editor does not edit is left as stored. */
function put(target: Record<string, unknown>, field: string, value: unknown) {
  if (value === undefined || value === '') {
    // eslint-disable-next-line @typescript-eslint/no-dynamic-delete
    delete target[field]
  } else {
    target[field] = value
  }
}

/** Whether every number the draft needs is a whole number (a weight may stay empty). */
export function numbersValid(draft: TableDraft) {
  return draft.entries.every((entry) =>
    draft.ranged
      ? parseInteger(entry.min) !== null && parseInteger(entry.max) !== null
      : entry.weight === '' || parseInteger(entry.weight) !== null,
  )
}

/**
 * The table to save: the stored one with the edits laid over it, so every field the editor does
 * not know survives. The entry key, a schema version 2 field, is only sent from a version 2 draft.
 */
export function toTable(
  original: OracleTable,
  draft: TableDraft,
  schemaVersion: number,
): SaveTable {
  const table: Record<string, unknown> = { ...original, key: draft.key, name: draft.name }
  put(table, 'dice', draft.ranged ? draft.dice.trim() : undefined)
  table.entries = draft.entries.map((entry) => {
    const stored: Record<string, unknown> = { ...entry.original }
    put(stored, 'text', entry.text)
    put(stored, 'table', entry.table)
    if (schemaVersion >= 2) {
      put(stored, 'key', entry.key)
    }
    put(stored, 'min', draft.ranged ? (parseInteger(entry.min) ?? undefined) : undefined)
    put(stored, 'max', draft.ranged ? (parseInteger(entry.max) ?? undefined) : undefined)
    put(stored, 'weight', draft.ranged ? undefined : (parseInteger(entry.weight) ?? undefined))
    return stored
  })
  // The stored table has fields the generated type does not list.
  return table as SaveTable
}
