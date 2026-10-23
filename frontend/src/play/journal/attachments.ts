import type { JournalEntry } from './useJournal'

/** The API's limit on the results a note attaches (`NoteContent::MAX_ATTACHMENTS`). */
export const MAX_ATTACHMENTS = 20

/**
 * Whether a result can be attached to a note: a roll, a table or a Likelihood oracle, or a check.
 * A random event result is not: it already belongs to its question (`NoteContent` rules).
 */
export function isAttachable(entry: JournalEntry) {
  const { content } = entry
  switch (content.kind) {
    case 'roll':
    case 'likelihood':
    case 'check':
      return true
    case 'oracle-table':
      return content.forEntry === null
    default:
      return false
  }
}

/** The results of the current scene a note can attach, in journal order; none outside a scene. */
export function attachableIn(
  entries: readonly JournalEntry[],
  current: { session: number; scene: number } | null,
) {
  if (current === null) {
    return []
  }
  return entries.filter(
    (entry) =>
      entry.sessionNumber === current.session &&
      entry.sceneNumber === current.scene &&
      isAttachable(entry),
  )
}

/**
 * Whether a note of the list attaches the entry: the journal shows it under that note, not at
 * its place in time (the entry itself stays as it was recorded).
 */
export function isAttached(entries: readonly JournalEntry[], entry: JournalEntry) {
  return entries.some(
    ({ content }) => content.kind === 'note' && content.attachments.includes(entry.id),
  )
}

/**
 * The entries a note attaches, in the order the note lists them. An id the list does not hold is
 * left out.
 */
export function attachedTo(entries: readonly JournalEntry[], note: JournalEntry) {
  if (note.content.kind !== 'note') {
    return []
  }
  return note.content.attachments.flatMap((id) => entries.find((entry) => entry.id === id) ?? [])
}

/** A short line naming a result in the picker: what was rolled and what came out. */
export function attachmentLabel(entry: JournalEntry) {
  const { content } = entry
  switch (content.kind) {
    case 'roll':
      return `Roll ${content.expression} = ${String(content.total)}`
    case 'oracle-table': {
      const result = content.steps.at(-1)?.text ?? ''
      return result === '' ? content.oracleName : `${content.oracleName}: ${result}`
    }
    case 'likelihood': {
      const answers = {
        exceptional_yes: 'Exceptional yes',
        yes: 'Yes',
        no: 'No',
        exceptional_no: 'Exceptional no',
      }
      return `${content.question ?? content.oracleName} → ${answers[content.answer]}`
    }
    case 'check':
      return `${content.characterName} · ${content.checkLabel}: ${content.bandLabel} (${String(content.total)})`
    default:
      return content.kind
  }
}
