import type { GameSystemNarrative } from '../useGameSystemNarrative'

/** What the step and effect forms pick from: the draft's own tables, trackers, tags, Scene Types, fact slots and sheet fields. */
export interface StepContext {
  tables: { key: string; name: string }[]
  trackers: { key: string; name: string }[]
  tags: { key: string; name: string }[]
  /** For the effect pickers; absent means none to pick yet. */
  sceneTypes?: { key: string; name: string }[]
  factSlots?: { key: string; name: string; type: string }[]
  /** The number and track fields of the sheet, which a sheet effect may change. */
  sheetFields?: { key: string; name: string }[]
}

/** The pickers' options from the narrative read model (tables from the catalog, the rest from the sections). */
export function stepContext(narrative: GameSystemNarrative): StepContext {
  const named = (items: { key: string; definition: Record<string, unknown> }[], field: string) =>
    items.map(({ key, definition }) => ({
      key,
      name: typeof definition[field] === 'string' ? definition[field] : key,
    }))
  return {
    tables: narrative.catalog.tables.map(({ key, name }) => ({ key, name })),
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
