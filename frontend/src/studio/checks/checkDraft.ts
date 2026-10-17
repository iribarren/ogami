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
 * The form state of a band's bound. A bound of a shape the form has no input for is `unknown`: it
 * is shown as stored and sent back unchanged, never dropped or guessed.
 */
export type UpToDraft =
  | { kind: 'none' }
  | { kind: 'number' | 'tracker' | 'formula'; value: string }
  | { kind: 'unknown'; raw: unknown }

/** The form state of one band; a number bound is text until the form is built. */
export interface BandDraft {
  key: string
  label: string
  upTo: UpToDraft
}

/** The most bands a check may have, as the publisher. */
export const maxBands = 10

/** The form state of one check. */
export interface CheckDraft {
  key: string
  label: string
  hint: string
  dice: string
  modifier: string
  /** Null while the check has no difficulty. */
  difficulty: DifficultyDraft | null
  bands: BandDraft[]
}

/** A message per band input. */
export type BandErrors = Partial<Record<'key' | 'label' | 'upTo', string>>

/** The messages of the bands' inputs, by the band's position. */
export type BandErrorMap = Record<number, BandErrors>

/**
 * Two sets of band messages as one, field by field: a band both name keeps the messages of every
 * input, and the later set wins only for an input both have a message for.
 */
export function mergeBandErrors(first: BandErrorMap, second: BandErrorMap): BandErrorMap {
  const merged: BandErrorMap = { ...first }
  for (const [position, found] of Object.entries(second)) {
    merged[Number(position)] = { ...merged[Number(position)], ...found }
  }
  return merged
}

/**
 * The band messages after the bands were rearranged: `order` lists, for each band now, the
 * position it had before (null for a band just added). A message follows its band; a removed
 * band's is dropped and the later ones shift; a new band starts with none.
 */
export function followBands(errors: BandErrorMap, order: (number | null)[]): BandErrorMap {
  const followed: BandErrorMap = {}
  order.forEach((before, position) => {
    const found = before === null ? undefined : errors[before]
    if (found !== undefined) {
      followed[position] = found
    }
  })
  return followed
}

/** A message per input, keyed like the draft; the difficulty inputs are `difficulty<Name>`. */
export type CheckErrors = Partial<
  Record<
    | 'bands'
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
> & {
  /** The messages of each band's inputs, by the band's position. */
  band?: BandErrorMap
}

export const blankDifficulty = (): DifficultyDraft => ({
  label: '',
  default: '',
  min: '',
  max: '',
})

export const blankBand = (): BandDraft => ({ key: '', label: '', upTo: { kind: 'none' } })

/** A new check starts with the one band every check needs. */
export function blankCheck(): CheckDraft {
  return {
    key: '',
    label: '',
    hint: '',
    dice: '',
    modifier: '',
    difficulty: null,
    bands: [{ key: 'outcome', label: 'Outcome', upTo: { kind: 'none' } }],
  }
}

const isObject = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

/** The bound as form state; a shape the form cannot hold is kept whole as `unknown`. */
function readUpTo(upTo: unknown): UpToDraft {
  if (upTo === undefined || upTo === null) {
    return { kind: 'none' }
  }
  if (typeof upTo === 'number' && Number.isInteger(upTo)) {
    return { kind: 'number', value: String(upTo) }
  }
  if (isObject(upTo)) {
    const keys = Object.keys(upTo)
    if (keys.length === 1 && keys[0] === 'tracker' && typeof upTo.tracker === 'string') {
      return { kind: 'tracker', value: upTo.tracker }
    }
    if (keys.length === 1 && keys[0] === 'formula' && typeof upTo.formula === 'string') {
      return { kind: 'formula', value: upTo.formula }
    }
  }
  return { kind: 'unknown', raw: upTo }
}

/** The stored band as form state, or null when it is not an object with a text key and label and nothing else. */
function readBand(band: unknown): BandDraft | null {
  if (!isObject(band) || typeof band.key !== 'string' || typeof band.label !== 'string') {
    return null
  }
  if (Object.keys(band).some((name) => !['key', 'label', 'upTo'].includes(name))) {
    return null
  }
  return { key: band.key, label: band.label, upTo: readUpTo(band.upTo) }
}

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
  const readBands = bands.map(readBand)
  if (readBands.includes(null)) {
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
    bands: readBands as BandDraft[],
  }
}

/** A value written with its keys in order, so two checks that differ only by key order match. */
function canonical(value: unknown): string {
  if (Array.isArray(value)) {
    return `[${value.map(canonical).join(',')}]`
  }
  if (isObject(value)) {
    return `{${Object.keys(value)
      .sort()
      .map((name) => `${JSON.stringify(name)}:${canonical(value[name])}`)
      .join(',')}}`
  }
  return JSON.stringify(value)
}

/** Whether two stored checks say the same, whatever the order of their properties. */
export function sameCheck(first: StoredCheck, second: StoredCheck): boolean {
  return canonical(first) === canonical(second)
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
  const bands = writeBands(draft.bands, errors)
  if (bands !== null) {
    check.bands = bands
  }

  return Object.keys(errors).length > 0 ? { errors } : { check }
}

/**
 * The bands to send, in order, or null with the errors found. The last band catches the rest, so
 * its bound is left out (the editor says so); every other band needs one. A bound of an unknown
 * shape is sent as it was stored.
 */
function writeBands(bands: BandDraft[], errors: CheckErrors): unknown[] | null {
  if (bands.length < 1 || bands.length > maxBands) {
    errors.bands = `A check has 1 to ${String(maxBands)} bands.`
    return null
  }
  const written: unknown[] = []
  bands.forEach((band, index) => {
    const found: BandErrors = {}
    const key = band.key.trim()
    const label = band.label.trim()
    if (key === '') {
      found.key = 'Give the band a key.'
    }
    if (label === '') {
      found.label = 'Give the band a label.'
    }
    const out: Record<string, unknown> = { key, label }
    if (index < bands.length - 1) {
      const upTo = writeUpTo(band.upTo)
      if ('error' in upTo) {
        found.upTo = upTo.error
      } else if (upTo.upTo !== undefined) {
        out.upTo = upTo.upTo
      }
    }
    if (Object.keys(found).length > 0) {
      errors.band = { ...errors.band, [index]: found }
    }
    written.push(out)
  })
  return errors.band === undefined ? written : null
}

/** The bound of a band that is not the last: what it says, or what is missing. */
function writeUpTo(upTo: UpToDraft): { upTo: unknown } | { error: string } {
  switch (upTo.kind) {
    case 'none':
      return { error: 'Choose a bound: only the last band catches the rest.' }
    case 'unknown':
      return { upTo: upTo.raw }
    case 'number': {
      const text = upTo.value.trim()
      if (text === '') {
        return { error: 'Give the band a number.' }
      }
      const value = integerOf(text)
      return value === null ? { error: 'Must be a whole number.' } : { upTo: value }
    }
    case 'tracker':
      return upTo.value === '' ? { error: 'Choose a tracker.' } : { upTo: { tracker: upTo.value } }
    case 'formula': {
      const formula = upTo.value.trim()
      return formula === '' ? { error: 'Write the formula.' } : { upTo: { formula } }
    }
  }
}
