import { InvalidFormula } from './errors'
import { referenceFromInner, type Reference } from './reference'

export type Token =
  | { type: 'integer'; text: string; position: number }
  | { type: 'operator'; text: '+' | '-' | '*' | '/'; position: number }
  | { type: 'open' | 'close' | 'comma' | 'end'; text: string; position: number }
  | { type: 'function'; text: 'min' | 'max'; position: number }
  | { type: 'reference'; text: string; position: number; reference: Reference }

// One alternative per token; whitespace is exactly space, tab, CR and LF.
const pattern =
  /(?<space>[ \t\r\n]+)|(?<integer>[0-9]+)|(?<name>[A-Za-z][A-Za-z0-9_]*)|(?<reference>\{[^{}]*\})|(?<operator>[-+*/])|(?<open>\()|(?<close>\))|(?<comma>,)/y

/** Splits a formula into tokens, skipping whitespace; the list always ends with an `end` token. */
export function tokenize(formula: string): Token[] {
  const tokens: Token[] = []
  let offset = 0
  while (offset < formula.length) {
    pattern.lastIndex = offset
    const match = pattern.exec(formula)
    const groups = match?.groups
    if (match === null || groups === undefined) {
      if (formula[offset] === '{') {
        throw new InvalidFormula('syntax', `Unclosed reference at position ${String(offset + 1)}.`)
      }
      throw new InvalidFormula(
        'syntax',
        `Unexpected character "${formula[offset] ?? ''}" at position ${String(offset + 1)}.`,
      )
    }
    const position = offset + 1
    offset += match[0].length
    const { integer, name, reference, operator, open, close, comma } = groups
    if (integer !== undefined) {
      tokens.push({ type: 'integer', text: integer, position })
    } else if (name !== undefined) {
      if (name !== 'min' && name !== 'max') {
        throw new InvalidFormula(
          'syntax',
          `Unknown name "${name}" at position ${String(position)}.`,
        )
      }
      tokens.push({ type: 'function', text: name, position })
    } else if (reference !== undefined) {
      const read = referenceFromInner(reference.slice(1, -1))
      if (read === null) {
        throw new InvalidFormula(
          'syntax',
          `Invalid reference ${reference} at position ${String(position)}.`,
        )
      }
      tokens.push({ type: 'reference', text: reference, position, reference: read })
    } else if (operator !== undefined) {
      tokens.push({ type: 'operator', text: operator as '+' | '-' | '*' | '/', position })
    } else if (open !== undefined) {
      tokens.push({ type: 'open', text: open, position })
    } else if (close !== undefined) {
      tokens.push({ type: 'close', text: close, position })
    } else if (comma !== undefined) {
      tokens.push({ type: 'comma', text: comma, position })
    }
  }
  tokens.push({ type: 'end', text: '', position: formula.length + 1 })
  return tokens
}
