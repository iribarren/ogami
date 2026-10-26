import type { FieldErrors } from '../narrativeDraft'
import type { StoredItem } from '../useGameSystemNarrative'
import {
  isObject,
  readValue,
  stringsOf,
  textOf,
  unknownFields,
  valueToStored,
  type ValueDraft,
  type ValueForm,
} from './draftParts'

// Typed form state for an effect list (a step's `effects`, a band's `effects`). As with steps, what
// the editor does not know stays in `extra` and goes back untouched; an effect of a kind it does
// not know or a shape it cannot read is kept as stored (`kind: 'stored'`), shown read-only. The
// publisher owns every rule (`Effects.php`); only the limit and the sheet guide below are echoed.

/** At most this many effects in a list (the publisher's `Effects::MAX_EFFECTS`). */
export const MAX_EFFECTS = 10

export const effectKinds = [
  'tracker',
  'nextScene',
  'switchSceneType',
  'endPhase',
  'sceneTitle',
  'createNpc',
  'createThread',
  'closeThread',
  'fillFact',
  'sheet',
] as const
export type EffectKind = (typeof effectKinds)[number]

export const trackerOps = ['add', 'set'] as const
export const sheetOps = ['add', 'subtract', 'set'] as const
/** The ops of a sheet effect on a conditions field, the only ones it takes (ADR 0041). */
export const conditionOps = ['mark', 'clear'] as const
export type SheetOp = (typeof sheetOps)[number] | (typeof conditionOps)[number]

/** The value forms of a tracker effect and of a sheet effect (only the latter takes dice). */
export const trackerForms = [
  'integer',
  'tracker',
  'formula',
] as const satisfies readonly ValueForm[]
export const sheetForms = ['integer', 'formula', 'dice'] as const satisfies readonly ValueForm[]

interface Extra {
  extra: StoredItem
}

export type EffectDraft =
  | (Extra & { kind: 'tracker'; tracker: string; op: 'add' | 'set'; value: ValueDraft })
  | (Extra & { kind: 'nextScene' | 'switchSceneType'; sceneType: string })
  | (Extra & { kind: 'endPhase' | 'closeThread' })
  | (Extra & { kind: 'sceneTitle'; title: string })
  | (Extra & { kind: 'createNpc'; name: string; agenda: string; tags: string[] })
  | (Extra & { kind: 'createThread'; name: string; tags: string[] })
  | (Extra & { kind: 'fillFact'; slot: string; text: string })
  | (Extra & {
      kind: 'sheet'
      field: string
      op: 'add' | 'subtract' | 'set'
      value: ValueDraft
    })
  | (Extra & {
      kind: 'sheet'
      field: string
      /** On a conditions field. */
      op: 'mark' | 'clear'
      /** The option key; '' leaves it out: a clear takes them all, a mark in a push cost lets the player pick. */
      option: string
    })
  | { kind: 'stored'; stored: unknown }

export type EditedEffect = Exclude<EffectDraft, { kind: 'stored' }>
export type SheetEffect = Extract<EditedEffect, { kind: 'sheet' }>

/** The fields each kind knows besides `kind`; anything else is kept in `extra`. */
export const effectFields: Record<EffectKind, readonly string[]> = {
  tracker: ['tracker', 'op', 'value'],
  nextScene: ['sceneType'],
  switchSceneType: ['sceneType'],
  endPhase: [],
  sceneTitle: ['title'],
  createNpc: ['name', 'agenda', 'tags'],
  createThread: ['name', 'tags'],
  closeThread: [],
  fillFact: ['slot', 'text'],
  sheet: ['field', 'op', 'value'],
}

const required = (source: StoredItem, field: string): string | null =>
  typeof source[field] === 'string' ? source[field] : null

const oneOf = <T extends string>(options: readonly T[], value: unknown): T | null =>
  options.find((option) => option === value) ?? null

/** The optional text and list fields: stored empty, they mean absent but are kept as stored. */
const optional = ['name', 'agenda', 'tags', 'text']
const isEmpty = (value: unknown) => value === '' || (Array.isArray(value) && value.length === 0)

/** The effect as form state; one this editor cannot edit is kept as stored. */
export function effectToDraft(stored: unknown): EffectDraft {
  const unreadable: EffectDraft = { kind: 'stored', stored }
  const kind = isObject(stored) ? oneOf(effectKinds, stored.kind) : null
  if (!isObject(stored) || kind === null) {
    return unreadable
  }
  // An optional field the stored effect carries empty stays as it is until the author fills it.
  const extra = {
    ...unknownFields(stored, ['kind', ...effectFields[kind]]),
    ...Object.fromEntries(
      optional.filter((field) => isEmpty(stored[field])).map((field) => [field, stored[field]]),
    ),
  }
  const name = textOf(stored, 'name')
  const tags = stringsOf(stored, 'tags')
  switch (kind) {
    case 'tracker': {
      const tracker = required(stored, 'tracker')
      const op = oneOf(trackerOps, stored.op)
      const value = readValue(stored.value, trackerForms)
      return tracker === null || op === null || value === null
        ? unreadable
        : { kind, tracker, op, value, extra }
    }
    case 'nextScene':
    case 'switchSceneType': {
      const sceneType = required(stored, 'sceneType')
      return sceneType === null ? unreadable : { kind, sceneType, extra }
    }
    case 'endPhase':
    case 'closeThread':
      return { kind, extra }
    case 'sceneTitle': {
      const title = required(stored, 'title')
      return title === null ? unreadable : { kind, title, extra }
    }
    case 'createNpc': {
      const agenda = textOf(stored, 'agenda')
      return name === null || agenda === null || tags === null
        ? unreadable
        : { kind, name, agenda, tags, extra }
    }
    case 'createThread':
      return name === null || tags === null ? unreadable : { kind, name, tags, extra }
    case 'fillFact': {
      const slot = required(stored, 'slot')
      const text = textOf(stored, 'text')
      return slot === null || text === null ? unreadable : { kind, slot, text, extra }
    }
    case 'sheet': {
      const field = required(stored, 'field')
      const conditionOp = oneOf(conditionOps, stored.op)
      if (conditionOp !== null) {
        // A mark or clear names an option as a key, or none.
        const option =
          stored.value === undefined || stored.value === null
            ? ''
            : typeof stored.value === 'string'
              ? stored.value
              : null
        return field === null || option === null
          ? unreadable
          : { kind, field, op: conditionOp, option, extra }
      }
      const op = oneOf(sheetOps, stored.op)
      const value = readValue(stored.value, sheetForms)
      return field === null || op === null || value === null
        ? unreadable
        : { kind, field, op, value, extra }
    }
  }
}

export const effectsToDrafts = (stored: unknown[]): EffectDraft[] => stored.map(effectToDraft)

/** A new effect of `kind` with nothing picked yet: the publisher refuses it until it is. */
export function newEffect(kind: EffectKind): EditedEffect {
  const extra = {}
  const one: ValueDraft = { form: 'integer', text: '1' }
  switch (kind) {
    case 'tracker':
      return { kind, tracker: '', op: 'add', value: one, extra }
    case 'nextScene':
    case 'switchSceneType':
      return { kind, sceneType: '', extra }
    case 'endPhase':
    case 'closeThread':
      return { kind, extra }
    case 'sceneTitle':
      return { kind, title: '', extra }
    case 'createNpc':
      return { kind, name: '', agenda: '', tags: [], extra }
    case 'createThread':
      return { kind, name: '', tags: [], extra }
    case 'fillFact':
      return { kind, slot: '', text: '', extra }
    case 'sheet':
      return { kind, field: '', op: 'add', value: one, extra }
  }
}

/** The sheet effect with another op: switching between the two families starts the other's value. */
export function sheetWithOp(effect: SheetEffect, op: SheetOp): SheetEffect {
  const { extra, field } = effect
  if (op === 'mark' || op === 'clear') {
    return { kind: 'sheet', extra, field, op, option: 'option' in effect ? effect.option : '' }
  }
  const one: ValueDraft = { form: 'integer', text: '1' }
  return { kind: 'sheet', extra, field, op, value: 'value' in effect ? effect.value : one }
}

/**
 * The sheet effect on another field. `conditions` says the field is a conditions field: the op
 * follows (mark/clear for it, add/subtract/set for any other) and an option picked for the
 * previous field goes.
 */
export function sheetWithField(
  effect: SheetEffect,
  field: string,
  conditions: boolean,
): SheetEffect {
  const onConditions = effect.op === 'mark' || effect.op === 'clear'
  const next =
    onConditions === conditions ? effect : sheetWithOp(effect, conditions ? 'mark' : 'add')
  return 'option' in next ? { ...next, field, option: '' } : { ...next, field }
}

/** The non-empty ones of the optional fields: an empty text or list means the same as absent (`extra` keeps one a stored effect had). */
const given = (fields: Record<string, string | string[]>) =>
  Object.fromEntries(Object.entries(fields).filter(([, value]) => value.length > 0))

function effectToStored(
  effect: EffectDraft,
  path: string,
  errors: FieldErrors,
  allowPick: boolean,
): unknown {
  if (effect.kind === 'stored') {
    return effect.stored
  }
  const { extra, kind } = effect
  if (effect.kind === 'sheet' && 'option' in effect) {
    // Only a push cost lets the player pick the option to mark; anywhere else a mark names it.
    if (effect.op === 'mark' && effect.option === '' && !allowPick) {
      errors[`${path}.value`] = 'Pick the option to mark.'
    }
    return {
      ...extra,
      kind,
      field: effect.field,
      op: effect.op,
      ...(effect.option === '' ? {} : { value: effect.option }),
    }
  }
  switch (effect.kind) {
    case 'tracker':
    case 'sheet':
      return {
        ...extra,
        kind,
        ...(effect.kind === 'tracker' ? { tracker: effect.tracker } : { field: effect.field }),
        op: effect.op,
        value: valueToStored(effect.value, `${path}.value`, errors),
      }
    case 'nextScene':
    case 'switchSceneType':
      return { ...extra, kind, sceneType: effect.sceneType }
    case 'endPhase':
    case 'closeThread':
      return { ...extra, kind }
    case 'sceneTitle':
      return { ...extra, kind, title: effect.title }
    case 'createNpc':
      return {
        ...extra,
        kind,
        ...given({ name: effect.name, agenda: effect.agenda, tags: effect.tags }),
      }
    case 'createThread':
      return { ...extra, kind, ...given({ name: effect.name, tags: effect.tags }) }
    case 'fillFact':
      return { ...extra, kind, slot: effect.slot, ...given({ text: effect.text }) }
  }
}

/**
 * The effects to save; values that are not whole numbers, and a mark without an option where
 * `allowPick` is not set, are keyed `<path>.<n>.value`.
 */
export function effectsToStored(
  effects: EffectDraft[],
  path: string,
  errors: FieldErrors,
  allowPick = false,
) {
  return effects.map((effect, index) =>
    effectToStored(effect, `${path}.${String(index)}`, errors, allowPick),
  )
}

/**
 * Client guides, nothing more: per effect, what the author should know before saving. A sheet
 * effect only works where a Character is known (a character step, a check outcome); the publisher
 * refuses it elsewhere, and one already stored there is shown with this note, never dropped.
 */
export function effectGuides(effects: EffectDraft[], allowSheet: boolean) {
  return effects.map((effect) =>
    effect.kind === 'sheet' && !allowSheet
      ? 'A sheet effect is only allowed in a character step and in check outcomes.'
      : undefined,
  )
}
