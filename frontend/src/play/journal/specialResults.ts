import type { JournalEntry } from './useJournal'

type Check = Extract<JournalEntry['content'], { kind: 'check' }>

/** The special results of a pool roll (ADR 0044), as the player reads them. */
export const specialResultLabels: Record<NonNullable<Check['special']>, string> = {
  critical: 'Critical',
  messyCritical: 'Messy critical',
  bestialFailure: 'Bestial failure',
}
