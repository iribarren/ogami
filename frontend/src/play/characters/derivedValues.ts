import {
  dependencyOrder,
  FormulaEvaluationFailed,
  parseFormula,
  type Formula,
  type Reference,
} from '@/shared/lib/formula'

import type { Tracker } from '../trackers/useTrackers'
import type { Draft } from './sheetValues'
import type { SheetField } from './useCharacters'

/** What a derived field shows: its value, or the short reason it has none. */
export type DerivedResult = { value: number } | { reason: string }

const failures = {
  divisionByZero: 'division by zero',
  overflow: 'overflow',
  unknownReference: 'incomplete value',
}

/** An integer as typed, or undefined while it is empty, fractional or not a number. */
function integer(text: string): number | undefined {
  const trimmed = text.trim()
  return /^-?\d+$/.test(trimmed) && Number.isSafeInteger(Number(trimmed))
    ? Number(trimmed)
    : undefined
}

/**
 * The derived values of the sheet as the form holds it, computed as the player types: the same
 * formulas and reference semantics as the server (`DerivedValues`), over the drafts of the number
 * and track fields and the Campaign's Tracker values. A value that cannot be computed, or depends
 * on one that cannot, is a reason instead; nothing here throws.
 */
export function computeDerived(
  sheet: SheetField[],
  drafts: Record<string, Draft>,
  trackers: Tracker[],
): Record<string, DerivedResult> {
  const fields = new Map(sheet.map((field) => [field.key, field]))
  const results: Record<string, DerivedResult> = {}
  const formulas = new Map<string, Formula>()
  for (const field of sheet) {
    if (field.type === 'derived') {
      try {
        formulas.set(field.key, parseFormula(field.formula ?? ''))
      } catch {
        results[field.key] = { reason: 'invalid formula' }
      }
    }
  }

  let order: string[]
  try {
    order = dependencyOrder(formulas)
  } catch {
    // A cycle: published content never has one, so none of the formulas is trusted.
    return {
      ...results,
      ...Object.fromEntries(
        [...formulas.keys()].map((key) => [key, { reason: 'invalid formula' }]),
      ),
    }
  }

  const read = (reference: Reference): number | undefined => {
    if (reference.kind === 'check') {
      // Only a check's bands read it; in a derived value it is invalid and has no value.
      return undefined
    }
    if (reference.kind === 'tracker') {
      return trackers.find(({ key }) => key === reference.key)?.value
    }
    const field = fields.get(reference.key)
    const draft = drafts[reference.key]
    if (field?.type === 'derived' && reference.kind === 'field') {
      const result = results[reference.key]
      return result !== undefined && 'value' in result ? result.value : undefined
    }
    if (field?.type === 'number' && reference.kind === 'field' && typeof draft === 'string') {
      return integer(draft)
    }
    if (field?.type === 'track' && typeof draft === 'object' && !Array.isArray(draft)) {
      return integer(reference.kind === 'trackMax' ? draft.max : draft.current)
    }
    return undefined
  }

  for (const key of order) {
    const formula = formulas.get(key)
    if (formula === undefined) {
      continue
    }
    try {
      results[key] = { value: formula.evaluate(read) }
    } catch (error) {
      if (!(error instanceof FormulaEvaluationFailed)) {
        throw error
      }
      const failed = formula.references.find(
        (reference) =>
          reference.kind === 'field' &&
          fields.get(reference.key)?.type === 'derived' &&
          'reason' in (results[reference.key] ?? {}),
      )
      // Only a reference that could not be read is blamed on its dependency; a failure of the
      // formula's own arithmetic keeps its own reason.
      results[key] = {
        reason:
          failed !== undefined && error.kind === 'unknownReference'
            ? `depends on ${failed.key}`
            : failures[error.kind],
      }
    }
  }
  return results
}
