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

/**
 * One entry per non-blank line. Ranged tables read a leading range; lines without one follow on
 * from the previous range (one total each). Weighted tables give every line weight 1 and keep a
 * leading number in the text. Any bad line fails the whole paste: nothing is guessed or fixed.
 */
export function parsePaste(draft: TableDraft, input: string, mode: PasteMode): PasteResult {
  const entries: PastedEntry[] = []
  const errors: PasteError[] = []
  let next = draft.ranged ? pasteStart(draft, mode) : null

  input.split(/\r?\n/).forEach((raw, offset) => {
    const line = raw.trim()
    const number = offset + 1
    if (line === '') {
      return
    }
    if (!draft.ranged) {
      entries.push({ text: line, weight: 1 })
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
      entries.push({ text: line, min: next, max: next })
      next += 1
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
    entries.push({ text: match[3] ?? '', min, max })
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
    const made = toEntryDraft({ text: entry.text }, firstId + offset)
    return draft.ranged
      ? { ...made, min: String(entry.min ?? ''), max: String(entry.max ?? '') }
      : { ...made, weight: String(entry.weight ?? 1) }
  })
  return mode === 'replace' ? added : [...draft.entries, ...added]
}
