/**
 * A narrow, typed reader for the parts of a GameSystem preset file (`backend/presets/*.json`) that
 * the card sheets use. A missing or malformed field throws, naming where it is: a preset that
 * changes shape must fail the build, never print a wrong card.
 */

export interface PresetStep {
  key: string
  kind: string
  title: string
  prompt?: string
  tip?: string
  options: string[]
}

export interface PresetSceneType {
  key: string
  name: string
  purpose: string
  tips?: string
  setup: PresetStep[]
  play: PresetStep[]
  closing: PresetStep[]
}

/** What a table entry does besides saying its text. `other` effects have no paper wording. */
export type PresetEffect =
  | { kind: 'tracker'; tracker: string; op: string; value: number }
  | { kind: 'nextScene'; sceneType: string }
  | { kind: 'createThread'; name: string }
  | { kind: 'other'; name: string }

export interface PresetTable {
  key: string
  name: string
  entries: { text: string; weight?: number; sceneType?: string; effects: PresetEffect[] }[]
}

export interface PresetTracker {
  key: string
  name: string
  hint: string
  kind: 'clock' | 'counter'
  /** Segments of a clock, or the maximum of a counter. */
  size: number
  /** The lowest value: 0 for a clock. */
  min: number
  levels: { upTo?: number; label: string }[]
}

export interface PresetFlow {
  key: string
  name: string
  description: string
  oracles: string[]
  trackers: string[]
}

export interface Preset {
  systemName: string
  license: string
  trackers: PresetTracker[]
  factSlots: { key: string; label: string }[]
  likelihoods: string[]
  tables: PresetTable[]
  sceneTypes: PresetSceneType[]
  flows: PresetFlow[]
}

type Rec = Record<string, unknown>

function rec(value: unknown, at: string): Rec {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new Error(`Preset ${at}: expected an object.`)
  }
  return value as Rec
}

function list(value: unknown, at: string): unknown[] {
  if (!Array.isArray(value)) {
    throw new Error(`Preset ${at}: expected a list.`)
  }
  return value as unknown[]
}

function str(o: Rec, key: string, at: string): string {
  const value = o[key]
  if (typeof value !== 'string' || value === '') {
    throw new Error(`Preset ${at}: "${key}" must be a non-empty string.`)
  }
  return value
}

function optStr(o: Rec, key: string, at: string): string | undefined {
  return o[key] === undefined || o[key] === null ? undefined : str(o, key, at)
}

function num(o: Rec, key: string, at: string): number {
  const value = o[key]
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new Error(`Preset ${at}: "${key}" must be a number.`)
  }
  return value
}

function each<T>(o: Rec, key: string, at: string, read: (item: Rec, at: string) => T): T[] {
  return list(o[key], `${at}.${key}`).map((item, index) => {
    const here = `${at}.${key}[${String(index)}]`
    return read(rec(item, here), here)
  })
}

function readStep(o: Rec, at: string): PresetStep {
  return {
    key: str(o, 'key', at),
    kind: str(o, 'kind', at),
    title: str(o, 'title', at),
    prompt: optStr(o, 'prompt', at),
    tip: optStr(o, 'tip', at),
    options:
      o.options === undefined
        ? []
        : each(o, 'options', at, (option, here) => str(option, 'label', here)),
  }
}

function readEffect(o: Rec, at: string): PresetEffect {
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

function readSceneType(o: Rec, at: string): PresetSceneType {
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

function readTracker(o: Rec, at: string): PresetTracker {
  const kind = str(o, 'kind', at)
  if (kind !== 'clock' && kind !== 'counter') {
    throw new Error(`Preset ${at}: "kind" must be clock or counter.`)
  }
  return {
    key: str(o, 'key', at),
    name: str(o, 'name', at),
    hint: str(o, 'hint', at),
    kind,
    size: num(o, kind === 'clock' ? 'segments' : 'max', at),
    min: kind === 'clock' ? 0 : num(o, 'min', at),
    levels:
      o.levels === undefined
        ? []
        : each(o, 'levels', at, (level, here) => ({
            upTo: level.upTo === undefined ? undefined : num(level, 'upTo', here),
            label: str(level, 'label', here),
          })),
  }
}

export function readPreset(raw: unknown): Preset {
  const root = rec(raw, 'file')
  const system = rec(root.gameSystem, 'gameSystem')
  const oracles = rec(root.oracles, 'oracles')
  return {
    systemName: str(system, 'name', 'gameSystem'),
    license: str(rec(system.credits, 'gameSystem.credits'), 'license', 'gameSystem.credits'),
    trackers: each(root, 'trackers', 'file', readTracker),
    factSlots: each(root, 'factSlots', 'file', (slot, at) => ({
      key: str(slot, 'key', at),
      label: str(slot, 'label', at),
    })),
    likelihoods: each(oracles, 'likelihood', 'oracles', (item, at) => str(item, 'key', at)),
    tables: each(oracles, 'tables', 'oracles', (table, at) => ({
      key: str(table, 'key', at),
      name: str(table, 'name', at),
      entries: each(table, 'entries', at, (entry, here) => ({
        text: str(entry, 'text', here),
        weight: entry.weight === undefined ? undefined : num(entry, 'weight', here),
        sceneType: optStr(entry, 'sceneType', here),
        effects: entry.effects === undefined ? [] : each(entry, 'effects', here, readEffect),
      })),
    })),
    sceneTypes: each(root, 'sceneTypes', 'file', readSceneType),
    flows: each(root, 'flows', 'file', (flow, at) => ({
      key: str(flow, 'key', at),
      name: str(flow, 'name', at),
      description: str(flow, 'description', at),
      oracles: list(flow.oracles, `${at}.oracles`).map((key, index) => {
        if (typeof key !== 'string') {
          throw new Error(`Preset ${at}.oracles[${String(index)}]: expected a key.`)
        }
        return key
      }),
      trackers: list(flow.trackers, `${at}.trackers`).map((key, index) => {
        if (typeof key !== 'string') {
          throw new Error(`Preset ${at}.trackers[${String(index)}]: expected a key.`)
        }
        return key
      }),
    })),
  }
}
