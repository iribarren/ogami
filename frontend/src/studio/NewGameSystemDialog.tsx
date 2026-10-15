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
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'
import { Textarea } from '@/shared/ui/textarea'

import { useCreateGameSystem, type CreateGameSystemRequest } from './useGameSystems'

type StartingPoint = 'blank' | 'copy'

/** The API's limit on a GameSystem name (the release contract's), checked by the server too. */
const MAX_NAME_LENGTH = 100

/**
 * The "New GameSystem" button and its dialog: a blank draft, or a copy of a published release
 * (any key, the latest version unless one is given). Creating opens the new GameSystem; a
 * refusal shows the API's message inside the dialog.
 */
export function NewGameSystemDialog() {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>New GameSystem</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New GameSystem</DialogTitle>
          <DialogDescription>
            Start from a blank draft, or copy a published release under a new key.
          </DialogDescription>
        </DialogHeader>
        <NewGameSystemForm />
      </DialogContent>
    </Dialog>
  )
}

/** A whole number of at least 1, or undefined when the text is not one. */
function parseVersion(text: string) {
  return /^[1-9]\d{0,8}$/.test(text) ? Number(text) : undefined
}

function NewGameSystemForm() {
  const [startingPoint, setStartingPoint] = useState<StartingPoint>('blank')
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [sourceKey, setSourceKey] = useState('')
  const [sourceVersion, setSourceVersion] = useState('')
  const create = useCreateGameSystem()
  const keyId = useId()
  const nameId = useId()
  const descriptionId = useId()
  const sourceKeyId = useId()
  const sourceVersionId = useId()
  const copying = startingPoint === 'copy'
  // An empty version means the latest release; anything else must be a valid version number.
  const versionValid = sourceVersion === '' || parseVersion(sourceVersion) !== undefined
  const valid =
    key.trim() !== '' &&
    name.trim() !== '' &&
    (!copying || (sourceKey.trim() !== '' && versionValid))

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body: CreateGameSystemRequest = { key, name }
    if (description.trim() !== '') {
      body.description = description
    }
    if (copying) {
      body.source = { key: sourceKey }
      if (sourceVersion !== '') {
        body.source.version = parseVersion(sourceVersion)
      }
    }
    create.mutate(body)
  }

  return (
    <>
      {/* Outside the form: Radix radios inside one render a hidden input that needs ResizeObserver. */}
      <SegmentedControl
        aria-label="Starting point"
        value={startingPoint}
        onValueChange={(value) => {
          setStartingPoint(value as StartingPoint)
        }}
      >
        <SegmentedControlItem value="blank">Blank draft</SegmentedControlItem>
        <SegmentedControlItem value="copy">Copy a release</SegmentedControlItem>
      </SegmentedControl>
      <form aria-label="New GameSystem" className="space-y-4" onSubmit={handleSubmit}>
        {copying && (
          <>
            <div className="space-y-2">
              <Label htmlFor={sourceKeyId}>Source GameSystem key</Label>
              <Input
                id={sourceKeyId}
                value={sourceKey}
                autoComplete="off"
                onChange={(event) => {
                  setSourceKey(event.target.value)
                }}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor={sourceVersionId}>Source version (empty for the latest)</Label>
              <Input
                id={sourceVersionId}
                inputMode="numeric"
                value={sourceVersion}
                autoComplete="off"
                aria-invalid={!versionValid}
                onChange={(event) => {
                  setSourceVersion(event.target.value)
                }}
              />
              {!versionValid && (
                <p className="text-sm text-destructive">The version is a whole number from 1.</p>
              )}
            </div>
          </>
        )}
        <div className="space-y-2">
          <Label htmlFor={keyId}>Key</Label>
          <Input
            id={keyId}
            value={key}
            autoComplete="off"
            onChange={(event) => {
              setKey(event.target.value)
            }}
          />
          <p className="text-sm text-muted-foreground">
            Lower case letters, digits and hyphens. It names the release line and never changes.
          </p>
        </div>
        <div className="space-y-2">
          <Label htmlFor={nameId}>Name</Label>
          <Input
            id={nameId}
            value={name}
            maxLength={MAX_NAME_LENGTH}
            autoComplete="off"
            onChange={(event) => {
              setName(event.target.value)
            }}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor={descriptionId}>Description (optional)</Label>
          <Textarea
            id={descriptionId}
            value={description}
            onChange={(event) => {
              setDescription(event.target.value)
            }}
          />
        </div>
        {create.error && (
          <p role="alert" className="text-sm text-destructive">
            {create.error.message}
          </p>
        )}
        <Button type="submit" disabled={!valid || create.isPending}>
          Create GameSystem
        </Button>
      </form>
    </>
  )
}
