import type { JournalEntry } from './useJournal'

/** The oracle table entry rolled for the random event of a fate question, if there is one. */
export function eventOf(entries: readonly JournalEntry[], question: JournalEntry) {
  return entries.find(
    (entry) => entry.content.kind === 'oracle-table' && entry.content.forEntry === question.id,
  )
}

/**
 * Whether the entry is the roll of a random event whose fate question is in the list: the
 * journal shows it under that question (RandomEventOffer), not at its place in time. An event
 * whose question is not in the list stays where it was recorded.
 */
export function isListedRandomEvent(entries: readonly JournalEntry[], entry: JournalEntry) {
  const { content } = entry
  return (
    content.kind === 'oracle-table' &&
    content.forEntry !== null &&
    entries.some(({ id }) => id === content.forEntry)
  )
}
