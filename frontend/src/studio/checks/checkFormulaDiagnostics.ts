import {
  FormulaEvaluationFailed,
  InvalidFormula,
  parseFormula,
  referenceToString,
  type Formula,
  type Reference,
} from '@/shared/lib/formula'

import type { ReferenceOption } from '../sheet/FormulaInput'
import type { SheetFieldView } from '@/shared/sheet/types'
import { positionOf, referenceProblem, type FormulaDiagnostics } from '../sheet/formulaDiagnostics'
import { integer } from '@/shared/sheet/derivedValues'

import type { DifficultyDraft } from './checkDraft'
import type { GameSystemChecks } from './useGameSystemChecks'

/** Where a formula sits in a check: the modifier never reads `{check:…}`, a band bound may. */
export type CheckFormulaScope = 'modifier' | 'band'

export type Catalog = Pick<GameSystemChecks, 'fields' | 'trackers'> &
  Partial<Pick<GameSystemChecks, 'conditions'>> & {
    /** The value a number or conditions field reads when nobody typed a sample: the sandbox's start; 0 without one. */
    defaults?: Record<string, number>
  }

/** The difficulty a check declares, as far as the form says: its default, when that is a whole number. */
export interface DeclaredDifficulty {
  default: number | null
}

/** The difficulty the form declares, or null while the check has none; an unreadable default is null. */
export function declaredDifficulty(difficulty: DifficultyDraft | null): DeclaredDifficulty | null {
  return difficulty === null ? null : { default: integer(difficulty.default) ?? null }
}

const failures = {
  divisionByZero: 'division by zero',
  overflow: 'overflow',
  unknownReference: 'incomplete value',
}

/**
 * Why a check formula cannot read a `{check:…}` reference, or null when it can: only a band
 * bound reads `{check:difficulty}`, and only when its check declares a difficulty.
 */
function checkProblem(
  reference: Reference,
  scope: CheckFormulaScope,
  difficulty: DeclaredDifficulty | null,
): string | null {
  if (scope === 'modifier') {
    return 'the modifier cannot read a check reference, only band bounds can'
  }
  if (reference.key !== 'difficulty') {
    return `unknown check value "${reference.key}", only {check:difficulty} exists`
  }
  return difficulty === null ? 'this check declares no difficulty' : null
}

/**
 * What the Studio can tell about a formula of a check (its modifier or a band bound) as it is
 * typed, by the publisher's rules: a syntax error, then the first reference it may not read (an
 * unknown field or tracker, `.max` of a field that is not a track, `{check:…}` where it is not
 * allowed). There is no cycle check: a check formula is read by nothing. Otherwise a value over
 * sample values: number fields their default (the test roll's start, else 0), track fields 0, a track's max, a tracker's initial value and the
 * difficulty's default; a derived field has no sample, so a formula that reads one has no value.
 * The positions are the publisher's: 1-based, in the text without its outer whitespace.
 */
export function checkFormulaDiagnostics({
  formula: text,
  scope,
  catalog,
  difficulty,
}: {
  formula: string
  scope: CheckFormulaScope
  catalog: Catalog
  /** Null when the check declares no difficulty. */
  difficulty: DeclaredDifficulty | null
}): FormulaDiagnostics {
  let formula: Formula
  try {
    formula = parseFormula(text)
  } catch (error) {
    if (!(error instanceof InvalidFormula)) {
      throw error
    }
    return { error: { message: error.message, position: error.position }, result: null }
  }

  // A conditions field is not among the catalog's fields: the formula reads it as a count.
  const types = new Map<string, SheetFieldView['type']>([
    ...catalog.fields.map((field): [string, SheetFieldView['type']] => [field.key, field.type]),
    ...(catalog.conditions ?? []).map((field): [string, SheetFieldView['type']] => [
      field.key,
      'conditions',
    ]),
  ])
  const trackerKeys = catalog.trackers.map((tracker) => tracker.key)
  for (const reference of formula.references) {
    const reason =
      reference.kind === 'check'
        ? checkProblem(reference, scope, difficulty)
        : referenceProblem(reference, types, trackerKeys)
    if (reason !== null) {
      return {
        error: {
          message: `{${referenceToString(reference)}}: ${reason}`,
          position: positionOf(formula, reference),
        },
        result: null,
      }
    }
  }

  const read = (reference: Reference): number | undefined => {
    if (reference.kind === 'check') {
      return difficulty?.default ?? undefined
    }
    if (reference.kind === 'tracker') {
      return catalog.trackers.find(({ key }) => key === reference.key)?.initial ?? undefined
    }
    if (catalog.conditions?.some(({ key }) => key === reference.key) === true) {
      return reference.kind === 'field' ? (catalog.defaults?.[reference.key] ?? 0) : undefined
    }
    const field = catalog.fields.find(({ key }) => key === reference.key)
    if (field?.type === 'number') {
      return catalog.defaults?.[field.key] ?? 0
    }
    if (field?.type === 'track' && reference.kind === 'field') {
      return 0
    }
    return field?.type === 'track' ? (field.max ?? undefined) : undefined
  }
  try {
    return { error: null, result: { value: formula.evaluate(read) } }
  } catch (error) {
    if (!(error instanceof FormulaEvaluationFailed)) {
      throw error
    }
    return { error: null, result: { reason: failures[error.kind] } }
  }
}

/**
 * The references a check formula may read, for the menu of its input: the number, track and
 * derived fields, a track's max, the trackers and, in a band bound of a check with a difficulty,
 * `{check:difficulty}`.
 */
export function checkReferences(
  catalog: Catalog,
  scope: CheckFormulaScope,
  hasDifficulty: boolean,
): ReferenceOption[] {
  return [
    ...catalog.fields.flatMap((field) => [
      { reference: field.key, label: field.label },
      ...(field.type === 'track'
        ? [{ reference: `${field.key}.max`, label: `${field.label} max` }]
        : []),
    ]),
    ...(catalog.conditions ?? []).map((field) => ({ reference: field.key, label: field.label })),
    ...catalog.trackers.map((tracker) => ({
      reference: `tracker:${tracker.key}`,
      label: tracker.label,
    })),
    ...(scope === 'band' && hasDifficulty
      ? [{ reference: 'check:difficulty', label: 'Difficulty' }]
      : []),
  ]
}

/**
 * What a check formula reads, by label, once each in the order it first reads them ("Power",
 * "Heat"): the hint under the modifier. A reference the catalog does not know is named by its
 * key; a formula that does not parse reads nothing here (its error is shown instead).
 */
export function checkReads(text: string, catalog: Catalog): string[] {
  let formula: Formula
  try {
    formula = parseFormula(text)
  } catch (error) {
    if (!(error instanceof InvalidFormula)) {
      throw error
    }
    return []
  }
  const labels = new Map(
    checkReferences(catalog, 'band', true).map(({ reference, label }) => [reference, label]),
  )
  const names = formula.references.map((reference) => {
    const written = referenceToString(reference)
    return labels.get(written) ?? written
  })
  return [...new Set(names)]
}
