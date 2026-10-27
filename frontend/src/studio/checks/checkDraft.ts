import { effectsToDrafts, effectsToStored, type EffectDraft } from '../narrative/steps/effectDraft'
import type { StepError } from '../narrative/steps/stepError'

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
  /**
   * Set on a band added in the form: its key follows its label as a slug until the key is edited.
   * A stored band never has it, so renaming one never rewrites its key. Never sent.
   */
  follow?: boolean
}

/** The most bands a check may have, as the publisher. */
export const maxBands = 10

/**
 * The form state of a pool: every input is text. The count is one input: digits are an integer,
 * anything else a formula over the sheet. The Hunger count is the same kind of input. An empty
 * criticals bonus or Hunger count (or one never set) is a pool without it: nothing is sent.
 */
export interface PoolDraft {
  count: string
  sides: string
  successAt: string
  /** The successes a pair of top faces adds (ADR 0044). */
  criticalsBonus?: string
  /** The dice of the pool that are Hunger dice: never re-rolled (ADR 0044). */
  hunger?: string
}

/** The most characters a push's cost label may have, as the publisher. */
const maxPushLabel = 100

/**
 * The form state of a push: what it costs in words and the effects of the cost. An effect the
 * shared editor cannot read stays as stored in the list.
 */
export interface PushDraft {
  label: string
  effects: EffectDraft[]
  /** The most failed dice a push re-rolls; empty (or never set) re-rolls every failed regular die. */
  maxDice?: string
}

/** How a check rolls: its dice notation and modifier, or a pool of dice counted for successes. */
export type CheckMode = 'dice' | 'pool'

/** The pool's limits, as the publisher. */
const poolLimits = { count: 100, formula: 200, sides: 1000, bonus: 10 }

/** The form state of one check. */
export interface CheckDraft {
  key: string
  label: string
  hint: string
  /** Empty for a pool check, which has no dice. */
  dice: string
  /** Not sent for a pool check, which refuses a modifier. */
  modifier: string
  /** Null for a dice check. */
  pool: PoolDraft | null
  /** Null while the check cannot be pushed; only a pool check has one. */
  push: PushDraft | null
  /** The stored check had `push: null`, which a save writes back as it was while there is no push. */
  storedNullPush?: boolean
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
    | 'poolCount'
    | 'poolSides'
    | 'poolSuccessAt'
    | 'poolCriticalsBonus'
    | 'poolHunger'
    | 'pushMaxDice'
    | 'pushLabel'
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
  /** The messages about the effects of the push, as the effects editor takes them. */
  pushEffects?: StepError[]
}

export const blankDifficulty = (): DifficultyDraft => ({
  label: '',
  default: '',
  min: '',
  max: '',
})

/** The key a label suggests: lower case letters and digits, runs of anything else as one hyphen. */
export function slugOf(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

/** A band added in the form: its key follows its label, and its bound starts as a number. */
export const newBand = (): BandDraft => ({
  key: '',
  label: '',
  upTo: { kind: 'number', value: '' },
  follow: true,
})

/**
 * The bands with a new one at the end, and the position each band had before (null for the new
 * one). The band that was last had no bound, but now it needs one: it starts as a number too, so
 * nothing has to be switched by hand first. Any bound it already had stays.
 */
export function addBand(bands: BandDraft[]): { bands: BandDraft[]; order: (number | null)[] } {
  const last = bands.length - 1
  const kept = bands.map((band, at) =>
    at === last && band.upTo.kind === 'none'
      ? { ...band, upTo: { kind: 'number' as const, value: '' } }
      : band,
  )
  return { bands: [...kept, newBand()], order: [...bands.keys(), null] }
}

/** The band with a new label; the key follows it while the band has not had its key edited. */
export function editBandLabel(band: BandDraft, label: string): BandDraft {
  return band.follow === true ? { ...band, label, key: slugOf(label) } : { ...band, label }
}

/** The band with a key typed by hand, which stops it following the label. */
export function editBandKey(band: BandDraft, key: string): BandDraft {
  return { ...band, key, follow: false }
}

/** The ready-made bands the "Use a preset" menu offers; each call gives bands of its own. */
export const bandPresets: { id: string; label: string; bands: () => BandDraft[] }[] = [
  {
    id: 'miss-partial-full',
    label: 'Miss / partial / full',
    bands: () => [
      { key: 'miss', label: 'Miss', upTo: { kind: 'number', value: '6' } },
      { key: 'partial', label: 'Partial hit', upTo: { kind: 'number', value: '9' } },
      { key: 'full', label: 'Full hit', upTo: { kind: 'none' } },
    ],
  },
  {
    id: 'failure-success',
    label: 'Failure / success',
    // The bound is for the person to fill in: no number is right for every dice.
    bands: () => [
      { key: 'failure', label: 'Failure', upTo: { kind: 'number', value: '' } },
      { key: 'success', label: 'Success', upTo: { kind: 'none' } },
    ],
  },
]

/** A new check starts with the one band every check needs. */
export function blankCheck(): CheckDraft {
  return {
    key: '',
    label: '',
    hint: '',
    dice: '',
    modifier: '',
    pool: null,
    push: null,
    difficulty: null,
    bands: [{ key: 'outcome', label: 'Outcome', upTo: { kind: 'none' } }],
  }
}

/** The mode a draft is in. */
export const modeOf = (draft: CheckDraft): CheckMode => (draft.pool === null ? 'dice' : 'pool')

/**
 * The draft in another mode. Dice drops the pool and its push, which go together; a pool starts
 * empty and with no push. Choosing the mode the draft is in changes nothing.
 */
export function switchMode(draft: CheckDraft, mode: CheckMode): CheckDraft {
  if (modeOf(draft) === mode) {
    return draft
  }
  return {
    ...draft,
    pool: mode === 'pool' ? { count: '', sides: '', successAt: '' } : null,
    push: null,
    storedNullPush: false,
  }
}

/**
 * A new, unsaved check prefilled from `source`: the key is `{key}-copy`, then `-copy-2`, `-copy-3`…
 * (the first one no check in `existingKeys` has) and the label "{label} (copy)". The bands are
 * copied, so editing the copy never touches the source.
 */
export function duplicateCheck(source: CheckDraft, existingKeys: string[]): CheckDraft {
  const taken = new Set(existingKeys)
  let key = `${source.key}-copy`
  for (let copy = 2; taken.has(key); copy += 1) {
    key = `${source.key}-copy-${String(copy)}`
  }
  return {
    ...source,
    key,
    label: `${source.label} (copy)`,
    pool: source.pool === null ? null : { ...source.pool },
    push: source.push === null ? null : { ...source.push, effects: [...source.push.effects] },
    difficulty: source.difficulty === null ? null : { ...source.difficulty },
    bands: source.bands.map((band) => ({ ...band, upTo: { ...band.upTo } })),
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

/** The stored pool as form state, or null when it is not exactly a count, sides and success threshold. */
function readPool(pool: unknown): PoolDraft | null {
  if (
    !isObject(pool) ||
    Object.keys(pool).some(
      (name) => !['count', 'sides', 'successAt', 'criticals', 'hunger'].includes(name),
    )
  ) {
    return null
  }
  const { count, sides, successAt, criticals, hunger } = pool
  if (!Number.isInteger(sides) || !Number.isInteger(successAt)) {
    return null
  }
  const counted = readCount(count)
  if (counted === null) {
    return null
  }
  const read: PoolDraft = { count: counted, sides: String(sides), successAt: String(successAt) }
  if (criticals !== undefined) {
    if (
      !isObject(criticals) ||
      Object.keys(criticals).length !== 1 ||
      typeof criticals.bonus !== 'number' ||
      !Number.isInteger(criticals.bonus)
    ) {
      return null
    }
    read.criticalsBonus = String(criticals.bonus)
  }
  if (hunger !== undefined) {
    const hungry =
      isObject(hunger) && Object.keys(hunger).length === 1 ? readCount(hunger.count) : null
    if (hungry === null) {
      return null
    }
    read.hunger = hungry
  }
  return read
}

/** A pool or Hunger count as text: an integer as digits, a `{formula}` as its formula; else null. */
function readCount(count: unknown): string | null {
  if (typeof count === 'number' && Number.isInteger(count)) {
    return String(count)
  }
  if (isObject(count) && Object.keys(count).length === 1 && typeof count.formula === 'string') {
    return count.formula
  }
  return null
}

/** The stored push as form state, or null when it is not exactly a label and a list of effects. */
function readPush(push: unknown): PushDraft | null {
  if (
    !isObject(push) ||
    Object.keys(push).some((name) => !['label', 'effects', 'maxDice'].includes(name))
  ) {
    return null
  }
  const { label, effects, maxDice } = push
  if (
    typeof label !== 'string' ||
    (effects !== undefined && !Array.isArray(effects)) ||
    (maxDice !== undefined && !(typeof maxDice === 'number' && Number.isInteger(maxDice)))
  ) {
    return null
  }
  return {
    label,
    effects: effectsToDrafts((effects ?? []) as unknown[]),
    ...(typeof maxDice === 'number' ? { maxDice: String(maxDice) } : {}),
  }
}

/** The stored check as form state, or null when a property has a shape the form cannot hold. */
export function readCheck(stored: StoredCheck): CheckDraft | null {
  const { key, label, hint, dice, modifier, difficulty, bands, pool, push } = stored
  if (typeof key !== 'string' || typeof label !== 'string') {
    return null
  }
  // Exactly one of dice and pool; a pool check has no modifier, and only it has a push.
  const pooled = pool !== undefined
  const read = pooled ? readPool(pool) : null
  if (
    pooled
      ? read === null || dice !== undefined || (modifier ?? null) !== null
      : typeof dice !== 'string'
  ) {
    return null
  }
  if (push !== undefined && !pooled) {
    return null
  }
  const readPushed = push === undefined || push === null ? null : readPush(push)
  if (push !== undefined && push !== null && readPushed === null) {
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

  let declared: DifficultyDraft | null = null
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
    declared = {
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
    dice: typeof dice === 'string' ? dice : '',
    modifier: typeof modifier === 'string' ? modifier : '',
    pool: read,
    push: readPushed,
    ...(push === null ? { storedNullPush: true } : {}),
    difficulty: declared,
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
  const pooled = draft.pool !== null
  if (key === '') {
    errors.key = 'Give the check a key.'
  }
  if (label === '') {
    errors.label = 'Give the check a label.'
  }
  if (!pooled && dice === '') {
    errors.dice = 'Write the dice.'
  }
  check.key = key
  check.label = label
  const hint = draft.hint.trim()
  if (hint !== '') {
    check.hint = hint
  }
  if (draft.pool === null) {
    check.dice = dice
    const modifier = draft.modifier.trim()
    if (modifier !== '') {
      check.modifier = modifier
    }
  } else {
    const pool = writePool(draft.pool, errors)
    if (pool !== null) {
      check.pool = pool
    }
    if (draft.push !== null) {
      const push = writePush(draft.push, errors)
      if (push !== null) {
        check.push = push
      }
    } else if (draft.storedNullPush === true) {
      check.push = null
    }
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
 * The pool to send, or null with the errors found, by the publisher's rules: a count that is an
 * integer 1..100 or a formula of 1 to 200 characters, sides 2..1000, success at 1..sides.
 */
function writePool(pool: PoolDraft, errors: CheckErrors): Record<string, unknown> | null {
  const written: Record<string, unknown> = {}

  const count = pool.count.trim()
  if (count === '') {
    errors.poolCount = 'Give the pool a count.'
  } else if (integerOf(count) !== null) {
    const value = integerOf(count) ?? 0
    if (value < 1 || value > poolLimits.count) {
      errors.poolCount = `The count must be between 1 and ${String(poolLimits.count)}, or a formula.`
    }
    written.count = value
  } else if (count.length > poolLimits.formula) {
    errors.poolCount = `The count formula is at most ${String(poolLimits.formula)} characters.`
  } else {
    written.count = { formula: count }
  }

  const sides = pool.sides.trim()
  const sidesValue = integerOf(sides)
  if (sides === '') {
    errors.poolSides = 'Give the pool its sides.'
  } else if (sidesValue === null) {
    errors.poolSides = 'Must be a whole number.'
  } else if (sidesValue < 2 || sidesValue > poolLimits.sides) {
    errors.poolSides = `Must be between 2 and ${String(poolLimits.sides)}.`
  }
  written.sides = sidesValue

  const successAt = pool.successAt.trim()
  const successValue = integerOf(successAt)
  if (successAt === '') {
    errors.poolSuccessAt = 'Give the success threshold.'
  } else if (successValue === null) {
    errors.poolSuccessAt = 'Must be a whole number.'
  } else if (successValue < 1 || (sidesValue !== null && successValue > sidesValue)) {
    errors.poolSuccessAt = `Must be between 1 and ${sidesValue === null ? 'the sides' : String(sidesValue)}.`
  }
  written.successAt = successValue

  const bonus = (pool.criticalsBonus ?? '').trim()
  if (bonus !== '') {
    const value = integerOf(bonus)
    if (value === null || value < 1 || value > poolLimits.bonus) {
      errors.poolCriticalsBonus = `The bonus must be a whole number from 1 to ${String(poolLimits.bonus)}.`
    } else {
      written.criticals = { bonus: value }
    }
  }

  const hunger = (pool.hunger ?? '').trim()
  if (hunger !== '') {
    const value = integerOf(hunger)
    if (value !== null) {
      if (value < 0 || value > poolLimits.count) {
        errors.poolHunger = `The Hunger count must be between 0 and ${String(poolLimits.count)}, or a formula.`
      }
      written.hunger = { count: value }
    } else if (hunger.length > poolLimits.formula) {
      errors.poolHunger = `The Hunger formula is at most ${String(poolLimits.formula)} characters.`
    } else {
      written.hunger = { count: { formula: hunger } }
    }
  }

  return errors.poolCount === undefined &&
    errors.poolSides === undefined &&
    errors.poolSuccessAt === undefined &&
    errors.poolCriticalsBonus === undefined &&
    errors.poolHunger === undefined
    ? written
    : null
}

/**
 * The push to send, or null with the errors found: a label of 1 to 100 characters, and the effects
 * of the cost, left out when there are none. The publisher checks each effect.
 */
function writePush(push: PushDraft, errors: CheckErrors): Record<string, unknown> | null {
  const label = push.label.trim()
  if (label === '') {
    errors.pushLabel = 'Give the push a cost label.'
  } else if (label.length > maxPushLabel) {
    errors.pushLabel = `The label is at most ${String(maxPushLabel)} characters.`
  }

  // The refusals of the effects editor come keyed `push.effects.<n>.value`; one that does not
  // match is kept as a refusal of the whole list, so the form never sends while one stands.
  const refused: Record<string, string> = {}
  const effects = effectsToStored(push.effects, 'push.effects', refused, true)
  const found: StepError[] = Object.entries(refused).map(([path, message]) => {
    const [, effect, field] = /^push\.effects\.(\d+)\.(\w+)$/.exec(path) ?? []
    return effect === undefined || field === undefined
      ? { index: null, band: null, effect: null, field: 'effects', message }
      : { index: null, band: null, effect: Number(effect), field, message }
  })
  if (found.length > 0) {
    errors.pushEffects = found
  }

  const cap = (push.maxDice ?? '').trim()
  let maxDice: number | null = null
  if (cap !== '') {
    maxDice = integerOf(cap)
    if (maxDice === null || maxDice < 1 || maxDice > poolLimits.count) {
      errors.pushMaxDice = `The cap must be a whole number from 1 to ${String(poolLimits.count)}, or empty.`
    }
  }

  return errors.pushLabel === undefined && errors.pushMaxDice === undefined && found.length === 0
    ? {
        label,
        ...(effects.length > 0 ? { effects } : {}),
        ...(maxDice === null ? {} : { maxDice }),
      }
    : null
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
