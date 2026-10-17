/** A formula that does not parse (or formulas that depend on each other in a cycle). */
export class InvalidFormula extends Error {
  readonly kind: 'syntax' | 'cycle'
  /** The 1-based position in the trimmed text that the message names; none when it names none. */
  readonly position: number | undefined

  constructor(kind: 'syntax' | 'cycle', message: string, details: { position?: number } = {}) {
    super(message)
    this.name = 'InvalidFormula'
    this.kind = kind
    this.position = details.position
  }
}

/** The reasons a parsed formula gives no value; the codes are the conformance file's `error` values. */
export type EvaluationFailure = 'divisionByZero' | 'overflow' | 'unknownReference'

export class FormulaEvaluationFailed extends Error {
  readonly kind: EvaluationFailure

  constructor(kind: EvaluationFailure, message: string) {
    super(message)
    this.name = 'FormulaEvaluationFailed'
    this.kind = kind
  }
}
