import { parseInteger } from '../../oracles/tableDraft'
import type { FieldErrors } from '../narrativeDraft'
import type { StoredItem } from '../useGameSystemNarrative'
import {
  isObject,
  listOf,
  readValue,
  stringsOf,
  textOf,
  unknownFields,
  valueToStored,
  type ValueDraft,
  type ValueForm,
} from './draftParts'
import { effectsToDrafts, effectsToStored, type EffectDraft } from './effectDraft'

// Typed form state for a step list (a Scene Type's `setup`, `play` and `closing`, a flow's hooks).
// A step is read into strings the inputs can hold and written back. What the editor does not know
// stays in `extra` and is sent back untouched; a step of a kind this editor does not edit, or of a
// shape it cannot read, is kept as stored (`kind: 'stored'`), shown read-only, never dropped or
// guessed. Effects are typed drafts of their own (`effectDraft`). The publisher owns every
// rule: the guides at the bottom only flag what the author is likely to want to fix first.

/** The reserved `next` that ends the list. */
export const END = 'end'

export const stepKinds = ['prompt', 'character', 'roll', 'condition'] as const
export type StepKind = (typeof stepKinds)[number]

export const countTargets = ['npc', 'thread', 'character'] as const
export type CountTarget = (typeof countTargets)[number]

export interface BandDraft {
  /** Null for the last band, which catches the rest. */
  upTo: ValueDraft | null
  /** '' continues with the following step. */
  next: string
  effects: EffectDraft[]
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
  effects: EffectDraft[]
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

const commonFields = ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'next', 'effects']
const bandFields = ['upTo', 'next', 'effects']
const countFields = ['of', 'tags']

/** The forms a band limit takes. */
export const bandForms = ['integer', 'tracker', 'formula'] as const satisfies readonly ValueForm[]

function readBand(stored: unknown): BandDraft | null {
  if (!isObject(stored)) {
    return null
  }
  const upTo = stored.upTo === undefined ? null : readValue(stored.upTo, bandForms)
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  if ((stored.upTo !== undefined && upTo === null) || next === null || effects === null) {
    return null
  }
  return { upTo, next, effects: effectsToDrafts(effects), extra: unknownFields(stored, bandFields) }
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
    effects: effectsToDrafts(effects ?? []),
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

/**
 * The limit stays with the band whatever its position: a band that became the last one keeps what
 * it had (the guides flag it, the publisher decides), and a band before the last with none is
 * refused here instead of being saved without a limit.
 */
function bandToStored(band: BandDraft, last: boolean, path: string, errors: FieldErrors) {
  if (band.upTo === null && !last) {
    errors[`${path}.upTo`] = 'Set a limit: only the last band catches the rest.'
  }
  return {
    ...band.extra,
    ...(band.upTo === null ? {} : { upTo: valueToStored(band.upTo, `${path}.upTo`, errors) }),
    ...(band.next === '' ? {} : { next: band.next }),
    ...(band.effects.length === 0
      ? {}
      : { effects: effectsToStored(band.effects, `${path}.effects`, errors) }),
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
    ...(step.effects.length === 0
      ? {}
      : { effects: effectsToStored(step.effects, `${String(index)}.effects`, errors) }),
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
 * repeated or reserved key, literal `upTo` values that do not increase and a limit on the last band.
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
        if (band.upTo !== null && position === step.bands.length - 1) {
          flags.upTo = 'The last band catches the rest: remove its limit.'
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
