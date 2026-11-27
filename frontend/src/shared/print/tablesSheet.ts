import type { Release, ReleaseTable, TableEntry } from './release'
import { tableRanges } from './tableRanges'
import { entryEffectText, UnwordableEffect } from './wording'

export interface TableRow {
  /** The numbers the entry answers to: "4" or "1-3". */
  range: string
  text: string
  /** What the entry does, in words; effects that have no wording are left out. */
  effects: string[]
}

export interface OracleTableSheet {
  key: string
  name: string
  /** Sides of the die the table is rolled on. */
  die: number
  /** The total when it is not a die size: a roll above it is rolled again. */
  rollAgainAbove: number | null
  rows: TableRow[]
}

const rangeText = (min: number, max: number) =>
  min === max ? String(min) : `${String(min)}-${String(max)}`

/**
 * A ranged table numbers a die with no holes: it starts at 1, lists its entries in ascending order
 * and each entry starts right after the one before it ends. Anything else prints a table that
 * cannot be rolled, so it throws.
 */
function checkRanges(entries: readonly TableEntry[]) {
  let expected = 1
  entries.forEach((entry, index) => {
    const min = entry.min ?? 0
    const max = entry.max ?? 0
    if (index === 0 && min !== 1) {
      throw new Error(`the first entry starts at ${String(min)}: the first number is 1.`)
    }
    const previous = entries[index - 1]
    if (index > 0 && min !== expected) {
      const pair = `entries ${rangeText(previous?.min ?? 0, previous?.max ?? 0)} and ${rangeText(min, max)}`
      throw new Error(
        min > expected
          ? `${pair} leave a gap: the next number is ${String(expected)}.`
          : min <= (previous?.min ?? 0)
            ? `${pair} are not in ascending order.`
            : `${pair} overlap: the next number is ${String(expected)}.`,
      )
    }
    expected = max + 1
  })
}

/**
 * The numbers of each entry, and the die. A table is either ranged (every entry has its `min` and
 * `max`: the die is the one that covers the highest number) or weighted (the numbers follow from
 * the weights, an unweighted entry counting 1); a mix has no numbering and is refused.
 */
function numbering(table: ReleaseTable): {
  die: number
  rollAgainAbove: number | null
  ranges: string[]
} {
  if (table.entries.length === 0) {
    throw new Error('it has no entries, so there is nothing to roll.')
  }
  const ranged = (entry: TableEntry) => entry.min !== undefined && entry.max !== undefined
  if (table.entries.every(ranged)) {
    checkRanges(table.entries)
    const highest = Math.max(...table.entries.map((entry) => entry.max ?? 0))
    const { die, rollAgainAbove } = tableRanges([highest])
    return {
      die,
      rollAgainAbove,
      ranges: table.entries.map((entry) => rangeText(entry.min ?? 0, entry.max ?? 0)),
    }
  }
  if (table.entries.some(ranged)) {
    throw new Error('it mixes entries with ranges and entries without.')
  }
  return tableRanges(table.entries.map((entry) => entry.weight ?? 1))
}

function tableSheet(release: Release, key: string): OracleTableSheet {
  const table = release.tables.find((candidate) => candidate.key === key)
  if (table === undefined) {
    throw new Error(`The flow's oracle "${key}" is neither a table nor a likelihood oracle.`)
  }
  try {
    const { die, rollAgainAbove, ranges } = numbering(table)
    return {
      key,
      name: table.name,
      die,
      rollAgainAbove,
      rows: table.entries.map((entry, index) => ({
        range: ranges[index] ?? '',
        text: entry.text,
        effects: entry.effects.flatMap((effect) => {
          try {
            return [entryEffectText(release, effect, key, true)]
          } catch (error) {
            // Only an `other` effect is left out; a wrong Tracker or Scene Type is a problem.
            if (error instanceof UnwordableEffect && effect.kind === 'other') {
              return []
            }
            throw error
          }
        }),
      })),
    }
  } catch (error) {
    throw new Error(
      `The table "${key}" cannot be printed: ${error instanceof Error ? error.message : 'unknown error'}`,
      { cause: error },
    )
  }
}

/**
 * The oracle tables of a flow, in flow order, as a roll sheet: the flow's likelihood oracles are
 * not tables and are skipped. A flow without tables has nothing to print (an empty list). Throws,
 * naming the table, for one the release lacks, one over a d100 or with no entries, and an entry
 * that names a Tracker or Scene Type the release does not have.
 */
export function buildTablesSheet(release: Release, flowKey: string): OracleTableSheet[] {
  const flow = release.flows.find((candidate) => candidate.key === flowKey)
  if (flow === undefined) {
    throw new Error(`The release has no flow "${flowKey}".`)
  }
  return flow.oracles
    .filter((key) => !release.likelihoods.includes(key))
    .map((key) => tableSheet(release, key))
}
