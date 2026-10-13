import { InvalidFormula } from './errors'
import type { Formula } from './formula'

/**
 * Orders named formulas so that each comes after the ones it reads, and rejects cycles. Only `{key}`
 * references to other keys of the map count; among the keys that are ready, the first in the input goes first.
 *
 * @throws InvalidFormula (kind `cycle`) when formulas depend on each other in a cycle
 */
export function dependencyOrder(formulas: ReadonlyMap<string, Formula>): string[] {
  const pending = new Map<string, string[]>()
  for (const [key, formula] of formulas) {
    pending.set(
      key,
      formula.references.flatMap((reference) =>
        reference.kind === 'field' && formulas.has(reference.key) ? [reference.key] : [],
      ),
    )
  }

  const order: string[] = []
  while (pending.size > 0) {
    const ready = [...pending].find(([, dependencies]) => dependencies.length === 0)?.[0]
    if (ready === undefined) {
      throw new InvalidFormula(
        'cycle',
        `The formulas depend on each other: ${cycleIn(pending).join(' -> ')}.`,
      )
    }
    order.push(ready)
    pending.delete(ready)
    for (const [key, dependencies] of pending) {
      pending.set(
        key,
        dependencies.filter((dependency) => dependency !== ready),
      )
    }
  }
  return order
}

// Follows the first unresolved dependency from the first pending key until a key repeats. Every
// pending key has one when no key is ready, so the walk ends in a cycle.
function cycleIn(pending: ReadonlyMap<string, string[]>): string[] {
  const path: string[] = []
  let next = pending.keys().next().value
  while (next !== undefined && !path.includes(next)) {
    path.push(next)
    next = pending.get(next)?.[0]
  }
  return next === undefined ? path : [...path.slice(path.indexOf(next)), next]
}
