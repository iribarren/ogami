import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import {
  dependencyOrder,
  FormulaEvaluationFailed,
  InvalidFormula,
  parseFormula,
  referenceToString,
} from './index'

interface EvaluateVector {
  formula: string
  values?: Record<string, number>
  result?: number
  error?: string
}

interface ReferencesVector {
  formula: string
  references: string[]
}

// The file PHP runs too (backend/contracts/formula/conformance.json). A missing file fails the
// suite: the node container mounts it, see compose.yaml.
const conformance = JSON.parse(
  readFileSync(resolve(process.cwd(), '../backend/contracts/formula/conformance.json'), 'utf8'),
) as { evaluate: EvaluateVector[]; references: ReferencesVector[] }

/** The value or the error code of a formula, parse errors included. */
function outcome(formula: string, values: Record<string, number> = {}) {
  try {
    return {
      result: parseFormula(formula).evaluate((reference) => values[referenceToString(reference)]),
    }
  } catch (error) {
    if (error instanceof InvalidFormula) {
      return { error: error.kind }
    }
    if (error instanceof FormulaEvaluationFailed) {
      return { error: error.kind }
    }
    throw error
  }
}

describe('the formula conformance file', () => {
  it('holds vectors', () => {
    expect(conformance.evaluate.length).toBeGreaterThan(0)
    expect(conformance.references.length).toBeGreaterThan(0)
  })

  it.each(conformance.evaluate)('evaluates $formula', (vector) => {
    const expected =
      vector.error === undefined ? { result: vector.result } : { error: vector.error }
    expect(outcome(vector.formula, vector.values)).toEqual(expected)
  })

  it.each(conformance.references)('reads $references from $formula', (vector) => {
    expect(parseFormula(vector.formula).references.map(referenceToString)).toEqual(
      vector.references,
    )
  })
})

describe('dependencyOrder', () => {
  const formulas = (entries: Record<string, string>) =>
    new Map(Object.entries(entries).map(([key, text]) => [key, parseFormula(text)]))

  it('puts a formula after the ones it reads, otherwise in input order', () => {
    expect(
      dependencyOrder(formulas({ c: '{b} + 1', a: '{str}', b: '{a} * 2', d: '{hp.max}' })),
    ).toEqual(['a', 'b', 'c', 'd'])
  })

  it('ignores references to other things than the map', () => {
    expect(dependencyOrder(formulas({ a: '{a.max} + {tracker:a} + {check:a} + {str}' }))).toEqual([
      'a',
    ])
  })

  it('refuses a cycle, naming it', () => {
    const error = (() => {
      try {
        dependencyOrder(formulas({ a: '{b}', b: '{c}', c: '{b}', d: '1' }))
      } catch (caught) {
        return caught
      }
    })()
    expect(error).toBeInstanceOf(InvalidFormula)
    expect((error as InvalidFormula).kind).toBe('cycle')
    expect((error as InvalidFormula).message).toContain('b -> c -> b')
  })

  it('refuses a formula that reads itself', () => {
    expect(() => dependencyOrder(formulas({ a: '{a} + 1' }))).toThrow(InvalidFormula)
  })
})

describe('InvalidFormula positions', () => {
  const failure = (text: string) => {
    try {
      parseFormula(text)
    } catch (caught) {
      return caught as InvalidFormula
    }
    throw new Error(`"${text}" should not parse.`)
  }

  // 1-based, in the trimmed text, as the PHP kernel's messages say.
  it.each([
    ['1 + $', 5],
    ['{unclosed', 1],
    ['1 + {Bad Key}', 5],
    ['1 + foo', 5],
    ['(1 + 2))', 8],
    ['1 2', 3],
    ['max(1; 2)', 6],
  ])('puts the position of "%s" at %i', (text, position) => {
    const error = failure(text)

    expect(error.kind).toBe('syntax')
    expect(error.position).toBe(position)
    expect(error.message).toContain(`position ${String(position)}`)
  })

  it.each(['1 +', '', '{a} * (', 'max(1)'])('has no position for "%s"', (text) => {
    expect(failure(text).position).toBeUndefined()
  })
})
