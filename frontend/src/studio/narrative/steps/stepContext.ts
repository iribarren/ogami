import type { GameSystemNarrative } from '../useGameSystemNarrative'

/** What the step and effect forms pick from: the draft's own tables, likelihood oracles, checks, trackers, tags, Scene Types, fact slots and sheet fields. */
export interface StepContext {
  /** The tables with their entry keys, which a table step branches on. */
  tables: { key: string; name: string; entries: string[] }[]
  /** The likelihood oracles with their level keys, which an oracle step may ask at. */
  likelihood: { key: string; name: string; levels: string[] }[]
  /** The checks with their bands (in order) and difficulty range, which a check step names. */
  checks: {
    key: string
    name: string
    bands: string[]
    /** Null when the check has no difficulty the author sets. */
    difficulty: { default: number; min: number | null; max: number | null } | null
  }[]
  trackers: { key: string; name: string }[]
  tags: { key: string; name: string }[]
  /** For the effect pickers; absent means none to pick yet. */
  sceneTypes?: { key: string; name: string }[]
  factSlots?: { key: string; name: string; type: string }[]
  /**
   * The sheet fields a sheet effect may change: number and track fields, and conditions fields,
   * which carry their `options` (the effect marks or clears one of them).
   */
  sheetFields?: { key: string; name: string; options?: { key: string; name: string }[] }[]
}

/** The pickers' options from the narrative read model (tables from the catalog, the rest from the sections). */
export function stepContext(narrative: GameSystemNarrative): StepContext {
  const named = (items: { key: string; definition: Record<string, unknown> }[], field: string) =>
    items.map(({ key, definition }) => ({
      key,
      name: typeof definition[field] === 'string' ? definition[field] : key,
    }))
  return {
    tables: narrative.catalog.tables.map(({ key, name, entries }) => ({ key, name, entries })),
    likelihood: narrative.catalog.likelihood.map(({ key, name, levels }) => ({
      key,
      name,
      levels,
    })),
    checks: narrative.catalog.checks.map(({ key, label, bands, difficulty }) => ({
      key,
      name: label,
      bands,
      difficulty,
    })),
    trackers: named(narrative.trackers, 'name'),
    tags: named(narrative.tags, 'label'),
    sceneTypes: named(narrative.sceneTypes, 'name'),
    factSlots: narrative.factSlots.map(({ key, definition }) => ({
      key,
      name: typeof definition.label === 'string' ? definition.label : key,
      type: typeof definition.type === 'string' ? definition.type : 'text',
    })),
    sheetFields: narrative.catalog.fields.map(({ key, label }) => ({ key, name: label })),
  }
}
