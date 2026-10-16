import { parseInteger } from '../oracles/tableDraft'
import type { StoredItem } from './useGameSystemNarrative'

// Typed form state for the small narrative items. Each reads a stored item (free-form, as the
// API serves it) into strings the inputs can hold, and writes it back. What the editor does not
// know stays in `extra` and is sent back untouched; an item it cannot read at all is `null`, and
// the tab shows it read-only instead of guessing. The publisher owns every rule: the only check
// here is that a number field holds a whole number.

/** Field name to message, for the fields the form should flag. */
export type FieldErrors = Record<string, string>

export type ItemResult = { item: StoredItem } | { errors: FieldErrors }

export type TrackerKind = 'counter' | 'clock'

export const factSlotTypes = ['text', 'npc', 'thread'] as const

export interface LevelDraft {
  /** Empty for the last level, which has no bound. */
  upTo: string
  label: string
  extra: StoredItem
}

export interface TrackerDraft {
  kind: TrackerKind
  key: string
  name: string
  hint: string
  min: string
  max: string
  initial: string
  segments: string
  levels: LevelDraft[]
  /** Fields this editor does not know. */
  extra: StoredItem
}

/** A tag, or a fact slot (which adds `type`). */
export interface LabelledDraft {
  key: string
  label: string
  hint: string
  type: string
  extra: StoredItem
}

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

/** The number of an optional field as input text: '' when absent, null when it is not a number. */
function numberOf(source: StoredItem, field: string): string | null {
  const value = source[field]
  if (value === undefined) {
    return ''
  }
  return typeof value === 'number' ? String(value) : null
}

/** Everything of `source` except the fields the editor knows. */
function unknownFields(source: StoredItem, known: readonly string[]): StoredItem {
  return Object.fromEntries(Object.entries(source).filter(([field]) => !known.includes(field)))
}

const trackerFields = ['key', 'name', 'hint', 'kind', 'min', 'max', 'initial', 'segments', 'levels']
const levelFields = ['upTo', 'label']

export function newTracker(): TrackerDraft {
  return {
    kind: 'counter',
    key: '',
    name: '',
    hint: '',
    min: '0',
    max: '10',
    initial: '0',
    segments: '4',
    levels: [],
    extra: {},
  }
}

function levelToDraft(level: unknown): LevelDraft | null {
  if (!isObject(level)) {
    return null
  }
  const upTo = numberOf(level, 'upTo')
  const label = textOf(level, 'label')
  return upTo === null || label === null
    ? null
    : { upTo, label, extra: unknownFields(level, levelFields) }
}

/** The tracker as form state; null for a kind this editor does not know or a field of the wrong type. */
export function trackerToDraft(definition: StoredItem): TrackerDraft | null {
  const { kind, levels } = definition
  if (kind !== 'counter' && kind !== 'clock') {
    return null
  }
  const key = textOf(definition, 'key')
  const name = textOf(definition, 'name')
  const hint = textOf(definition, 'hint')
  const min = numberOf(definition, 'min')
  const max = numberOf(definition, 'max')
  const initial = numberOf(definition, 'initial')
  const segments = numberOf(definition, 'segments')
  if ([key, name, hint, min, max, initial, segments].includes(null)) {
    return null
  }
  if (levels !== undefined && !Array.isArray(levels)) {
    return null
  }
  const drafts = (levels ?? []).map(levelToDraft)
  if (drafts.includes(null)) {
    return null
  }
  return {
    ...newTracker(),
    kind,
    key: key ?? '',
    name: name ?? '',
    hint: hint ?? '',
    min: min ?? '',
    max: max ?? '',
    initial: initial ?? '',
    segments: segments ?? '',
    levels: drafts.filter((level) => level !== null),
    extra: unknownFields(definition, trackerFields),
  }
}

/** The item to send, or the number fields that are not whole numbers. */
export function trackerToItem(draft: TrackerDraft): ItemResult {
  const errors: FieldErrors = {}
  const whole = (field: string, text: string): number => {
    const value = parseInteger(text.trim())
    if (value === null) {
      errors[field] = WHOLE
    }
    return value ?? 0
  }

  const item: StoredItem = {
    ...draft.extra,
    key: draft.key.trim(),
    name: draft.name.trim(),
    ...(draft.hint.trim() === '' ? {} : { hint: draft.hint.trim() }),
    kind: draft.kind,
  }
  if (draft.kind === 'clock') {
    item.segments = whole('segments', draft.segments)
  } else {
    item.min = whole('min', draft.min)
    item.max = whole('max', draft.max)
    item.initial = whole('initial', draft.initial)
    if (draft.levels.length > 0) {
      const last = draft.levels.length - 1
      item.levels = draft.levels.map((level, index) => ({
        ...level.extra,
        ...(index === last || level.upTo.trim() === ''
          ? {}
          : { upTo: whole(`level-${String(index)}`, level.upTo) }),
        label: level.label.trim(),
      }))
    }
  }
  return Object.keys(errors).length > 0 ? { errors } : { item }
}

export function newLabelled(type = ''): LabelledDraft {
  return { key: '', label: '', hint: '', type, extra: {} }
}

function labelledToDraft(definition: StoredItem, known: readonly string[]): LabelledDraft | null {
  const key = textOf(definition, 'key')
  const label = textOf(definition, 'label')
  const hint = textOf(definition, 'hint')
  if (key === null || label === null || hint === null) {
    return null
  }
  return { key, label, hint, type: '', extra: unknownFields(definition, known) }
}

function labelledToItem(draft: LabelledDraft, type: boolean): ItemResult {
  return {
    item: {
      ...draft.extra,
      key: draft.key.trim(),
      label: draft.label.trim(),
      ...(type ? { type: draft.type } : {}),
      ...(draft.hint.trim() === '' ? {} : { hint: draft.hint.trim() }),
    },
  }
}

export function tagToDraft(definition: StoredItem): LabelledDraft | null {
  return labelledToDraft(definition, ['key', 'label', 'hint'])
}

export const tagToItem = (draft: LabelledDraft) => labelledToItem(draft, false)

/** The fact slot as form state; null for a type that is not text, npc or thread. */
export function factSlotToDraft(definition: StoredItem): LabelledDraft | null {
  const { type } = definition
  const draft = labelledToDraft(definition, ['key', 'label', 'hint', 'type'])
  if (draft === null || !factSlotTypes.some((known) => known === type)) {
    return null
  }
  return { ...draft, type: type as string }
}

export const factSlotToItem = (draft: LabelledDraft) => labelledToItem(draft, true)
