import {
  InvalidFormula,
  parseFormula,
  referenceToString,
  tokenize,
  type Formula,
  type Reference,
} from '@/shared/lib/formula'
import { computeDerived, type DerivedResult } from '@/shared/sheet/derivedValues'
import type { Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, TrackerReading } from '@/shared/sheet/types'

/** What is wrong with a formula; `position` is 1-based in the trimmed text, when one is known. */
export interface FormulaError {
  message: string
  position?: number
}

export interface FormulaDiagnostics {
  error: FormulaError | null
  /** The value over the sample values, or why there is none; null while the formula is refused. */
  result: DerivedResult | null
}

/** The sheet field types a derived field's formula may read as `{key}`, as the publisher (a conditions field reads as the count marked). */
const readable = ['number', 'track', 'conditions', 'derived']

/** Why a derived field cannot read `reference`, or null when it can: the publisher's rules. */
export function referenceProblem(
  reference: Reference,
  types: Map<string, SheetFieldView['type']>,
  trackerKeys: string[],
): string | null {
  if (reference.kind === 'check') {
    return 'a check reference is read only by the bands of a check'
  }
  if (reference.kind === 'tracker') {
    return trackerKeys.includes(reference.key) ? null : `unknown tracker "${reference.key}"`
  }
  const type = types.get(reference.key)
  if (type === undefined) {
    return `unknown field "${reference.key}"`
  }
  if (reference.kind === 'trackMax') {
    return type === 'track'
      ? null
      : `field "${reference.key}" is a ${type} field, only a track field has a max`
  }
  return readable.includes(type)
    ? null
    : `field "${reference.key}" is a ${type} field, a formula reads number, track, conditions and derived fields only`
}

/** Where the formula first writes `reference`, 1-based in its trimmed text. */
export function positionOf(formula: Formula, reference: Reference): number | undefined {
  const spelling = `{${referenceToString(reference)}}`
  return tokenize(formula.text).find((token) => token.text === spelling)?.position
}

/** The keys around a cycle from `key` back to `key` (`a -> b -> a`), or null when it is on none. */
function cycleThrough(key: string, formulas: Map<string, Formula>): string[] | null {
  const walk = (at: string, path: string[], visited: Set<string>): string[] | null => {
    for (const reference of formulas.get(at)?.references ?? []) {
      if (reference.kind !== 'field') {
        continue
      }
      if (reference.key === key) {
        return [...path, key]
      }
      if (formulas.has(reference.key) && !visited.has(reference.key)) {
        visited.add(reference.key)
        const found = walk(reference.key, [...path, reference.key], visited)
        if (found !== null) {
          return found
        }
      }
    }
    return null
  }
  return walk(key, [key], new Set())
}

/**
 * What the Studio can tell about the formula of the derived field `key` as it is typed, by the
 * publisher's rules: a syntax error, the first reference the field may not read (unknown field,
 * a field that is not a number, track or derived, an unknown tracker, a check, `.max` of a field
 * that is not a track), then a cycle through this field. The typed formula replaces the stored one.
 * Otherwise the value over the sample `drafts` and Trackers, computed as `computeDerived`. Other
 * fields' own errors are theirs to report: a cycle that does not run through this field is not
 * reported here, but makes every derived value read as invalid, as in `computeDerived`. Positions
 * are those of the publisher's messages: 1-based, in the text without its outer whitespace.
 */
export function formulaDiagnostics({
  key,
  formula: text,
  fields,
  drafts,
  trackerKeys,
  trackers,
}: {
  key: string
  formula: string
  fields: SheetFieldView[]
  drafts: Record<string, Draft>
  /** Every Tracker of the sheet, whether or not it has a sample value. */
  trackerKeys: string[]
  trackers: TrackerReading[]
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

  const types = new Map(fields.map((field) => [field.key, field.type]))
  types.set(key, 'derived')
  for (const reference of formula.references) {
    const reason = referenceProblem(reference, types, trackerKeys)
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

  const formulas = new Map<string, Formula>([[key, formula]])
  for (const field of fields) {
    if (field.type === 'derived' && field.key !== key) {
      try {
        formulas.set(field.key, parseFormula(field.formula ?? ''))
      } catch {
        // Its own error; it takes no part in a cycle.
      }
    }
  }
  const cycle = cycleThrough(key, formulas)
  if (cycle !== null) {
    const read = formula.references.find(
      (reference) => reference.kind === 'field' && reference.key === cycle[1],
    )
    return {
      error: {
        message: `Derived values depend on each other in a cycle: ${cycle.join(' -> ')}.`,
        position: read === undefined ? undefined : positionOf(formula, read),
      },
      result: null,
    }
  }

  const replaced: SheetFieldView[] = fields.some((field) => field.key === key)
    ? fields.map((field) =>
        field.key === key ? { ...field, type: 'derived', formula: text } : field,
      )
    : [...fields, derivedField(key, text)]
  return { error: null, result: computeDerived(replaced, drafts, trackers)[key] ?? null }
}

function derivedField(key: string, formula: string): SheetFieldView {
  return {
    key,
    label: key,
    hint: null,
    group: null,
    type: 'derived',
    min: null,
    max: null,
    default: null,
    multiline: null,
    maxLength: null,
    itemLabel: null,
    maxItems: null,
    formula,
    span: 1,
  }
}
