import { useQueryClient } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { nextVersion, usePublishGameSystem, type PublishRequest } from './useGameSystemReleases'
import {
  StudioError,
  gameSystemKey,
  useGameSystemValidation,
  type GameSystem,
} from './useGameSystems'

/** The API's limit on release notes, checked by the server too. */
const MAX_NOTES_LENGTH = 2000

/**
 * The server answers 409 both for "no changes" and for a lost version race, in the same shape,
 * so one message covers both.
 */
export const conflictMessage =
  'Nothing was published: the draft equals the latest release, or another release was published meanwhile. Reload and try again.'

/**
 * The "Publish" button and its dialog: the validation result, the version a publish creates and
 * optional release notes. A refusal is explained inside the dialog; success closes it and tells
 * the page which version was published.
 */
export function PublishDialog({
  gameSystem,
  onPublished,
}: {
  gameSystem: GameSystem
  onPublished: (version: number) => void
}) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Upload aria-hidden="true" />
          Publish
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Publish a release</DialogTitle>
          <DialogDescription>
            Publishing turns the saved draft into a new, immutable release that campaigns can play.
          </DialogDescription>
        </DialogHeader>
        <PublishForm
          gameSystem={gameSystem}
          onPublished={(version) => {
            setOpen(false)
            onPublished(version)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function PublishForm({
  gameSystem,
  onPublished,
}: {
  gameSystem: GameSystem
  onPublished: (version: number) => void
}) {
  const [notes, setNotes] = useState('')
  const validation = useGameSystemValidation(gameSystem.key)
  const publish = usePublishGameSystem(gameSystem.key)
  const queryClient = useQueryClient()
  const notesId = useId()
  const conflict = publish.error instanceof StudioError && publish.error.status === 409
  const canPublish = validation.data?.valid === true && !publish.isPending

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body: PublishRequest = notes.trim() === '' ? {} : { notes }
    publish.mutate(body, {
      onSuccess: (release) => {
        onPublished(release.version)
      },
    })
  }

  return (
    <form aria-label="Publish" className="space-y-4" onSubmit={handleSubmit}>
      <ValidationSummary validation={validation} version={nextVersion(gameSystem)} />
      <div className="space-y-2">
        <Label htmlFor={notesId}>Release notes (optional)</Label>
        <Textarea
          id={notesId}
          value={notes}
          maxLength={MAX_NOTES_LENGTH}
          onChange={(event) => {
            setNotes(event.target.value)
          }}
        />
        <p className="text-sm text-muted-foreground">
          {notes.length} / {MAX_NOTES_LENGTH.toLocaleString('en-GB')} characters
        </p>
      </div>
      <p className="text-sm text-muted-foreground">
        Only the saved draft is published: save your metadata edits first.
      </p>
      {publish.error && (
        <div role="alert" className="space-y-2 text-sm text-destructive">
          <p>{conflict ? conflictMessage : publish.error.message}</p>
          {conflict && (
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                publish.reset()
                void queryClient.invalidateQueries({ queryKey: gameSystemKey(gameSystem.key) })
              }}
            >
              Reload
            </Button>
          )}
        </div>
      )}
      <Button type="submit" disabled={!canPublish}>
        Publish version {nextVersion(gameSystem)}
      </Button>
    </form>
  )
}

function ValidationSummary({
  validation,
  version,
}: {
  validation: ReturnType<typeof useGameSystemValidation>
  version: number
}) {
  if (validation.isPending) {
    return <p className="text-muted-foreground">Validating the draft…</p>
  }
  if (validation.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {validation.error.message}
      </p>
    )
  }
  const { valid, error, warnings } = validation.data
  if (!valid) {
    return (
      <p role="alert" className="text-sm text-destructive">
        The draft is not valid yet, so it cannot be published. The first error:{' '}
        {error?.message ?? 'unknown'}
      </p>
    )
  }
  return (
    <div className="space-y-2">
      <p>This creates version {version}.</p>
      {warnings.length > 0 && (
        <>
          <h3 className="font-heading font-semibold">Warnings</h3>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {warnings.map((warning, index) => (
              // Warnings can repeat, so the position is part of the key.
              <li key={`${String(index)}:${warning}`}>{warning}</li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}
