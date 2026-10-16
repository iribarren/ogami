import { parseInteger } from '../../oracles/tableDraft'
import type { FieldErrors } from '../narrativeDraft'
import type { StoredItem } from '../useGameSystemNarrative'

// Typed form state for a step list (a Scene Type's `setup`, `play` and `closing`, a flow's hooks).
// A step is read into strings the inputs can hold and written back. What the editor does not know
// stays in `extra` and is sent back untouched; a step of a kind this editor does not edit, or of a
// shape it cannot read, is kept as stored (`kind: 'stored'`), shown read-only, never dropped or
// guessed. Effects are kept as stored until the effects editor exists. The publisher owns every
// rule: the guides at the bottom only flag what the author is likely to want to fix first.

/** The reserved `next` that ends the list. */
export const END = 'end'

export const stepKinds = ['prompt', 'character', 'roll', 'condition'] as const
export type StepKind = (typeof stepKinds)[number]

export const countTargets = ['npc', 'thread', 'character'] as const
export type CountTarget = (typeof countTargets)[number]

export type ValueForm = 'integer' | 'tracker' | 'formula'

/** An `upTo`: a whole number, a tracker key or a formula, as text. */
export interface ValueDraft {
  form: ValueForm
  text: string
}

export interface BandDraft {
  /** Null for the last band, which catches the rest. */
  upTo: ValueDraft | null
  /** '' continues with the following step. */
  next: string
  effects: unknown[]
  extra: StoredItem
}

interface StepBase {
  key: string
  title: string
  prompt: string
  tip: string
  mandatory: boolean
  /** A later step key or `end`; '' continues with the following step. */
  next: string
  effects: unknown[]
  extra: StoredItem
}

export type StepDraft =
  | (StepBase & { kind: 'prompt'; inspiration: string[] })
  | (StepBase & { kind: 'character' })
  | (StepBase & { kind: 'roll'; dice: string; bands: BandDraft[] })
  | (StepBase & {
      kind: 'condition'
      source: 'tracker' | 'count'
      tracker: string
      of: CountTarget
      tags: string[]
      /** What the stored `count` carries beyond `of` and `tags`. */
      countExtra: StoredItem
      bands: BandDraft[]
    })
  | { kind: 'stored'; stored: unknown }

export type EditedStep = Exclude<StepDraft, { kind: 'stored' }>

export type StepsResult = { steps: StoredItem[] } | { errors: FieldErrors }

const WHOLE = 'Use a whole number.'

function isObject(value: unknown): value is StoredItem {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The text of an optional field: '' when absent, null when it holds something else. */
function textOf(source: StoredItem, field: string): string | null {
  const value = source[field]
  if (value === undefined) {
    return ''
  }
  return typeof value === 'string' ? value : null
}

/** An optional list of strings: empty when absent, null when it holds something else. */
function stringsOf(source: StoredItem, field: string): string[] | null {
  const value = source[field]
  if (value === undefined) {
    return []
  }
  return Array.isArray(value) && value.every((item) => typeof item === 'string') ? value : null
}

function listOf(source: StoredItem, field: string): unknown[] | null {
  const value = source[field]
  if (value === undefined) {
    return []
  }
  return Array.isArray(value) ? (value as unknown[]) : null
}

function unknownFields(source: StoredItem, known: readonly string[]): StoredItem {
  return Object.fromEntries(Object.entries(source).filter(([field]) => !known.includes(field)))
}

const commonFields = ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'next', 'effects']
const bandFields = ['upTo', 'next', 'effects']
const countFields = ['of', 'tags']

function readValue(value: unknown): ValueDraft | null {
  if (typeof value === 'number' && Number.isInteger(value)) {
    return { form: 'integer', text: String(value) }
  }
  if (isObject(value) && Object.keys(value).length === 1) {
    if (typeof value.tracker === 'string') {
      return { form: 'tracker', text: value.tracker }
    }
    if (typeof value.formula === 'string') {
      return { form: 'formula', text: value.formula }
    }
  }
  return null
}

function readBand(stored: unknown): BandDraft | null {
  if (!isObject(stored)) {
    return null
  }
  const upTo = stored.upTo === undefined ? null : readValue(stored.upTo)
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  if ((stored.upTo !== undefined && upTo === null) || next === null || effects === null) {
    return null
  }
  return { upTo, next, effects, extra: unknownFields(stored, bandFields) }
}

/** Every band, or null when one of them cannot be read. */
function readBands(stored: StoredItem): BandDraft[] | null {
  const list = listOf(stored, 'bands')
  const bands = (list ?? []).map(readBand)
  return list === null || bands.includes(null) ? null : (bands as BandDraft[])
}

function readCondition(stored: StoredItem, base: StepBase): StepDraft | null {
  const { tracker, count } = stored
  const bands = readBands(stored)
  if (bands === null || (tracker === undefined) === (count === undefined)) {
    return null
  }
  const blank = { of: 'npc' as const, tags: [], countExtra: {} }
  if (tracker !== undefined) {
    return typeof tracker === 'string'
      ? { ...base, kind: 'condition', source: 'tracker', tracker, ...blank, bands }
      : null
  }
  const tags = isObject(count) ? stringsOf(count, 'tags') : null
  if (!isObject(count) || tags === null || !countTargets.some((of) => of === count.of)) {
    return null
  }
  return {
    ...base,
    kind: 'condition',
    source: 'count',
    tracker: '',
    of: count.of as CountTarget,
    tags,
    countExtra: unknownFields(count, countFields),
    bands,
  }
}

/** The step as form state; a step this editor cannot edit is kept as stored. */
export function stepToDraft(stored: unknown): StepDraft {
  const unreadable: StepDraft = { kind: 'stored', stored }
  if (!isObject(stored) || !stepKinds.some((kind) => kind === stored.kind)) {
    return unreadable
  }
  const key = textOf(stored, 'key')
  const title = textOf(stored, 'title')
  const prompt = textOf(stored, 'prompt')
  const tip = textOf(stored, 'tip')
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  const mandatory = stored.mandatory ?? false
  if ([key, title, prompt, tip, next, effects].includes(null) || typeof mandatory !== 'boolean') {
    return unreadable
  }
  const kind = stored.kind as StepKind
  const known = {
    prompt: [...commonFields, 'inspiration'],
    character: commonFields,
    roll: [...commonFields, 'dice', 'bands'],
    condition: [...commonFields, 'tracker', 'count', 'bands'],
  }[kind]
  const base: StepBase = {
    key: key ?? '',
    title: title ?? '',
    prompt: prompt ?? '',
    tip: tip ?? '',
    mandatory,
    next: next ?? '',
    effects: effects ?? [],
    extra: unknownFields(stored, known),
  }
  switch (kind) {
    case 'prompt': {
      const inspiration = stringsOf(stored, 'inspiration')
      return inspiration === null ? unreadable : { ...base, kind, inspiration }
    }
    case 'character':
      return { ...base, kind }
    case 'roll': {
      const bands = readBands(stored)
      const dice = textOf(stored, 'dice')
      return bands === null || dice === null ? unreadable : { ...base, kind, dice, bands }
    }
    case 'condition':
      return readCondition(stored, base) ?? unreadable
  }
}

export const stepsToDrafts = (stored: unknown[]): StepDraft[] => stored.map(stepToDraft)

/** The smallest `kind-n` key no step of the list has. */
function freeKey(kind: StepKind, steps: StepDraft[]): string {
  const taken = steps.map(stepKey)
  let n = 1
  while (taken.includes(`${kind}-${String(n)}`)) {
    n += 1
  }
  return `${kind}-${String(n)}`
}

export function newBand(): BandDraft {
  return { upTo: { form: 'integer', text: '' }, next: '', effects: [], extra: {} }
}

export function newStep(kind: StepKind, steps: StepDraft[]): EditedStep {
  const base: StepBase = {
    key: freeKey(kind, steps),
    title: '',
    prompt: '',
    tip: '',
    mandatory: false,
    next: '',
    effects: [],
    extra: {},
  }
  const lastBand: BandDraft = { ...newBand(), upTo: null }
  switch (kind) {
    case 'prompt':
      return { ...base, kind, inspiration: [] }
    case 'character':
      return { ...base, kind }
    case 'roll':
      return { ...base, kind, dice: '1d6', bands: [] }
    case 'condition':
      return {
        ...base,
        kind,
        source: 'tracker',
        tracker: '',
        of: 'npc',
        tags: [],
        countExtra: {},
        bands: [lastBand],
      }
  }
}

/** The key of a step, '' when a stored step has none. */
export function stepKey(step: StepDraft): string {
  const key = step.kind === 'stored' && isObject(step.stored) ? step.stored.key : undefined
  return step.kind === 'stored' ? (typeof key === 'string' ? key : '') : step.key
}

/** What the effects of a step or band are, for a line of text: "2 effects: tracker, nextScene". */
export function summariseEffects(effects: unknown[]): string {
  if (effects.length === 0) {
    return ''
  }
  const kinds = effects.map((effect) =>
    isObject(effect) && typeof effect.kind === 'string' ? effect.kind : 'unknown',
  )
  return `${String(effects.length)} ${effects.length === 1 ? 'effect' : 'effects'}: ${kinds.join(', ')}`
}

function valueToStored(value: ValueDraft, path: string, errors: FieldErrors): unknown {
  if (value.form === 'tracker') {
    return { tracker: value.text }
  }
  if (value.form === 'formula') {
    return { formula: value.text }
  }
  const whole = parseInteger(value.text.trim())
  if (whole === null) {
    errors[path] = WHOLE
  }
  return whole ?? 0
}

function bandToStored(band: BandDraft, last: boolean, path: string, errors: FieldErrors) {
  return {
    ...band.extra,
    ...(last || band.upTo === null
      ? {}
      : { upTo: valueToStored(band.upTo, `${path}.upTo`, errors) }),
    ...(band.next === '' ? {} : { next: band.next }),
    ...(band.effects.length === 0 ? {} : { effects: band.effects }),
  }
}

function stepToStored(step: EditedStep, index: number, errors: FieldErrors): StoredItem {
  const at = `${String(index)}.bands`
  const bandsOf = (bands: BandDraft[]) =>
    bands.map((band, position) =>
      bandToStored(band, position === bands.length - 1, `${at}.${String(position)}`, errors),
    )
  const item: StoredItem = {
    ...step.extra,
    key: step.key.trim(),
    kind: step.kind,
    title: step.title,
    ...(step.prompt === '' ? {} : { prompt: step.prompt }),
    ...(step.tip === '' ? {} : { tip: step.tip }),
    ...(step.mandatory && step.kind !== 'condition' ? { mandatory: true } : {}),
    ...(step.next === '' ? {} : { next: step.next }),
    ...(step.effects.length === 0 ? {} : { effects: step.effects }),
  }
  switch (step.kind) {
    case 'prompt':
      if (step.inspiration.length > 0) {
        item.inspiration = step.inspiration
      }
      break
    case 'roll':
      item.dice = step.dice.trim()
      if (step.bands.length > 0) {
        item.bands = bandsOf(step.bands)
      }
      break
    case 'condition':
      if (step.source === 'tracker') {
        item.tracker = step.tracker
      } else {
        item.count = {
          ...step.countExtra,
          of: step.of,
          ...(step.tags.length === 0 ? {} : { tags: step.tags }),
        }
      }
      item.bands = bandsOf(step.bands)
      break
    case 'character':
      break
  }
  return item
}

/**
 * The steps to save, or the whole numbers that are not (keyed `<step>.bands.<band>.upTo`). A stored
 * step goes back exactly as it came.
 */
export function stepsToItems(steps: StepDraft[]): StepsResult {
  const errors: FieldErrors = {}
  const items = steps.map((step, index) =>
    step.kind === 'stored' ? (step.stored as StoredItem) : stepToStored(step, index, errors),
  )
  return Object.keys(errors).length > 0 ? { errors } : { steps: items }
}

export interface StepGuide {
  key?: string
  next?: string
  bands: Record<number, { upTo?: string; next?: string }>
}

const backward = 'Pick a later step: next only goes forward.'

/**
 * Client guides, nothing more: the publisher checks the same rules and stays the authority, so
 * none of them blocks a save. A `next` that is the same or an earlier key (after a move, say), a
 * repeated or reserved key, and literal `upTo` values that do not increase.
 */
export function stepGuides(steps: StepDraft[]): StepGuide[] {
  const keys = steps.map(stepKey)
  const misplaced = (next: string, index: number) =>
    next !== '' && next !== END && keys.includes(next) && keys.indexOf(next) <= index
  return steps.map((step, index) => {
    const guide: StepGuide = { bands: {} }
    const key = keys[index] ?? ''
    if (key === END) {
      guide.key = 'end is reserved for the end of the list.'
    } else if (key !== '' && keys.indexOf(key) !== index) {
      guide.key = 'Another step has this key.'
    }
    if (step.kind === 'stored') {
      return guide
    }
    if (misplaced(step.next, index)) {
      guide.next = backward
    }
    if (step.kind === 'roll' || step.kind === 'condition') {
      let previous: number | null = null
      step.bands.forEach((band, position) => {
        const literal = band.upTo?.form === 'integer' ? parseInteger(band.upTo.text.trim()) : null
        const flags: { upTo?: string; next?: string } = {}
        if (literal !== null && previous !== null && literal <= previous) {
          flags.upTo = 'Must be above the previous limit.'
        }
        if (literal !== null) {
          previous = literal
        }
        if (misplaced(band.next, index)) {
          flags.next = backward
        }
        if (Object.keys(flags).length > 0) {
          guide.bands[position] = flags
        }
      })
    }
    return guide
  })
}
