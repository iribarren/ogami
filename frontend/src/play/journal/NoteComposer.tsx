import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { attachableIn, attachmentLabel } from './attachments'
import { useJournal, useRecordNote } from './useJournal'

/** The API's limit on a note (`NoteContent::MAX_LENGTH`), after trimming. */
const MAX_NOTE_LENGTH = 10_000

/**
 * Writes a note in the current scene, optionally attaching results of that scene; disabled while
 * the campaign has no current scene.
 */
export function NoteComposer({
  campaignId,
  disabled,
  current,
}: {
  campaignId: string
  disabled: boolean
  /** The scene play is in, which is the only one whose results a note can attach. */
  current: { session: number; scene: number } | null
}) {
  const [text, setText] = useState('')
  const [picked, setPicked] = useState<ReadonlySet<string>>(new Set())
  const [open, setOpen] = useState(false)
  const recordNote = useRecordNote(campaignId)
  const journal = useJournal(campaignId)
  const noteId = useId()
  const listId = useId()
  const valid = text.trim() !== ''
  const attachable = attachableIn(journal.data ?? [], current)
  // Only results of the current scene count: after a scene change the old picks drop out. They
  // go in journal order, not in the order they were clicked.
  const attachments = attachable.filter(({ id }) => picked.has(id)).map(({ id }) => id)

  function toggle(id: string) {
    const next = new Set(picked)
    if (!next.delete(id)) {
      next.add(id)
    }
    setPicked(next)
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    recordNote.mutate(attachments.length === 0 ? { text } : { text, attachments }, {
      onSuccess: () => {
        setText('')
        setPicked(new Set())
        setOpen(false)
      },
    })
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
      {attachable.length > 0 && (
        <div className="space-y-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled}
            aria-expanded={open}
            aria-controls={listId}
            onClick={() => {
              setOpen(!open)
            }}
          >
            Attach results{attachments.length > 0 && ` (${String(attachments.length)})`}
          </Button>
          {open && (
            <ul id={listId} aria-label="Results to attach" className="space-y-1">
              {/* Newest first, as the player just made them. */}
              {attachable.toReversed().map((entry) => (
                <li key={entry.id}>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="size-4 accent-primary"
                      checked={picked.has(entry.id)}
                      onChange={() => {
                        toggle(entry.id)
                      }}
                    />
                    {attachmentLabel(entry)}
                  </label>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
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
