import { useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'

import type { Campaign } from '../campaigns/useCampaigns'
import { eventOf } from './randomEvents'
import { JournalEntryView } from './JournalEntryView'
import { useRecordNote, useRecordOracleTableResult, type JournalEntry } from './useJournal'

/** The API's limit on a note (`NoteContent::MAX_LENGTH`), after trimming. */
const MAX_NOTE_LENGTH = 10_000

/**
 * The random event a fate question answered with a double. Until it is rolled, "Roll <table>"
 * rolls the table the oracle declares through the oracle table request, naming the question; it
 * is only offered in the current scene, where an entry can be recorded. Once an oracle table
 * entry names the question the button is gone, whatever remounts or reloads, and that entry is
 * shown right here under its question. While it is the last entry of the journal in the current
 * scene, a one-line prompt records what it means as a plain note.
 */
export function RandomEventOffer({
  campaignId,
  entry,
  entries,
  tables,
  tags,
  sheet,
  current,
}: {
  campaignId: string
  entry: JournalEntry
  /** The whole journal, which tells whether the event was rolled and whether it is the last entry. */
  entries: readonly JournalEntry[]
  /** The oracle tables of the campaign's pinned release, which name the table to roll. */
  tables: Campaign['oracleTables']
  /** The release Tags and sheet fields, which name what the event entry stores. */
  tags: Campaign['tags']
  sheet?: Campaign['pinnedRelease']['sheet']
  /** Whether the question is in the current scene, the only one an entry can be recorded in. */
  current: boolean
}) {
  const roll = useRecordOracleTableResult(campaignId)
  if (entry.content.kind !== 'likelihood' || entry.content.randomEvent === null) {
    return null
  }
  const tableKey = entry.content.randomEvent
  const table = tables.find(({ key }) => key === tableKey)
  const event = eventOf(entries, entry)

  if (event !== undefined) {
    return (
      <div className="space-y-2">
        <JournalEntryView entry={event} tags={tags} sheet={sheet} />
        {current && entries.at(-1)?.id === event.id && <MeaningPrompt campaignId={campaignId} />}
      </div>
    )
  }

  if (!current) {
    return null
  }

  return (
    <div className="space-y-1">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={roll.isPending}
        onClick={() => {
          roll.mutate({ oracleKey: tableKey, forEntry: entry.id })
        }}
      >
        Roll {table?.name ?? tableKey}
      </Button>
      {roll.error && (
        <p role="alert" className="text-sm text-destructive">
          {roll.error.message}
        </p>
      )}
    </div>
  )
}

/** A one-line note under a fresh random event, for what it means. */
function MeaningPrompt({ campaignId }: { campaignId: string }) {
  const [text, setText] = useState('')
  const recordNote = useRecordNote(campaignId)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (text.trim() === '') {
      return
    }
    recordNote.mutate(
      { text },
      {
        onSuccess: () => {
          setText('')
        },
      },
    )
  }

  return (
    <form aria-label="What does it mean?" className="space-y-1" onSubmit={handleSubmit}>
      <div className="flex gap-2">
        <Input
          aria-label="What does it mean?"
          placeholder="What does it mean?"
          value={text}
          maxLength={MAX_NOTE_LENGTH}
          disabled={recordNote.isPending}
          onChange={(event) => {
            setText(event.target.value)
          }}
        />
        <Button
          type="submit"
          variant="outline"
          size="sm"
          disabled={recordNote.isPending || text.trim() === ''}
        >
          Write note
        </Button>
      </div>
      {recordNote.error && (
        <p role="alert" className="text-sm text-destructive">
          {recordNote.error.message}
        </p>
      )}
    </form>
  )
}
