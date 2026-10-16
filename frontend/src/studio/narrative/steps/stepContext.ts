import type { GameSystemNarrative } from '../useGameSystemNarrative'

/** What the step forms pick from: the draft's own tables, trackers and tags. */
export interface StepContext {
  tables: { key: string; name: string }[]
  trackers: { key: string; name: string }[]
  tags: { key: string; name: string }[]
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
  }
}
