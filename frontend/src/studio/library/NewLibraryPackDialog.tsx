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
import { Textarea } from '@/shared/ui/textarea'

import { useCreateLibraryPack, type CreateLibraryPackRequest } from './useLibraryPacks'

/** The API's limit on a pack name (the release contract's), checked by the server too. */
const MAX_NAME_LENGTH = 100

/**
 * The "New pack" button and its dialog: a blank library pack. Creating opens the new pack for
 * editing; a refusal (key taken, a rule broken) shows the API's message inside the dialog.
 */
export function NewLibraryPackDialog() {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>New pack</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New library pack</DialogTitle>
          <DialogDescription>
            A pack holds oracles, trackers, tags and Scene Types that any GameSystem can import.
          </DialogDescription>
        </DialogHeader>
        <NewLibraryPackForm />
      </DialogContent>
    </Dialog>
  )
}

function NewLibraryPackForm() {
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const create = useCreateLibraryPack()
  const keyId = useId()
  const nameId = useId()
  const descriptionId = useId()
  const valid = key.trim() !== '' && name.trim() !== ''

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body: CreateLibraryPackRequest = { key, name }
    if (description.trim() !== '') {
      body.description = description
    }
    create.mutate(body)
  }

  return (
    <form aria-label="New library pack" className="space-y-4" onSubmit={handleSubmit}>
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
          Lower case letters, digits and hyphens. It names the pack and never changes.
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
        Create pack
      </Button>
    </form>
  )
}
