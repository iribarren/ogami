import { InvalidFormula } from './errors'
import type { Reference } from './reference'
import { tokenize, type Token } from './tokenizer'

export const maxInteger = 1_000_000
export const minArguments = 2
export const maxArguments = 10
export const maxDepth = 50

export type Operator = '+' | '-' | '*' | '/'

/** The expression tree of a formula. */
export type Node =
  | { type: 'constant'; value: number }
  | { type: 'reference'; reference: Reference }
  | { type: 'negation'; operand: Node }
  | { type: 'binary'; left: Node; operator: Operator; right: Node }
  | { type: 'call'; function: 'min' | 'max'; arguments: Node[] }

const operand = 'a number, a reference, "min", "max", "-" or "("'

/** Recursive-descent parser, the grammar of the PHP kernel: expression, term, unary, primary. */
export function parse(formula: string): Node {
  const tokens = tokenize(formula)
  let current = 0
  let depth = 0

  const peek = (): Token =>
    tokens[current] ?? { type: 'end', text: '', position: formula.length + 1 }
  const advance = (): Token => {
    const token = peek()
    if (token.type !== 'end') {
      current += 1
    }
    return token
  }
  const expect = (type: Token['type'], expected: string): Token => {
    const token = peek()
    if (token.type === type) {
      return advance()
    }
    throw new InvalidFormula(
      'syntax',
      token.type === 'end'
        ? `Unexpected end of the formula, expected ${expected}.`
        : `Unexpected "${token.text}" at position ${String(token.position)}, expected ${expected}.`,
    )
  }
  const operatorIn = (...operators: Operator[]): Operator | null => {
    const token = peek()
    if (token.type === 'operator' && operators.includes(token.text)) {
      advance()
      return token.text
    }
    return null
  }
  const descend = () => {
    depth += 1
    if (depth > maxDepth) {
      throw new InvalidFormula(
        'syntax',
        `The formula nests deeper than ${String(maxDepth)} levels.`,
      )
    }
  }
  // An expression one level deeper, inside parentheses or a call.
  const nested = (): Node => {
    descend()
    const node = expression()
    depth -= 1
    return node
  }

  function call(): Node {
    const name = advance()
    expect('open', '"("')
    const args = [nested()]
    while (peek().type === 'comma') {
      advance()
      if (args.length === maxArguments) {
        throw new InvalidFormula(
          'syntax',
          `${name.text} takes ${String(minArguments)} to ${String(maxArguments)} arguments.`,
        )
      }
      args.push(nested())
    }
    expect('close', '"," or ")"')
    if (args.length < minArguments) {
      throw new InvalidFormula(
        'syntax',
        `${name.text} takes ${String(minArguments)} to ${String(maxArguments)} arguments, ${String(args.length)} given.`,
      )
    }
    return { type: 'call', function: name.text as 'min' | 'max', arguments: args }
  }

  function primary(): Node {
    const token = peek()
    if (token.type === 'open') {
      advance()
      const inner = nested()
      expect('close', '")"')
      return inner
    }
    if (token.type === 'function') {
      return call()
    }
    if (token.type === 'reference') {
      advance()
      return { type: 'reference', reference: token.reference }
    }
    const integer = expect('integer', operand)
    const value = Number(integer.text)
    if (value > maxInteger) {
      throw new InvalidFormula(
        'syntax',
        `The number ${integer.text} is above ${String(maxInteger)}.`,
      )
    }
    return { type: 'constant', value }
  }

  function unary(): Node {
    if (operatorIn('-') === null) {
      return primary()
    }
    descend()
    const inner = unary()
    depth -= 1
    return { type: 'negation', operand: inner }
  }

  function term(): Node {
    let node = unary()
    for (let operator = operatorIn('*', '/'); operator !== null; operator = operatorIn('*', '/')) {
      node = { type: 'binary', left: node, operator, right: unary() }
    }
    return node
  }

  function expression(): Node {
    let node = term()
    for (let operator = operatorIn('+', '-'); operator !== null; operator = operatorIn('+', '-')) {
      node = { type: 'binary', left: node, operator, right: term() }
    }
    return node
  }

  const root = expression()
  expect('end', 'an operator or the end of the formula')
  return root
}
