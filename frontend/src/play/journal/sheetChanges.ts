import type { JournalEntry, SheetChangeContent } from './useJournal'

type SheetChange = SheetChangeContent['changes'][number]

/**
 * Whether a sheet change changed nothing and rolled no dice. `hp 7 → 7 (1d8: 4)` is not one: its dice
 * tell why nothing changed. Entries recorded before the Flow stopped recording these still hold them.
 */
export function isNoOpChange(change: SheetChange) {
  return change.before === change.after && change.dice === null
}

/** The changes of a sheet change entry worth showing. */
export function visibleChanges(changes: readonly SheetChange[]) {
  return changes.filter((change) => !isNoOpChange(change))
}

/** Whether a sheet change entry holds nothing but no-ops, so it shows nothing at all. */
export function isEmptySheetChange(entry: JournalEntry) {
  return entry.content.kind === 'sheet-change' && visibleChanges(entry.content.changes).length === 0
}
