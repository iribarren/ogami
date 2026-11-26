import { useId, useState, type SubmitEvent } from 'react'

import { Plus, X } from 'lucide-react'

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
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'
import { Textarea } from '@/shared/ui/textarea'

import { KeyHint } from './KeyHint'
import {
  useCreateGameSystem,
  usePublishedGameSystemsForCopy,
  type CreateGameSystemRequest,
} from './useGameSystems'

type StartingPoint = 'blank' | 'copy'

/** The API's limit on a GameSystem name (the release contract's), checked by the server too. */
const MAX_NAME_LENGTH = 100

/**
 * The "New GameSystem" button and its dialog: a blank draft, or a copy of a published release
 * picked from the published GameSystems (the latest version unless one is picked). Creating opens the new GameSystem; a
 * refusal shows the API's message inside the dialog.
 */
export function NewGameSystemDialog() {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus aria-hidden="true" />
          New GameSystem
        </Button>
      </DialogTrigger>
      <DialogContent>
        <div className="flex items-start gap-3">
          <DialogHeader className="min-w-0 flex-1">
            <DialogTitle>New GameSystem</DialogTitle>
            <DialogDescription>
              Start from a blank draft, or copy a published release under a new key.
            </DialogDescription>
          </DialogHeader>
          <DialogClose asChild>
            <IconButton label="Close">
              <X />
            </IconButton>
          </DialogClose>
        </div>
        <NewGameSystemForm />
      </DialogContent>
    </Dialog>
  )
}

function NewGameSystemForm() {
  const [startingPoint, setStartingPoint] = useState<StartingPoint>('blank')
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [sourceKey, setSourceKey] = useState('')
  const [sourceVersion, setSourceVersion] = useState('')
  const create = useCreateGameSystem()
  const copying = startingPoint === 'copy'
  const sources = usePublishedGameSystemsForCopy(copying)
  const keyId = useId()
  const nameId = useId()
  const descriptionId = useId()
  const sourceKeyId = useId()
  const sourceVersionId = useId()
  const source = sources.data?.find((candidate) => candidate.key === sourceKey)
  // Create waits for a source picked from the list: a list that is loading, failed or empty offers none.
  const valid = key.trim() !== '' && name.trim() !== '' && (!copying || source !== undefined)

  function pickSource(pickedKey: string) {
    setSourceKey(pickedKey)
    // Another GameSystem has other versions: back to its latest.
    setSourceVersion('')
    const picked = sources.data?.find((candidate) => candidate.key === pickedKey)
    if (picked !== undefined && name.trim() === '') {
      setName(`${picked.name} (copy)`.slice(0, MAX_NAME_LENGTH))
    }
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body: CreateGameSystemRequest = { key, name }
    if (description.trim() !== '') {
      body.description = description
    }
    if (copying) {
      body.source = { key: sourceKey }
      if (sourceVersion !== '') {
        body.source.version = Number(sourceVersion)
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
              <Label htmlFor={sourceKeyId}>Source GameSystem</Label>
              <NativeSelect
                id={sourceKeyId}
                value={sourceKey}
                disabled={sources.isPending || sources.data?.length === 0}
                onChange={(event) => {
                  pickSource(event.target.value)
                }}
              >
                {sources.isPending ? (
                  <option value="">Loading…</option>
                ) : (
                  <>
                    <option value="">Choose a GameSystem</option>
                    {sources.data?.map((candidate) => (
                      <option key={candidate.key} value={candidate.key}>
                        {candidate.name} ({candidate.key})
                      </option>
                    ))}
                  </>
                )}
              </NativeSelect>
              {sources.error && (
                <p role="alert" className="text-sm text-destructive">
                  {sources.error.message}
                </p>
              )}
              {sources.data?.length === 0 && (
                <p className="text-sm text-muted-foreground">
                  No GameSystem has a published release yet.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor={sourceVersionId}>Source version</Label>
              <NativeSelect
                id={sourceVersionId}
                value={sourceVersion}
                disabled={source === undefined}
                onChange={(event) => {
                  setSourceVersion(event.target.value)
                }}
              >
                <option value="">
                  Latest{source === undefined ? '' : ` (v${String(source.latestVersion)})`}
                </option>
                {source !== undefined &&
                  Array.from(
                    { length: source.latestVersion },
                    (_, index) => source.latestVersion - index,
                  ).map((version) => (
                    <option key={version} value={String(version)}>
                      v{version}
                    </option>
                  ))}
              </NativeSelect>
            </div>
          </>
        )}
        <div className="space-y-2">
          <Label htmlFor={keyId}>Key</Label>
          <Input
            id={keyId}
            value={key}
            autoComplete="off"
            aria-describedby={`${keyId}-hint`}
            onChange={(event) => {
              setKey(event.target.value)
            }}
          />
          <KeyHint id={`${keyId}-hint`}>It names the release line and never changes.</KeyHint>
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
        <div className="-mx-4 -mb-4 action-bar">
          <DialogClose asChild>
            <Button type="button" variant="ghost">
              Cancel
            </Button>
          </DialogClose>
          <Button type="submit" disabled={!valid || create.isPending}>
            Create GameSystem
          </Button>
        </div>
      </form>
    </>
  )
}
