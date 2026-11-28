import type { JournalEntry } from '../journal/useJournal'

type Groups = Extract<JournalEntry['content'], { kind: 'roll' }>['groups']

/** The dice a roll showed, as the server returned them. */
export interface LatestRoll {
  /** The journal entry's id: a new roll is a new id, which is what makes the dice tumble. */
  id: string
  /** What was rolled: the expression of a free roll, "label: expression" for a check. */
  label: string
  total: number
  groups: Groups
}

type Check = Extract<JournalEntry['content'], { kind: 'check' }>

/** The dice of a check: its dice groups, or the dice of its pool when it rolls a pool of one die size. */
function checkGroups({ groups, pool, expression }: Check): Groups {
  if (groups.length > 0 || pool === null) {
    return groups
  }
  return [
    {
      notation: expression,
      sides: pool.sides,
      dice: pool.dice.map(({ face }) => ({ value: face, kept: true })),
      subtotal: pool.successes,
    },
  ]
}

/**
 * The latest roll of the journal, a free roll or the check roll of a step, in recorded order. A
 * pure derivation: the table stores nothing about it. A check recorded without dice is no roll to
 * show, so it is skipped.
 */
export function latestRoll(entries: readonly JournalEntry[]): LatestRoll | null {
  for (const entry of [...entries].reverse()) {
    const { content } = entry
    if (content.kind === 'roll' && content.groups.length > 0) {
      return { id: entry.id, label: content.expression, total: content.total, groups: content.groups }
    }
    if (content.kind === 'check') {
      const groups = checkGroups(content)
      if (groups.length > 0) {
        return {
          id: entry.id,
          label: `${content.checkLabel}: ${content.expression}`,
          total: content.total,
          groups,
        }
      }
    }
  }
  return null
}
