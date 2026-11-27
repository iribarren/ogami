/**
 * A narrow, typed reader for the parts of a GameSystem release that printing uses. The release
 * content endpoint and the preset files (`backend/presets/*.json`) are the same content (ADR 0036).
 * A missing or malformed field throws and names where it is: content that changes shape must
 * fail, never print a wrong card. What printing does not need is not read.
 */

export interface ReleaseTracker {
  key: string
  name: string
  hint?: string
  kind: 'clock' | 'counter'
  /** Segments of a clock, or the maximum of a counter. */
  size: number
  /** The lowest value: 0 for a clock. */
  min: number
  levels: { upTo?: number; label: string }[]
}

/**
 * What a check outcome does. Only a Tracker or a sheet change has wording on a card; any other
 * kind is kept as `other`, so a card can leave it out and a paper edition can refuse it.
 */
export type OutcomeEffect =
  | { kind: 'tracker'; tracker: string; op: string; value: number }
  | { kind: 'sheet'; field: string; op: string; value: number | string }
  | { kind: 'other'; name: string }

/** A band (or special result) of a check step, with what it does. */
export interface StepOutcome {
  band: string
  effects: OutcomeEffect[]
}

/** A step of a Scene Type. A kind this reader does not know is kept as it is: the card degrades. */
export interface ReleaseStep {
  key: string
  kind: string
  title: string
  prompt?: string
  tip?: string
  options: string[]
  /** Only a `check` step has outcomes. */
  outcomes: StepOutcome[]
}

export interface ReleaseSceneType {
  key: string
  name: string
  purpose: string
  tips?: string
  setup: ReleaseStep[]
  play: ReleaseStep[]
  closing: ReleaseStep[]
}

/** What a table entry does besides saying its text. `other` effects have no printed wording. */
export type EntryEffect =
  | { kind: 'tracker'; tracker: string; op: string; value: number }
  | { kind: 'nextScene'; sceneType: string }
  | { kind: 'createThread'; name: string }
  | { kind: 'other'; name: string }

export interface TableEntry {
  text: string
  /** Weighted tables: the copies of the entry. */
  weight?: number
  /** Ranged tables: the numbers the entry answers to, both ends included. */
  min?: number
  max?: number
  sceneType?: string
  effects: EntryEffect[]
}

export interface ReleaseTable {
  key: string
  name: string
  entries: TableEntry[]
}

export interface ReleaseFlow {
  key: string
  name: string
  description?: string
  oracles: string[]
  trackers: string[]
  /** The tables of the phases that pick the next Scene Type with an `oracle` selection, in order. */
  selectionTables: string[]
}

export interface Release {
  systemName: string
  license?: string
  trackers: ReleaseTracker[]
  factSlots: { key: string; label: string }[]
  tags: { key: string; label: string }[]
  likelihoods: string[]
  tables: ReleaseTable[]
  sceneTypes: ReleaseSceneType[]
  flows: ReleaseFlow[]
}

type Rec = Record<string, unknown>

const fail = (at: string, problem: string): never => {
  throw new Error(`Release ${at}: ${problem}`)
}

export function asRec(value: unknown, at: string): Rec {
  return typeof value !== 'object' || value === null || Array.isArray(value)
    ? fail(at, 'expected an object.')
    : (value as Rec)
}

export function asList(value: unknown, at: string): unknown[] {
  return Array.isArray(value) ? (value as unknown[]) : fail(at, 'expected a list.')
}

export function asStr(value: unknown, at: string): string {
  return typeof value === 'string' && value !== ''
    ? value
    : fail(at, 'expected a non-empty string.')
}

export function asNum(value: unknown, at: string): number {
  return typeof value === 'number' && Number.isFinite(value)
    ? value
    : fail(at, 'expected a number.')
}

const asInt = (value: unknown, at: string): number => {
  const number = asNum(value, at)
  return Number.isInteger(number) ? number : fail(at, 'expected a whole number.')
}

const str = (o: Rec, key: string, at: string) => asStr(o[key], `${at}.${key}`)
const num = (o: Rec, key: string, at: string) => asNum(o[key], `${at}.${key}`)

function optStr(o: Rec, key: string, at: string): string | undefined {
  return o[key] === undefined || o[key] === null ? undefined : str(o, key, at)
}

/** A list of objects, each read with its path; an absent optional list is empty. */
function each<T>(
  o: Rec,
  key: string,
  at: string,
  read: (item: Rec, at: string) => T,
  optional = false,
): T[] {
  if (optional && (o[key] === undefined || o[key] === null)) {
    return []
  }
  return asList(o[key], `${at}.${key}`).map((item, index) => {
    const here = `${at}.${key}[${String(index)}]`
    return read(asRec(item, here), here)
  })
}

function keys(o: Rec, key: string, at: string): string[] {
  return asList(o[key], `${at}.${key}`).map((item, index) =>
    asStr(item, `${at}.${key}[${String(index)}]`),
  )
}

function readOutcomeEffect(o: Rec, at: string): OutcomeEffect {
  const kind = str(o, 'kind', at)
  if (kind === 'tracker' && typeof o.value === 'number') {
    return {
      kind,
      tracker: str(o, 'tracker', at),
      op: str(o, 'op', at),
      value: num(o, 'value', at),
    }
  }
  if (kind === 'sheet') {
    const amount = o.value
    const dice = typeof amount === 'object' && amount !== null && 'dice' in amount
    // A number or `{dice}` (or no value at all, which fails below); a mark, a string or a
    // `{formula}` amount has no wording on a card.
    if (dice || typeof amount === 'number' || amount === undefined) {
      return {
        kind,
        field: str(o, 'field', at),
        op: str(o, 'op', at),
        value: dice ? str(amount, 'dice', `${at}.value`) : num(o, 'value', at),
      }
    }
  }
  return { kind: 'other', name: kind }
}

function readStep(o: Rec, at: string): ReleaseStep {
  const kind = str(o, 'kind', at)
  return {
    key: str(o, 'key', at),
    kind,
    title: str(o, 'title', at),
    prompt: optStr(o, 'prompt', at),
    tip: optStr(o, 'tip', at),
    options: each(o, 'options', at, (option, here) => str(option, 'label', here), true),
    outcomes:
      kind === 'check'
        ? each(
            o,
            'outcomes',
            at,
            (outcome, here) => ({
              // A check outcome names a band, or a special result of the roll.
              band:
                outcome.band === undefined
                  ? str(outcome, 'special', here)
                  : str(outcome, 'band', here),
              effects: each(outcome, 'effects', here, readOutcomeEffect, true),
            }),
            true,
          )
        : [],
  }
}

function readSceneType(o: Rec, at: string): ReleaseSceneType {
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    purpose: str(o, 'purpose', at),
    tips: optStr(o, 'tips', at),
    setup: each(o, 'setup', at, readStep),
    play: each(o, 'play', at, readStep),
    closing: each(o, 'closing', at, readStep),
  }
}

function readEntryEffect(o: Rec, at: string): EntryEffect {
  const kind = str(o, 'kind', at)
  if (kind === 'tracker') {
    return {
      kind,
      tracker: str(o, 'tracker', at),
      op: str(o, 'op', at),
      value: num(o, 'value', at),
    }
  }
  if (kind === 'nextScene') {
    return { kind, sceneType: str(o, 'sceneType', at) }
  }
  if (kind === 'createThread') {
    return { kind, name: str(o, 'name', at) }
  }
  return { kind: 'other', name: kind }
}

function readEntry(o: Rec, at: string): TableEntry {
  const ranged = o.min !== undefined || o.max !== undefined
  const min = ranged ? asInt(o.min, `${at}.min`) : undefined
  const max = ranged ? asInt(o.max, `${at}.max`) : undefined
  if (min !== undefined && max !== undefined && min > max) {
    fail(at, '"min" is above "max".')
  }
  const weight = o.weight === undefined ? undefined : asInt(o.weight, `${at}.weight`)
  if (weight !== undefined && weight < 1) {
    fail(`${at}.weight`, 'expected a whole number of at least 1.')
  }
  return {
    text: str(o, 'text', at),
    weight,
    min,
    max,
    sceneType: optStr(o, 'sceneType', at),
    effects: each(o, 'effects', at, readEntryEffect, true),
  }
}

function readTracker(o: Rec, at: string): ReleaseTracker {
  const kind = str(o, 'kind', at)
  if (kind !== 'clock' && kind !== 'counter') {
    return fail(`${at}.kind`, 'must be clock or counter.')
  }
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    hint: optStr(o, 'hint', at),
    kind,
    size: num(o, kind === 'clock' ? 'segments' : 'max', at),
    min: kind === 'clock' ? 0 : num(o, 'min', at),
    levels: each(
      o,
      'levels',
      at,
      (level, here) => ({
        upTo: level.upTo === undefined ? undefined : num(level, 'upTo', here),
        label: str(level, 'label', here),
      }),
      true,
    ),
  }
}

function readFlow(o: Rec, at: string): ReleaseFlow {
  const selectionTables = each(
    o,
    'phases',
    at,
    (phase, here) => {
      const selection =
        phase.selection === undefined ? undefined : asRec(phase.selection, `${here}.selection`)
      return selection?.rule === 'oracle' ? str(selection, 'table', `${here}.selection`) : undefined
    },
    true,
  ).filter((table): table is string => table !== undefined)
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    description: optStr(o, 'description', at),
    oracles: keys(o, 'oracles', at),
    trackers: keys(o, 'trackers', at),
    selectionTables: [...new Set(selectionTables)],
  }
}

/**
 * Reads a release (or a preset file) into what printing needs; throws, naming the path, on anything
 * else. Schema version 1 has no trackers, Fact slots, Scene Types or flows: they read as empty, and
 * such a release has no flow to print.
 */
export function readRelease(raw: unknown): Release {
  const root = asRec(raw, 'file')
  const system = asRec(root.gameSystem, 'gameSystem')
  const oracles = asRec(root.oracles, 'oracles')
  const credits =
    system.credits === undefined ? undefined : asRec(system.credits, 'gameSystem.credits')
  return {
    systemName: str(system, 'name', 'gameSystem'),
    license: credits === undefined ? undefined : optStr(credits, 'license', 'gameSystem.credits'),
    trackers: each(root, 'trackers', 'file', readTracker, true),
    factSlots: each(
      root,
      'factSlots',
      'file',
      (slot, at) => ({ key: str(slot, 'key', at), label: str(slot, 'label', at) }),
      true,
    ),
    tags: each(
      root,
      'tags',
      'file',
      (tag, at) => ({ key: str(tag, 'key', at), label: str(tag, 'label', at) }),
      true,
    ),
    likelihoods: each(oracles, 'likelihood', 'oracles', (item, at) => str(item, 'key', at)),
    tables: each(oracles, 'tables', 'oracles', (table, at) => ({
      key: str(table, 'key', at),
      name: str(table, 'name', at),
      entries: each(table, 'entries', at, readEntry),
    })),
    sceneTypes: each(root, 'sceneTypes', 'file', readSceneType, true),
    flows: each(root, 'flows', 'file', readFlow, true),
  }
}
