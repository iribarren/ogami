/** The dice a table is printed for, smallest first. */
const DICE = [4, 6, 8, 10, 12, 20, 100] as const

export interface TableRanges {
  /** Sides of the smallest standard die that covers the table. */
  die: number
  /** The total when it is not a die size: a roll above it is rolled again. `null` when it is. */
  rollAgainAbove: number | null
  /** The numbers each entry answers to, in table order: "4" or "1-3". */
  ranges: string[]
}

/**
 * Numbers a table for rolling on paper. An entry takes as many numbers as its weight (an
 * unweighted table gives each entry weight 1), in order. A table over 100 has no die, and a
 * malformed weight has no meaning: both throw.
 */
export function tableRanges(weights: readonly number[]): TableRanges {
  if (weights.length === 0) {
    throw new Error('A table with no entries has nothing to roll.')
  }
  if (!weights.every((weight) => Number.isInteger(weight) && weight > 0)) {
    throw new Error(`Table weights must be whole numbers above 0, got ${weights.join(', ')}.`)
  }
  const total = weights.reduce((sum, weight) => sum + weight, 0)
  const die = DICE.find((sides) => sides >= total)
  if (die === undefined) {
    throw new Error(`A table that totals ${String(total)} is more than a d100 can number.`)
  }
  let next = 1
  const ranges = weights.map((weight) => {
    const first = next
    next += weight
    return weight === 1 ? String(first) : `${String(first)}-${String(next - 1)}`
  })
  return { die, rollAgainAbove: total === die ? null : total, ranges }
}
