import type { Release, ReleaseTracker } from './release'

/** A counter with more boxes than this has a write-in box instead. */
export const WRITE_IN_ABOVE = 20

export interface ClockSheet {
  kind: 'clock'
  key: string
  name: string
  hint?: string
  segments: number
}

export interface CounterSheet {
  kind: 'counter'
  key: string
  name: string
  hint?: string
  min: number
  max: number
  /** "0-5": the values the counter takes. */
  range: string
  /** The value the counter starts at, when the release names one. */
  start?: number
  /** A box per value, the first of each level carrying its label; `null` for a write-in box. */
  boxes: { value: number; label?: string }[] | null
  /** The levels with the values they cover. */
  levels: { label: string; range: string }[]
}

export type TrackerSheetEntry = ClockSheet | CounterSheet

const rangeText = (from: number, to: number) =>
  from === to ? String(from) : `${String(from)}-${String(to)}`

function counterSheet(tracker: ReleaseTracker): CounterSheet {
  const { min, size: max, initial } = tracker
  if (!Number.isInteger(min) || !Number.isInteger(max)) {
    throw new Error('its minimum and maximum must be whole numbers.')
  }
  if (max < min) {
    throw new Error(`it counts from ${String(min)} to ${String(max)}.`)
  }
  if (initial !== undefined && (!Number.isInteger(initial) || initial < min || initial > max)) {
    throw new Error(
      `it starts at ${String(initial)}, outside its range of ${String(min)} to ${String(max)}.`,
    )
  }
  let from = min
  const levels = tracker.levels.map((level, index) => {
    if (level.upTo === undefined && index < tracker.levels.length - 1) {
      throw new Error(`the level "${level.label}" has no "upTo" but is not the last.`)
    }
    const to = level.upTo ?? max
    if (to > max) {
      throw new Error(`the level "${level.label}" runs to ${String(to)}, above ${String(max)}.`)
    }
    if (to < from) {
      throw new Error(
        `the level "${level.label}" ends at ${String(to)}, before the value ${String(from)} it should start at.`,
      )
    }
    const covered = { label: level.label, first: from, range: rangeText(from, to) }
    from = to + 1
    return covered
  })
  const count = max - min + 1
  return {
    kind: 'counter',
    key: tracker.key,
    name: tracker.name,
    hint: tracker.hint,
    min,
    max,
    range: `${String(min)}-${String(max)}`,
    start: initial,
    boxes:
      count > WRITE_IN_ABOVE
        ? null
        : Array.from({ length: count }, (_, at) => {
            const label = levels.find((level) => level.first === min + at)?.label
            return label === undefined ? { value: min + at } : { value: min + at, label }
          }),
    levels: levels.map(({ label, range }) => ({ label, range })),
  }
}

function trackerSheet(tracker: ReleaseTracker): TrackerSheetEntry {
  try {
    if (tracker.kind === 'counter') {
      return counterSheet(tracker)
    }
    if (!Number.isInteger(tracker.size) || tracker.size < 1) {
      throw new Error('its segments must be a whole number of at least 1.')
    }
    return {
      kind: 'clock',
      key: tracker.key,
      name: tracker.name,
      hint: tracker.hint,
      segments: tracker.size,
    }
  } catch (error) {
    throw new Error(
      `The Tracker "${tracker.key}" cannot be printed: ${error instanceof Error ? error.message : 'unknown error'}`,
      { cause: error },
    )
  }
}

/**
 * The trackers of a flow, in flow order, as the clocks to shade and the counters to tick. A flow
 * without trackers has nothing to print (an empty list). Throws, naming the Tracker, for a flow the
 * release lacks, a Tracker it lacks, and a clock or counter that cannot be drawn.
 */
export function buildTrackerSheet(release: Release, flowKey: string): TrackerSheetEntry[] {
  const flow = release.flows.find((candidate) => candidate.key === flowKey)
  if (flow === undefined) {
    throw new Error(`The release has no flow "${flowKey}".`)
  }
  return flow.trackers.map((key) => {
    const tracker = release.trackers.find((candidate) => candidate.key === key)
    if (tracker === undefined) {
      throw new Error(
        `The flow "${flowKey}" uses the Tracker "${key}", which the release does not have.`,
      )
    }
    return trackerSheet(tracker)
  })
}
