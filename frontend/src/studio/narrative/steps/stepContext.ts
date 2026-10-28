import type { StoredCheck } from '../../checks/checkDraft'
import type { GameSystemChecks } from '../../checks/useGameSystemChecks'
import type { GameSystemSheet } from '../../sheet/useGameSystemSheet'
import type { GameSystemNarrative } from '../useGameSystemNarrative'
import type { CheckSpecial } from './stepDraft'

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
    /** The special results its pool can produce (ADR 0044); absent when the checks were not loaded, so none is offered or flagged. */
    specials?: CheckSpecial[]
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
  /**
   * The draft's economy for a shop step (ADR 0045): the gear catalog, with its categories, and
   * whether the sheet declares a currency. Absent until the sheet loads, so nothing is flagged.
   */
  economy?: { gear: { key: string; name: string; category: string | null }[]; currency: boolean }
}

/**
 * The special results a stored check can produce (ADR 0044): `critical` needs critical pairs,
 * `bestialFailure` needs Hunger dice and `messyCritical` both. A literal Hunger count of 0 means no
 * Hunger dice; a formula counts as Hunger dice, as the publisher reads it.
 */
export function specialsOf(check: StoredCheck): CheckSpecial[] {
  const pool = check.pool
  if (typeof pool !== 'object' || pool === null) {
    return []
  }
  const { criticals, hunger } = pool as Record<string, unknown>
  const critical = criticals !== undefined && criticals !== null
  const hungry =
    hunger !== undefined &&
    hunger !== null &&
    !(typeof hunger === 'object' && (hunger as { count?: unknown }).count === 0)
  return [
    ...(critical ? (['critical'] as const) : []),
    ...(critical && hungry ? (['messyCritical'] as const) : []),
    ...(hungry ? (['bestialFailure'] as const) : []),
  ]
}

/**
 * The pickers' options from the narrative read model (tables from the catalog, the rest from the
 * sections). With the draft's `checks`, each check also says which special results it can produce;
 * with its `sheet`, a shop step can pick from the gear catalog.
 */
export function stepContext(
  narrative: GameSystemNarrative,
  stored?: GameSystemChecks,
  sheet?: GameSystemSheet,
): StepContext {
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
    checks: narrative.catalog.checks.map(({ key, label, bands, difficulty }) => {
      const found = stored?.checks.find((check) => check.key === key)
      return {
        key,
        name: label,
        bands,
        difficulty,
        ...(found === undefined ? {} : { specials: specialsOf(found) }),
      }
    }),
    trackers: named(narrative.trackers, 'name'),
    tags: named(narrative.tags, 'label'),
    sceneTypes: named(narrative.sceneTypes, 'name'),
    factSlots: narrative.factSlots.map(({ key, definition }) => ({
      key,
      name: typeof definition.label === 'string' ? definition.label : key,
      type: typeof definition.type === 'string' ? definition.type : 'text',
    })),
    // Only a conditions field has options: the effect marks or clears one of them.
    sheetFields: narrative.catalog.fields.map(({ key, label, options }) => ({
      key,
      name: label,
      ...(options === null || options === undefined
        ? {}
        : { options: options.map((option) => ({ key: option.key, name: option.label })) }),
    })),
    ...(sheet === undefined
      ? {}
      : {
          economy: {
            gear: sheet.gear.map(({ key, name, category }) => ({ key, name, category })),
            currency: sheet.currency !== null,
          },
        }),
  }
}
