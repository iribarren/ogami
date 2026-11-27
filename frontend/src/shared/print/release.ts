/**
 * A narrow, typed reader for the parts of a GameSystem release that printing uses. The release
 * content endpoint and the preset files (`backend/presets/*.json`) are the same content (ADR 0036).
 * A missing or malformed field throws and names where it is: content that changes shape must
 * fail, never print a wrong card. What printing does not need is not read.
 */

import { PHASE_HOOKS, type PhaseHook } from '@/shared/flowDiagram/flowDiagram'

export interface ReleaseTracker {
  key: string
  name: string
  hint?: string
  kind: 'clock' | 'counter'
  /** Segments of a clock, or the maximum of a counter. */
  size: number
  /** The lowest value: 0 for a clock. */
  min: number
  /** The value a counter starts at, when the release says. */
  initial?: number
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

/** How a phase picks the next Scene Type: from a list, or by rolling a table. */
export type PhaseSelection =
  { rule: 'sequence' | 'player'; sceneTypes: string[] } | { rule: 'oracle'; table: string }

/** A hook of a phase with the steps it runs; hooks without steps are not read. */
export interface PhaseHookSteps {
  hook: PhaseHook
  steps: ReleaseStep[]
}

export interface ReleasePhase {
  key: string
  name: string
  mode: 'once' | 'loop'
  description?: string
  selection: PhaseSelection
  /** The hooks that have steps, in `PHASE_HOOKS` order. */
  hooks: PhaseHookSteps[]
}

export interface ReleaseFlow {
  key: string
  name: string
  description?: string
  introduction?: string
  oracles: string[]
  trackers: string[]
  phases: ReleasePhase[]
  /** The tables of the phases that pick the next Scene Type with an `oracle` selection, in order. */
  selectionTables: string[]
}

/** A check as a reference page words it: what it is, how to roll it and what its bands are called. */
export interface ReleaseCheck {
  key: string
  label: string
  hint: string
  /** The dice as written ("1d6", "d%"); a pool check has none. */
  dice?: string
  /** The bands in order; their thresholds and formulas are not read. */
  bands: { key: string; label: string }[]
}

/** An oracle answered with a likelihood: it says yes on a roll at or under the level's target. */
export interface ReleaseLikelihoodOracle {
  key: string
  name: string
  sides: number
  levels: { key: string; label: string; target: number }[]
  exceptionalPercent?: number
}

/** A Fact slot or a Tag: what the campaign log asks to write down. */
export interface ReleaseLogEntry {
  key: string
  label: string
  hint?: string
}

export interface Release {
  systemName: string
  license?: string
  trackers: ReleaseTracker[]
  factSlots: ReleaseLogEntry[]
  tags: ReleaseLogEntry[]
  checks: ReleaseCheck[]
  /** The keys of `likelihoodOracles`. */
  likelihoods: string[]
  likelihoodOracles: ReleaseLikelihoodOracle[]
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

function optNum(o: Rec, key: string, at: string): number | undefined {
  return o[key] === undefined || o[key] === null ? undefined : num(o, key, at)
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
  const weight = o.weight === undefined ? undefined : asNum(o.weight, `${at}.weight`)
  if (weight !== undefined && (!Number.isInteger(weight) || weight < 1)) {
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
    initial: optNum(o, 'initial', at),
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

function readCheck(o: Rec, at: string): ReleaseCheck {
  return {
    key: str(o, 'key', at),
    label: str(o, 'label', at),
    hint: str(o, 'hint', at),
    dice: optStr(o, 'dice', at),
    bands: each(o, 'bands', at, (band, here) => ({
      key: str(band, 'key', here),
      label: str(band, 'label', here),
    })),
  }
}

function readLikelihoodOracle(o: Rec, at: string): ReleaseLikelihoodOracle {
  const sides = asInt(o.sides, `${at}.sides`)
  if (sides < 2) {
    fail(`${at}.sides`, 'expected a whole number of at least 2.')
  }
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    sides,
    levels: each(o, 'levels', at, (level, here) => {
      const target = asInt(level.target, `${here}.target`)
      if (target < 0 || target > sides) {
        fail(`${here}.target`, `expected a whole number from 0 to ${String(sides)}.`)
      }
      return { key: str(level, 'key', here), label: str(level, 'label', here), target }
    }),
    exceptionalPercent:
      o.exceptionalPercent === undefined || o.exceptionalPercent === null
        ? undefined
        : asInt(o.exceptionalPercent, `${at}.exceptionalPercent`),
  }
}

function readLogEntry(o: Rec, at: string): ReleaseLogEntry {
  return { key: str(o, 'key', at), label: str(o, 'label', at), hint: optStr(o, 'hint', at) }
}

function readSelection(o: Rec, at: string): PhaseSelection {
  const rule = str(o, 'rule', at)
  if (rule === 'sequence' || rule === 'player') {
    return { rule, sceneTypes: keys(o, 'sceneTypes', at) }
  }
  if (rule === 'oracle') {
    return { rule, table: str(o, 'table', at) }
  }
  return fail(`${at}.rule`, `unknown selection rule "${rule}".`)
}

function readPhase(o: Rec, at: string): ReleasePhase {
  const mode = str(o, 'mode', at)
  if (mode !== 'once' && mode !== 'loop') {
    return fail(`${at}.mode`, `unknown mode "${mode}": expected once or loop.`)
  }
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    mode,
    description: optStr(o, 'description', at),
    selection: readSelection(asRec(o.selection, `${at}.selection`), `${at}.selection`),
    hooks: PHASE_HOOKS.flatMap((hook) => {
      const steps = each(o, hook, at, readStep, true)
      return steps.length > 0 ? [{ hook, steps }] : []
    }),
  }
}

function readFlow(o: Rec, at: string): ReleaseFlow {
  const phases = each(o, 'phases', at, readPhase, true)
  const selectionTables = phases.flatMap(({ selection }) =>
    selection.rule === 'oracle' ? [selection.table] : [],
  )
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    description: optStr(o, 'description', at),
    introduction: optStr(o, 'introduction', at),
    oracles: keys(o, 'oracles', at),
    trackers: keys(o, 'trackers', at),
    phases,
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
  const likelihoodOracles = each(oracles, 'likelihood', 'oracles', readLikelihoodOracle)
  const credits =
    system.credits === undefined ? undefined : asRec(system.credits, 'gameSystem.credits')
  return {
    systemName: str(system, 'name', 'gameSystem'),
    license: credits === undefined ? undefined : optStr(credits, 'license', 'gameSystem.credits'),
    trackers: each(root, 'trackers', 'file', readTracker, true),
    factSlots: each(root, 'factSlots', 'file', readLogEntry, true),
    tags: each(root, 'tags', 'file', readLogEntry, true),
    checks: each(root, 'checks', 'file', readCheck, true),
    likelihoods: likelihoodOracles.map((oracle) => oracle.key),
    likelihoodOracles,
    tables: each(oracles, 'tables', 'oracles', (table, at) => ({
      key: str(table, 'key', at),
      name: str(table, 'name', at),
      entries: each(table, 'entries', at, readEntry),
    })),
    sceneTypes: each(root, 'sceneTypes', 'file', readSceneType, true),
    flows: each(root, 'flows', 'file', readFlow, true),
  }
}
