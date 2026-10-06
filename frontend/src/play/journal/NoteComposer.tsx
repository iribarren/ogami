import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { useRecordNote } from './useJournal'

/** The API's limit on a note (`NoteContent::MAX_LENGTH`), after trimming. */
const MAX_NOTE_LENGTH = 10_000

/** Writes a note in the current scene; disabled while the campaign has no current scene. */
export function NoteComposer({ campaignId, disabled }: { campaignId: string; disabled: boolean }) {
  const [text, setText] = useState('')
  const recordNote = useRecordNote(campaignId)
  const noteId = useId()
  const valid = text.trim() !== ''

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
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
    <form aria-label="Write a note" className="space-y-2" onSubmit={handleSubmit}>
      <Label htmlFor={noteId}>New note</Label>
      <Textarea
        id={noteId}
        value={text}
        maxLength={MAX_NOTE_LENGTH}
        rows={3}
        placeholder="What happens next?"
        disabled={disabled}
        onChange={(event) => {
          setText(event.target.value)
        }}
      />
      {recordNote.error && (
        <p role="alert" className="text-sm text-destructive">
          {recordNote.error.message}
        </p>
      )}
      <Button type="submit" disabled={disabled || !valid || recordNote.isPending}>
        Write note
      </Button>
    </form>
  )
}
