import type { StepContext } from '../narrative/steps/stepContext'
import type { GameSystemChecks } from './useGameSystemChecks'

/**
 * What the effects of a push cost pick from, out of the checks read model: its trackers, its
 * number and track sheet fields, and its conditions fields with their options. The rest of the
 * narrative catalog (tables, checks, tags, Scene Types) is not offered there. The fields come in
 * the sheet's order (`sheetOrder`, the keys of its fields); those it does not list follow, in
 * the order the read model gives.
 */
export function pushContext(data: GameSystemChecks, sheetOrder: string[] = []): StepContext {
  const at = (key: string) => {
    const position = sheetOrder.indexOf(key)
    return position === -1 ? sheetOrder.length : position
  }

  return {
    tables: [],
    likelihood: [],
    checks: [],
    tags: [],
    trackers: data.trackers.map(({ key, label }) => ({ key, name: label })),
    sheetFields: [
      ...data.fields
        .filter(({ type }) => type === 'number' || type === 'track')
        .map(({ key, label }) => ({ key, name: label })),
      ...data.conditions.map(({ key, label, options }) => ({
        key,
        name: label,
        options: options.map((option) => ({ key: option.key, name: option.label })),
      })),
      // Array.prototype.sort is stable, so unlisted fields keep their order.
    ].sort((a, b) => at(a.key) - at(b.key)),
  }
}
