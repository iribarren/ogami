import type { JournalEntry } from './useJournal'

type Check = Extract<JournalEntry['content'], { kind: 'check' }>

/** The special results of a roll (ADR 0044, 0049), as the player reads them. */
export const specialResultLabels: Record<NonNullable<Check['special']>, string> = {
  critical: 'Critical',
  messyCritical: 'Messy critical',
  bestialFailure: 'Bestial failure',
  match: 'Match',
}
