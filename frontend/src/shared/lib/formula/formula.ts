import { FormulaEvaluationFailed, InvalidFormula } from './errors'
import { parse, type Node, type Operator } from './parser'
import { referenceToString, type Reference } from './reference'

export const maxLength = 200
export const maxMagnitude = 1_000_000_000

/** The value of a reference, or `undefined` when there is none (the formula then fails as `unknownReference`). */
export type Resolver = (reference: Reference) => number | undefined

const whitespace = /^[ \t\r\n]+|[ \t\r\n]+$/g

function checked(value: number): number {
  if (Math.abs(value) > maxMagnitude) {
    throw new FormulaEvaluationFailed(
      'overflow',
      `A result of the formula is outside -${String(maxMagnitude)} to ${String(maxMagnitude)}.`,
    )
  }
  // Adding 0 turns -0 into 0.
  return value + 0
}

/** Integer division rounding down, exact for operands within ±maxMagnitude. */
function floorDivide(left: number, right: number): number {
  if (right === 0) {
    throw new FormulaEvaluationFailed('divisionByZero', 'The formula divides by zero.')
  }
  const remainder = left % right
  const quotient = (left - remainder) / right
  return remainder !== 0 && left < 0 !== right < 0 ? quotient - 1 : quotient
}

function apply(operator: Operator, left: number, right: number): number {
  switch (operator) {
    case '+':
      return checked(left + right)
    case '-':
      return checked(left - right)
    case '*':
      return checked(left * right)
    case '/':
      return checked(floorDivide(left, right))
  }
}

function evaluate(node: Node, resolve: Resolver): number {
  switch (node.type) {
    case 'constant':
      return node.value
    case 'reference': {
      const value = resolve(node.reference)
      if (value === undefined) {
        throw new FormulaEvaluationFailed(
          'unknownReference',
          `The formula reads "${referenceToString(node.reference)}", which has no value.`,
        )
      }
      return checked(value)
    }
    case 'negation':
      return 0 - evaluate(node.operand, resolve)
    case 'binary': {
      // Left operand, right operand, then the operator: the first failure wins.
      const left = evaluate(node.left, resolve)
      return apply(node.operator, left, evaluate(node.right, resolve))
    }
    case 'call': {
      const values = node.arguments.map((argument) => evaluate(argument, resolve))
      return node.function === 'min' ? Math.min(...values) : Math.max(...values)
    }
  }
}

function collect(node: Node): Reference[] {
  switch (node.type) {
    case 'constant':
      return []
    case 'reference':
      return [node.reference]
    case 'negation':
      return collect(node.operand)
    case 'binary':
      return [...collect(node.left), ...collect(node.right)]
    case 'call':
      return node.arguments.flatMap(collect)
  }
}

/**
 * A parsed formula such as `({dex}-10)/2`, `max({str},{dex})` or `{tracker:chaos}*2+{hp.max}`: the
 * grammar, limits and error precedence of the PHP kernel (ADR 0023).
 */
export class Formula {
  readonly text: string
  /** What the formula reads, each reference once, in the order it first appears. */
  readonly references: Reference[]
  private readonly root: Node

  constructor(text: string, root: Node) {
    this.text = text
    this.root = root
    const unique = new Map<string, Reference>()
    for (const reference of collect(root)) {
      const spelling = referenceToString(reference)
      if (!unique.has(spelling)) {
        unique.set(spelling, reference)
      }
    }
    this.references = [...unique.values()]
  }

  /** @throws FormulaEvaluationFailed on division by zero, a result outside ±1,000,000,000 or a missing value */
  evaluate(resolve: Resolver): number {
    return evaluate(this.root, resolve)
  }

  toString(): string {
    return this.text
  }
}

/** @throws InvalidFormula when the text is not a formula */
export function parseFormula(formula: string): Formula {
  // Exactly the tokenizer's whitespace.
  const text = formula.replace(whitespace, '')
  if (text === '') {
    throw new InvalidFormula('syntax', 'The formula is empty.')
  }
  const length = new TextEncoder().encode(text).length
  if (length > maxLength) {
    throw new InvalidFormula(
      'syntax',
      `The formula is ${String(length)} characters long, the limit is ${String(maxLength)}.`,
    )
  }
  return new Formula(text, parse(text))
}
