/** A check as stored in the draft: the publisher validates it, the editor keeps nothing else. */
export type StoredCheck = Record<string, unknown>

/** The form state of a check's difficulty: every input is text, and an empty min or max is not sent. */
export interface DifficultyDraft {
  label: string
  default: string
  min: string
  max: string
}

/**
 * The form state of one check. The bands are not edited yet: they are kept exactly as stored, so
 * a save sends them back unchanged.
 */
export interface CheckDraft {
  key: string
  label: string
  hint: string
  dice: string
  modifier: string
  /** Null while the check has no difficulty. */
  difficulty: DifficultyDraft | null
  bands: unknown[]
}

/** A message per input, keyed like the draft; the difficulty inputs are `difficulty<Name>`. */
export type CheckErrors = Partial<
  Record<
    | 'key'
    | 'label'
    | 'hint'
    | 'dice'
    | 'modifier'
    | 'difficulty'
    | 'difficultyLabel'
    | 'difficultyDefault'
    | 'difficultyMin'
    | 'difficultyMax',
    string
  >
>

export const blankDifficulty = (): DifficultyDraft => ({
  label: '',
  default: '',
  min: '',
  max: '',
})

/** A new check starts with the one band every check needs. */
export function blankCheck(): CheckDraft {
  return {
    key: '',
    label: '',
    hint: '',
    dice: '',
    modifier: '',
    difficulty: null,
    bands: [{ key: 'outcome', label: 'Outcome' }],
  }
}

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

/** The stored check as form state, or null when a property has a shape the form cannot hold. */
export function readCheck(stored: StoredCheck): CheckDraft | null {
  const { key, label, hint, dice, modifier, difficulty, bands } = stored
  if (typeof key !== 'string' || typeof label !== 'string' || typeof dice !== 'string') {
    return null
  }
  const optionalText = (value: unknown) =>
    value === undefined || value === null || typeof value === 'string'
  if (!optionalText(hint) || !optionalText(modifier) || !Array.isArray(bands)) {
    return null
  }

  let read: DifficultyDraft | null = null
  if (difficulty !== undefined && difficulty !== null) {
    if (!isObject(difficulty) || typeof difficulty.label !== 'string') {
      return null
    }
    const integer = (value: unknown) =>
      value === undefined || value === null || Number.isInteger(value)
    if (
      !Number.isInteger(difficulty.default) ||
      !integer(difficulty.min) ||
      !integer(difficulty.max)
    ) {
      return null
    }
    const text = (value: unknown) => (typeof value === 'number' ? String(value) : '')
    read = {
      label: difficulty.label,
      default: text(difficulty.default),
      min: text(difficulty.min),
      max: text(difficulty.max),
    }
  }

  return {
    key,
    label,
    hint: typeof hint === 'string' ? hint : '',
    dice,
    modifier: typeof modifier === 'string' ? modifier : '',
    difficulty: read,
    bands: bands as unknown[],
  }
}

const wholeNumber = /^-?\d+$/

/** A whole number the text spells exactly, or null. */
function integerOf(text: string): number | null {
  const value = Number(text)
  return wholeNumber.test(text) && Number.isSafeInteger(value) ? value : null
}

/**
 * The check to send, or what is wrong with the form. Empty optional inputs are left out, never
 * replaced by a default; the publisher checks the rest (ranges, key format, dice, formulas).
 */
export function writeCheck(draft: CheckDraft): { check: StoredCheck } | { errors: CheckErrors } {
  const errors: CheckErrors = {}
  const check: StoredCheck = {}

  const key = draft.key.trim()
  const label = draft.label.trim()
  const dice = draft.dice.trim()
  if (key === '') {
    errors.key = 'Give the check a key.'
  }
  if (label === '') {
    errors.label = 'Give the check a label.'
  }
  if (dice === '') {
    errors.dice = 'Write the dice.'
  }
  check.key = key
  check.label = label
  const hint = draft.hint.trim()
  if (hint !== '') {
    check.hint = hint
  }
  check.dice = dice
  const modifier = draft.modifier.trim()
  if (modifier !== '') {
    check.modifier = modifier
  }

  if (draft.difficulty !== null) {
    const difficulty: Record<string, unknown> = {}
    const name = draft.difficulty.label.trim()
    if (name === '') {
      errors.difficultyLabel = 'Give the difficulty a label.'
    }
    difficulty.label = name

    const initial = draft.difficulty.default.trim()
    if (initial === '') {
      errors.difficultyDefault = 'Give the difficulty a default.'
    } else {
      const value = integerOf(initial)
      if (value === null) {
        errors.difficultyDefault = 'Must be a whole number.'
      } else {
        difficulty.default = value
      }
    }
    for (const bound of ['min', 'max'] as const) {
      const text = draft.difficulty[bound].trim()
      if (text === '') {
        continue
      }
      const value = integerOf(text)
      if (value === null) {
        errors[bound === 'min' ? 'difficultyMin' : 'difficultyMax'] = 'Must be a whole number.'
      } else {
        difficulty[bound] = value
      }
    }
    check.difficulty = difficulty
  }
  check.bands = draft.bands

  return Object.keys(errors).length > 0 ? { errors } : { check }
}

/** One band as the tab shows it, read-only. */
export interface BandSummary {
  key: string
  label: string
  bound: string
}

/** The bands as stored, each with its bound said in words; one of an unknown shape is "unreadable". */
export function describeBands(bands: unknown[]): BandSummary[] {
  return bands.map((band) => {
    if (!isObject(band) || typeof band.key !== 'string' || typeof band.label !== 'string') {
      return { key: '?', label: '?', bound: 'unreadable' }
    }
    const { key, label, upTo } = band
    return { key, label, bound: bound(upTo) }
  })
}

function bound(upTo: unknown): string {
  if (upTo === undefined || upTo === null) {
    return 'catches the rest'
  }
  if (typeof upTo === 'number') {
    return `up to ${String(upTo)}`
  }
  if (isObject(upTo) && typeof upTo.tracker === 'string') {
    return `up to the tracker ${upTo.tracker}`
  }
  if (isObject(upTo) && typeof upTo.formula === 'string') {
    return `up to ${upTo.formula}`
  }
  return 'unreadable'
}
