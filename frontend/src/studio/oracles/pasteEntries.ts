import { diceRange, nextTotal, toEntryDraft, type EntryDraft, type TableDraft } from './tableDraft'

/** Whether pasted entries take the place of the entries or follow the last one. */
export type PasteMode = 'replace' | 'append'

export interface PastedEntry {
  text: string
  /** A ranged table's range. */
  min?: number
  max?: number
  /** A weighted table's weight. */
  weight?: number
  /** The entry key, only on a schema version with entry keys. */
  key?: string
}

export interface PasteError {
  line: number
  message: string
}

export interface PasteResult {
  entries: PastedEntry[]
  errors: PasteError[]
}

/**
 * The total a ranged table's first unnumbered pasted line takes: the dice minimum (1 when the
 * dice are unknown) when replacing, one after the last max when adding. Null when the last max is
 * not a whole number: there is nothing to follow on from.
 */
export function pasteStart(draft: TableDraft, mode: PasteMode): number | null {
  return mode === 'replace' ? (diceRange(draft.dice)?.min ?? 1) : nextTotal(draft)
}

/**
 * An optional range (`N` or `N-M`, hyphen or en dash), then a separator or space, then the text.
 * A dot separates when whitespace or a non-digit follows it ("1.Rain"), so "3.5 kg" is text, not range 3.
 */
const RANGED_LINE = /^(\d{1,9})(?:\s*[-–]\s*(\d{1,9}))?(?:\s*[:)]\s*|\s*\.(?=\s|\D)\s*|\s+)(\S.*)$/
/** A line that is only a range, with or without its separator. */
const RANGE_ONLY = /^\d{1,9}(?:\s*[-–]\s*\d{1,9})?\s*[.:)]?$/

/** The key rule of every Studio key (the entry key field is checked by the server, which stays the authority). */
const KEY_RULE = /^[a-z0-9-]{1,64}$/
/** An optional `[key]` at the start of the entry text, then the text. */
const KEYED_TEXT = /^\[([^\]]*)\]\s*(.*)$/

/**
 * One entry per non-blank line. Ranged tables read a leading range; lines without one follow on
 * from the previous range (one total each). Weighted tables give every line weight 1 and keep a
 * leading number in the text. With `withKey` (the schema version has entry keys) the text may start
 * with `[key]` (`\[` at the start is a literal bracket; without `withKey` brackets are plain text); a key must follow the key rule and be new to the paste and, when adding, to the table.
 * Any bad line fails the whole paste: nothing is guessed or fixed.
 */
export function parsePaste(
  draft: TableDraft,
  input: string,
  mode: PasteMode,
  withKey = false,
): PasteResult {
  const entries: PastedEntry[] = []
  const errors: PasteError[] = []
  const seen = new Map<string, number>()
  const inTable = new Set(
    mode === 'append' ? draft.entries.map(({ key }) => key).filter((key) => key !== '') : [],
  )

  /** Reads the optional `[key]` off an entry's text; null once the line has failed. */
  function keyed(number: number, text: string): { text: string; key?: string } | null {
    const fail = (message: string) => {
      errors.push({ line: number, message: `Line ${String(number)}: ${message}` })
      return null
    }
    // Without entry keys, brackets are plain text.
    if (!withKey) {
      return { text }
    }
    if (text.startsWith('\\[')) {
      // An escaped bracket is a literal one: the backslash goes.
      return { text: text.slice(1) }
    }
    if (!text.startsWith('[')) {
      return { text }
    }
    const match = KEYED_TEXT.exec(text)
    const key = match?.[1] ?? ''
    if (!match || !KEY_RULE.test(key)) {
      return fail(
        'the text in brackets at the start is not a valid entry key (lower case letters, digits and hyphens, up to 64). To start the text with a bracket, write \\[.',
      )
    }
    const rest = match[2] ?? ''
    if (rest === '') {
      return fail('add the entry text after the key.')
    }
    const first = seen.get(key)
    if (first !== undefined) {
      return fail(`the key "${key}" is already used on line ${String(first)}.`)
    }
    if (inTable.has(key)) {
      return fail(`the key "${key}" is already in the table.`)
    }
    seen.set(key, number)
    return { text: rest, key }
  }

  let next = draft.ranged ? pasteStart(draft, mode) : null

  input.split(/\r?\n/).forEach((raw, offset) => {
    const line = raw.trim()
    const number = offset + 1
    if (line === '') {
      return
    }
    if (!draft.ranged) {
      const read = keyed(number, line)
      if (read) {
        entries.push({ ...read, weight: 1 })
      }
      return
    }
    const match = RANGED_LINE.exec(line)
    if (!match && RANGE_ONLY.test(line) && /[-–.:)]/.test(line)) {
      errors.push({
        line: number,
        message: `Line ${String(number)}: add the entry text after the range.`,
      })
      return
    }
    if (!match) {
      if (next === null) {
        errors.push({
          line: number,
          message: `Line ${String(number)}: no range to follow on from; start the line with one like 1-3.`,
        })
        return
      }
      const read = keyed(number, line)
      if (read) {
        entries.push({ ...read, min: next, max: next })
        next += 1
      }
      return
    }
    const min = Number(match[1])
    const max = match[2] === undefined ? min : Number(match[2])
    if (min > max) {
      errors.push({
        line: number,
        message: `Line ${String(number)}: the range ${String(min)}–${String(max)} has its min above its max.`,
      })
      return
    }
    const read = keyed(number, match[3] ?? '')
    if (read) {
      entries.push({ ...read, min, max })
    }
    next = max + 1
  })

  return { entries: errors.length > 0 ? [] : entries, errors }
}

/** The draft's entries after the paste: replaced, or followed by the pasted ones with fresh ids. */
export function applyPaste(
  draft: TableDraft,
  pasted: PastedEntry[],
  mode: PasteMode,
  firstId: number,
): EntryDraft[] {
  const added = pasted.map((entry, offset): EntryDraft => {
    const made = { ...toEntryDraft({ text: entry.text }, firstId + offset), key: entry.key ?? '' }
    return draft.ranged
      ? { ...made, min: String(entry.min ?? ''), max: String(entry.max ?? '') }
      : { ...made, weight: String(entry.weight ?? 1) }
  })
  return mode === 'replace' ? added : [...draft.entries, ...added]
}
