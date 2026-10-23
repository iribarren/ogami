import { useQueryClient } from '@tanstack/react-query'
import { Check, Info, Upload, X } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { useId, useState, type SubmitEvent } from 'react'

import { Alert, FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { nextVersion, usePublishGameSystem, type PublishRequest } from './useGameSystemReleases'
import {
  StudioError,
  gameSystemKey,
  useGameSystemValidation,
  type GameSystem,
} from './useGameSystems'
import {
  describeLocation,
  noNames,
  splitWarning,
  useNames,
  type LocatorNames,
} from './validationLocation'

/** The API's limit on release notes (`GameSystemRelease::MAX_NOTES_LENGTH`), in characters like the server counts them. */
const MAX_NOTES_LENGTH = 2000
const characterCount = (text: string) => Array.from(text).length
/** How many errors the summary lists before it links to the Validation tab for the rest. */
const SHOWN_ERRORS = 3

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
      <DialogContent
        onOpenAutoFocus={(event) => {
          // The first field, not the Close button (whose tooltip would pop up at once). Without
          // one, Radix focuses as usual, so focus never stays outside the modal.
          const notes =
            event.currentTarget instanceof HTMLElement
              ? event.currentTarget.querySelector('textarea')
              : null
          if (notes !== null) {
            event.preventDefault()
            notes.focus()
          }
        }}
      >
        <div className="flex items-start gap-3">
          <IconTile size="lg">
            <Upload />
          </IconTile>
          <DialogHeader className="min-w-0 flex-1">
            <DialogTitle>Publish a release</DialogTitle>
            <DialogDescription>
              Publishing turns the saved draft into a new, immutable release that campaigns can
              play.
            </DialogDescription>
          </DialogHeader>
          <DialogClose asChild>
            <IconButton label="Close">
              <X />
            </IconButton>
          </DialogClose>
        </div>
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
  const notesLength = characterCount(notes)
  const notesTooLong = notesLength > MAX_NOTES_LENGTH
  const canPublish = validation.data?.valid === true && !notesTooLong && !publish.isPending

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
      <div className="space-y-3 well">
        <p className="font-heading text-[22px] font-bold readout-glow">
          {gameSystem.latestReleaseVersion === null
            ? 'Draft'
            : `v${String(gameSystem.latestReleaseVersion)}`}{' '}
          <span aria-hidden="true">→</span> <span className="sr-only">to</span> v
          {nextVersion(gameSystem)}
        </p>
        <ValidationSummary
          validation={validation}
          version={nextVersion(gameSystem)}
          gameSystemKey={gameSystem.key}
        />
      </div>
      <div className="space-y-2">
        <div className="flex items-baseline justify-between gap-3">
          <Label htmlFor={notesId}>Release notes (optional)</Label>
          <span id={`${notesId}-count`} className="hint">
            {notesLength.toLocaleString('en-GB')} / {MAX_NOTES_LENGTH.toLocaleString('en-GB')}
          </span>
        </div>
        <Textarea
          id={notesId}
          value={notes}
          aria-invalid={notesTooLong}
          aria-describedby={`${notesId}-count`}
          onChange={(event) => {
            setNotes(event.target.value)
          }}
        />
        {notesTooLong && (
          <FieldError>
            The release notes are limited to {MAX_NOTES_LENGTH.toLocaleString('en-GB')} characters.
          </FieldError>
        )}
      </div>
      <p className="flex items-start gap-2 hint">
        <Info aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
        Only the saved draft is published: save your metadata edits first.
      </p>
      {publish.error && (
        <Alert
          role="alert"
          action={
            conflict ? (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  publish.reset()
                  void queryClient.invalidateQueries({ queryKey: gameSystemKey(gameSystem.key) })
                }}
              >
                Reload
              </Button>
            ) : undefined
          }
        >
          {conflict ? conflictMessage : publish.error.message}
        </Alert>
      )}
      <div className="-mx-4 -mb-4 action-bar">
        <DialogClose asChild>
          <Button type="button" variant="ghost">
            Cancel
          </Button>
        </DialogClose>
        <Button type="submit" disabled={!canPublish}>
          <Upload aria-hidden="true" />
          Publish version {nextVersion(gameSystem)}
        </Button>
      </div>
    </form>
  )
}

/**
 * The errors that block a publish: the first three, each with the place it is in, in words, then a
 * link to the Validation tab for the rest.
 */
export function PublishErrors({
  errors,
  gameSystemKey: key,
  names = noNames,
}: {
  errors: string[]
  gameSystemKey: string
  names?: LocatorNames
}) {
  const more = errors.length - SHOWN_ERRORS

  return (
    <>
      <ul className="list-disc space-y-1 pl-5">
        {errors.slice(0, SHOWN_ERRORS).map((message, index) => (
          <li key={`${String(index)}:${message}`}>
            <WarningText warning={message} names={names} />
          </li>
        ))}
      </ul>
      {more > 0 && (
        <p>
          and {String(more)} more:{' '}
          <Link
            to="/studio/game-systems/$key"
            params={{ key }}
            search={{ tab: 'validation' }}
            className="underline underline-offset-4"
          >
            see the Validation tab
          </Link>
        </p>
      )}
    </>
  )
}

/** A warning's (or error's) reason, with the place it is in, in words, under it. */
function WarningText({ warning, names }: { warning: string; names: LocatorNames }) {
  const { path, reason } = splitWarning(warning)
  if (path === undefined) {
    return reason
  }
  return (
    <>
      {reason}
      <span className="block text-xs text-muted-foreground">{describeLocation(path, names)}</span>
    </>
  )
}

function ValidationSummary({
  validation,
  version,
  gameSystemKey: key,
}: {
  validation: ReturnType<typeof useGameSystemValidation>
  version: number
  gameSystemKey: string
}) {
  const names = useNames(key)
  if (validation.isPending) {
    return <p className="text-muted-foreground">Validating the draft…</p>
  }
  if (validation.isError) {
    return <Alert role="alert">{validation.error.message}</Alert>
  }
  const { valid, errors, warnings } = validation.data
  if (!valid) {
    return (
      <Alert role="alert">
        <p>The draft is not valid yet, so it cannot be published.</p>
        <PublishErrors
          errors={errors.map(({ path, message }) => `${path}: ${message}`)}
          gameSystemKey={key}
          names={names}
        />
      </Alert>
    )
  }
  return (
    <div className="space-y-2">
      <div className="flex items-start gap-2">
        <Check aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-success" />
        <div>
          <p className="font-medium">The draft is valid.</p>
          <p>This creates version {version}.</p>
        </div>
      </div>
      {warnings.length > 0 && (
        <Alert variant="warning">
          <h3 className="font-heading font-semibold">Warnings</h3>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {warnings.map((warning, index) => (
              // Warnings can repeat, so the position is part of the key.
              <li key={`${String(index)}:${warning}`}>
                <WarningText warning={warning} names={names} />
              </li>
            ))}
          </ul>
        </Alert>
      )}
    </div>
  )
}
