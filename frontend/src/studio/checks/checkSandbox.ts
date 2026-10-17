import {
  FormulaEvaluationFailed,
  InvalidFormula,
  parseFormula,
  type Reference,
} from '@/shared/lib/formula'
import { computeDerived, integer } from '@/shared/sheet/derivedValues'
import type { Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, TrackerReading } from '@/shared/sheet/types'

import type { Sample } from '../sheet/useSheetSamples'
import type { BandDraft, DifficultyDraft } from './checkDraft'
import type { CheckTestRollRequest, GameSystemChecks } from './useGameSystemChecks'

/** The most a track without a declared max may hold in a test roll, as the server. */
const openTrackMax = 999

/** What the sandbox reads: the form's check, the sample values and what was typed beside them. */
export interface SandboxState {
  catalog: Pick<GameSystemChecks, 'fields' | 'trackers'>
  /** The sheet's fields with their defaults and ranges; empty while the sheet is not known. */
  template: SheetFieldView[]
  /** What was typed for the fields, shared with the Sheet tab. */
  samples: Record<string, Sample>
  /** What was typed for the Trackers, shared with the Sheet tab. */
  trackerSamples: Record<string, string>
  /** The difficulty the form declares, or null. */
  difficulty: DifficultyDraft | null
  /** What was typed for the difficulty; null until the person types, which shows the default. */
  difficultyText: string | null
  situationalText: string
  bands: BandDraft[]
}

/** One input of the sandbox: what it shows, what an empty one stands for and what is wrong with it. */
export interface SandboxInput {
  key: string
  label: string
  value: string
  /** What an empty input stands for: the server applies it. */
  placeholder: string
  error: string | null
}

/** A band's bound for the samples: a number, the rest (the last band), or none can be computed. */
export type Bound = { kind: 'value'; value: number } | { kind: 'rest' } | { kind: 'unknown' }

/** The request of a roll: the typed samples; an empty input is left out. */
type SandboxRequest = CheckTestRollRequest & {
  sheet: Record<string, number>
  trackers: Record<string, number>
}

/** What the sandbox shows and sends for the current inputs. */
export interface Sandbox {
  fields: SandboxInput[]
  trackers: SandboxInput[]
  /** Null while the form's check has no difficulty. */
  difficulty: SandboxInput | null
  situational: SandboxInput
  bounds: Bound[]
  /** Set when the computed bounds do not strictly increase. */
  warning: string | null
  /** Null while an input is malformed: nothing is rolled then. */
  request: SandboxRequest | null
}

/** A whole number within `min..max` (either may be open), or why not. */
function whole(
  text: string,
  min: number | null,
  max: number | null,
): { value: number } | { error: string } {
  const value = integer(text)
  if (value === undefined) {
    return { error: 'Must be a whole number.' }
  }
  if ((min !== null && value < min) || (max !== null && value > max)) {
    if (min !== null && max !== null) {
      return { error: `Must be between ${String(min)} and ${String(max)}.` }
    }
    return {
      error: min !== null ? `Must be at least ${String(min)}.` : `Must be at most ${String(max)}.`,
    }
  }
  return { value }
}

/** What was typed for a number or track field: a track's is its current value. */
function typedFor(field: { type: string }, sample: Sample | undefined): string {
  if (sample?.type !== field.type) {
    return ''
  }
  const { draft } = sample
  if (typeof draft === 'string') {
    return draft
  }
  return !Array.isArray(draft) && 'current' in draft ? draft.current : ''
}

/** A number's value when nobody typed: its default, else 0, held within its range. */
function startOf(field: SheetFieldView): number {
  const start = field.default ?? 0
  return Math.min(Math.max(start, field.min ?? start), field.max ?? start)
}

/**
 * The sandbox of a check as the form holds it: the sample inputs with their problems, the band
 * bounds computed for these samples with the formula kernel, and the request a roll would send.
 * An empty input is not sent, so the server applies its default (a number its default or 0, a
 * track 0, a Tracker its initial value, no situational modifier). Nothing here throws.
 */
export function resolveSandbox(state: SandboxState): Sandbox {
  const { catalog, template, samples, trackerSamples, difficulty, bands } = state
  const request: SandboxRequest = { sheet: {}, trackers: {} }
  const problems: string[] = []
  const flag = (error: string | null) => {
    if (error !== null) {
      problems.push(error)
    }
  }

  // The number and track fields: what they read in a formula, and what is sent for them.
  const numbers = new Map<string, number | undefined>()
  const fields = catalog.fields.flatMap((field): SandboxInput[] => {
    if (field.type === 'derived') {
      return []
    }
    const known = template.find((held) => held.key === field.key)
    const typed = typedFor(field, samples[field.key])
    const isNumber = field.type === 'number'
    const empty = typed.trim() === ''
    let error: string | null = null
    let value: number | undefined
    if (empty) {
      value = isNumber ? (known === undefined ? undefined : startOf(known)) : 0
    } else {
      const parsed = isNumber
        ? whole(typed, known?.min ?? null, known?.max ?? null)
        : whole(typed, 0, field.max ?? openTrackMax)
      if ('error' in parsed) {
        error = parsed.error
      } else {
        value = parsed.value
        request.sheet[field.key] = parsed.value
      }
    }
    flag(error)
    numbers.set(field.key, value)
    return [
      {
        key: field.key,
        label: field.label,
        value: typed,
        placeholder: isNumber ? (known === undefined ? '' : String(startOf(known))) : '0',
        error,
      },
    ]
  })

  const trackerValues = new Map<string, number | undefined>()
  const trackers = catalog.trackers.map((tracker): SandboxInput => {
    const typed = trackerSamples[tracker.key] ?? ''
    let error: string | null = null
    let value = tracker.initial ?? undefined
    if (typed.trim() !== '') {
      const parsed = whole(typed, null, null)
      if ('error' in parsed) {
        error = parsed.error
      } else {
        value = parsed.value
        request.trackers[tracker.key] = parsed.value
      }
    }
    flag(error)
    trackerValues.set(tracker.key, value)
    return {
      key: tracker.key,
      label: tracker.label,
      value: typed,
      placeholder: tracker.initial === null ? '' : String(tracker.initial),
      error,
    }
  })

  let difficultyInput: SandboxInput | null = null
  let difficultyValue: number | undefined
  if (difficulty !== null) {
    const shown = state.difficultyText ?? difficulty.default
    const declared = integer(difficulty.default)
    difficultyValue = declared
    let error: string | null = null
    if (shown.trim() !== '') {
      const parsed = whole(shown, integer(difficulty.min) ?? null, integer(difficulty.max) ?? null)
      if ('error' in parsed) {
        error = parsed.error
        difficultyValue = undefined
      } else {
        difficultyValue = parsed.value
        request.difficulty = parsed.value
      }
    }
    flag(error)
    difficultyInput = {
      key: 'difficulty',
      label: 'Test difficulty',
      value: shown,
      placeholder: declared === undefined ? '' : String(declared),
      error,
    }
  }

  let situationalError: string | null = null
  if (state.situationalText.trim() !== '') {
    const parsed = whole(state.situationalText, -100, 100)
    if ('error' in parsed) {
      situationalError = parsed.error
    } else {
      request.situational = parsed.value
    }
  }
  flag(situationalError)

  // Derived values come from the sheet's formulas over these samples, when the sheet is known.
  const drafts: Record<string, Draft> = {}
  for (const field of template) {
    const value = numbers.get(field.key)
    if (field.type === 'number') {
      drafts[field.key] = value === undefined ? '' : String(value)
    } else if (field.type === 'track') {
      drafts[field.key] = {
        current: value === undefined ? '' : String(value),
        max: String(field.max ?? value ?? 0),
      }
    }
  }
  const readings = [...trackerValues].flatMap(([key, value]): TrackerReading[] =>
    value === undefined ? [] : [{ key, value }],
  )
  const derived = computeDerived(template, drafts, readings)

  const read = (reference: Reference): number | undefined => {
    if (reference.kind === 'check') {
      return difficultyValue
    }
    if (reference.kind === 'tracker') {
      return trackerValues.get(reference.key)
    }
    const field = catalog.fields.find(({ key }) => key === reference.key)
    if (field?.type === 'derived') {
      const result = derived[reference.key]
      return result !== undefined && 'value' in result ? result.value : undefined
    }
    if (reference.kind === 'trackMax') {
      return field?.max ?? numbers.get(reference.key)
    }
    return numbers.get(reference.key)
  }

  const bounds = bands.map((band, index) => boundOf(band, index === bands.length - 1, read))

  return {
    fields,
    trackers,
    difficulty: difficultyInput,
    situational: {
      key: 'situational',
      label: 'Situational modifier',
      value: state.situationalText,
      placeholder: '0',
      error: situationalError,
    },
    bounds,
    warning: nonIncreasing(bounds),
    request: problems.length > 0 ? null : request,
  }
}

/** The bound of one band for the samples; formulas read through `read`. */
function boundOf(
  band: BandDraft,
  last: boolean,
  read: (reference: Reference) => number | undefined,
): Bound {
  if (last) {
    return { kind: 'rest' }
  }
  const { upTo } = band
  switch (upTo.kind) {
    case 'number': {
      const value = integer(upTo.value)
      return value === undefined ? { kind: 'unknown' } : { kind: 'value', value }
    }
    case 'tracker': {
      const value = read({ kind: 'tracker', key: upTo.value })
      return value === undefined ? { kind: 'unknown' } : { kind: 'value', value }
    }
    case 'formula':
      try {
        return { kind: 'value', value: parseFormula(upTo.value).evaluate(read) }
      } catch (error) {
        if (error instanceof InvalidFormula || error instanceof FormulaEvaluationFailed) {
          return { kind: 'unknown' }
        }
        throw error
      }
    default:
      // No bound yet, or a shape the form keeps as stored: nothing to compute.
      return { kind: 'unknown' }
  }
}

/** Says so when the bounds that can be computed do not strictly increase; null when they do. */
function nonIncreasing(bounds: Bound[]): string | null {
  let before: { position: number; value: number } | null = null
  for (const [position, bound] of bounds.entries()) {
    if (bound.kind !== 'value') {
      continue
    }
    if (before !== null && bound.value <= before.value) {
      return `Band ${String(position + 1)}'s bound (${String(bound.value)}) is not above band ${String(before.position + 1)}'s (${String(before.value)}): for these samples the bounds do not increase.`
    }
    before = { position, value: bound.value }
  }
  return null
}
