import { parseInteger } from './tableDraft'
import type { LikelihoodOracle } from './useGameSystemOracles'

/** A level as stored: a field the editor does not know stays in it and is sent back. */
type Stored = Record<string, unknown>

export interface LevelDraft {
  /** Stable while the list is edited, for React keys. */
  id: number
  original: Stored
  key: string
  label: string
  target: string
}

export interface ChaosDraft {
  min: string
  max: string
  neutral: string
  shiftPerPoint: string
  /** The key of the counter that holds the chaos factor; empty for none. Version 2 only. */
  tracker: string
}

export interface LikelihoodDraft {
  key: string
  name: string
  sides: string
  levels: LevelDraft[]
  /** Null when the oracle has no chaos factor. */
  chaos: ChaosDraft | null
  exceptionalPercent: string
  /** The key of the table rolled on a random event; empty for none. */
  randomEvent: string
}

const text = (value: unknown) => (typeof value === 'string' ? value : '')
const number = (value: unknown) => (typeof value === 'number' ? String(value) : '')
const record = (value: unknown): Stored =>
  typeof value === 'object' && value !== null ? (value as Stored) : {}

export function toLevelDraft(level: Stored, id: number): LevelDraft {
  return {
    id,
    original: level,
    key: text(level.key),
    label: text(level.label),
    target: number(level.target),
  }
}

export function toDraft(oracle: LikelihoodOracle): LikelihoodDraft {
  const chaos = record(oracle.chaos)
  return {
    key: oracle.key,
    name: oracle.name,
    sides: String(oracle.sides),
    levels: oracle.levels.map((level, index) => toLevelDraft(level, index)),
    chaos: oracle.chaos
      ? {
          min: number(chaos.min),
          max: number(chaos.max),
          neutral: number(chaos.neutral),
          shiftPerPoint: number(chaos.shiftPerPoint),
          tracker: text(chaos.tracker),
        }
      : null,
    exceptionalPercent: number(oracle.exceptionalPercent),
    randomEvent: text(oracle.randomEvent?.table),
  }
}

/** Whether the stored chaos tracker is absent or text: anything else is kept as stored, not edited. */
export const trackerEditable = (oracle: LikelihoodOracle) => {
  const tracker = record(oracle.chaos).tracker
  return tracker === undefined || typeof tracker === 'string'
}

/** The chaos factors the oracle accepts, when the draft has a chaos range that reads. */
export function chaosRange(draft: LikelihoodDraft) {
  const min = draft.chaos && parseInteger(draft.chaos.min)
  const max = draft.chaos && parseInteger(draft.chaos.max)
  return typeof min === 'number' && typeof max === 'number' ? { min, max } : null
}

/** What stops the draft from being saved; the server decides the rest. Empty when it can be. */
export function draftProblems(draft: LikelihoodDraft): string[] {
  const problems: string[] = []
  const unreadable = (...values: string[]) => values.some((value) => parseInteger(value) === null)
  if (draft.key.trim() === '' || draft.name.trim() === '') {
    problems.push('Key and name are required.')
  }
  if (unreadable(draft.sides)) {
    problems.push('Sides must be a whole number.')
  }
  if (draft.exceptionalPercent !== '' && unreadable(draft.exceptionalPercent)) {
    problems.push('Exceptional percent must be a whole number.')
  }
  if (
    draft.chaos &&
    unreadable(draft.chaos.min, draft.chaos.max, draft.chaos.neutral, draft.chaos.shiftPerPoint)
  ) {
    problems.push('Every chaos field must be a whole number.')
  }
  if (draft.levels.some((level) => level.key.trim() === '' || level.label.trim() === '')) {
    problems.push('Every level needs a key and a label.')
  }
  if (unreadable(...draft.levels.map((level) => level.target))) {
    problems.push('Every level target must be a whole number.')
  }
  const keys = draft.levels.map((level) => level.key.trim())
  if (new Set(keys).size !== keys.length) {
    problems.push('Level keys must be unique.')
  }
  return problems
}

/** Sets a field, or removes it when the draft left it empty, unless it is stored as null. */
function put(target: Stored, field: string, value: unknown) {
  if (value !== undefined) {
    target[field] = value
  } else if (target[field] !== null) {
    // eslint-disable-next-line @typescript-eslint/no-dynamic-delete
    delete target[field]
  }
}

/**
 * The oracle to save: the stored one with the edits laid over it, so every field the editor does
 * not know survives. `chaos.tracker` and the random event are version 2 fields: only a version 2
 * draft edits and sends them.
 */
export function toOracle(
  original: LikelihoodOracle,
  draft: LikelihoodDraft,
  schemaVersion: number,
): LikelihoodOracle {
  const oracle: Stored = { ...original, key: draft.key, name: draft.name }
  oracle.sides = parseInteger(draft.sides) ?? original.sides
  oracle.levels = draft.levels.map((level) => ({
    ...level.original,
    key: level.key,
    label: level.label,
    target: parseInteger(level.target) ?? level.original.target,
  }))
  const chaos: Stored | null = draft.chaos && {
    ...record(original.chaos),
    min: parseInteger(draft.chaos.min),
    max: parseInteger(draft.chaos.max),
    neutral: parseInteger(draft.chaos.neutral),
    shiftPerPoint: parseInteger(draft.chaos.shiftPerPoint),
  }
  if (chaos && schemaVersion < 2) {
    delete chaos.tracker
  } else if (chaos && trackerEditable(original)) {
    put(chaos, 'tracker', draft.chaos?.tracker === '' ? undefined : draft.chaos?.tracker)
  }
  put(oracle, 'chaos', chaos ?? undefined)
  put(oracle, 'exceptionalPercent', parseInteger(draft.exceptionalPercent) ?? undefined)
  put(
    oracle,
    'randomEvent',
    draft.randomEvent !== '' && schemaVersion >= 2
      ? { ...record(original.randomEvent), table: draft.randomEvent }
      : undefined,
  )
  // The stored oracle has fields the generated type does not list.
  return oracle as unknown as LikelihoodOracle
}

/**
 * A new likelihood oracle: the smallest the domain accepts that is usable, two levels on a d100
 * with no chaos factor, exceptional bands or random event; the editor adds those.
 */
export function newLikelihood(key: string, name: string): LikelihoodOracle {
  return {
    key,
    name,
    sides: 100,
    levels: [
      { key: 'unlikely', label: 'Unlikely', target: 35 },
      { key: 'likely', label: 'Likely', target: 65 },
    ],
  }
}
