import { useState, type ReactNode, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import { StudioError } from '../useGameSystems'
import type { FieldErrors, ItemResult } from './narrativeDraft'
import {
  NarrativeError,
  useGameSystemNarrative,
  useSaveNarrativeItem,
  type NarrativeSection,
  type StoredItem,
} from './useGameSystemNarrative'

/** What the dialog needs to know about one kind of item. */
export interface ItemForm<Draft> {
  /** "tracker", "tag", "fact slot": used in titles and buttons. */
  noun: string
  section: NarrativeSection
  blank: () => Draft
  /** The stored item as form state, or null when this editor cannot read it. */
  read: (definition: StoredItem) => Draft | null
  write: (draft: Draft) => ItemResult
  Fields: (props: {
    draft: Draft
    onChange: (draft: Draft) => void
    errors: FieldErrors
  }) => ReactNode
}

/**
 * The create button or an Edit button (`original` is the key of the item being replaced) and its
 * dialog. Failing keeps the dialog open with what was typed; the form only closes once the API
 * has answered with the saved narrative.
 */
export function ItemDialog<Draft>({
  gameSystemKey,
  form,
  version,
  original,
  initial,
  trigger,
}: {
  gameSystemKey: string
  form: ItemForm<Draft>
  version: number
  original: string | null
  initial: Draft
  trigger: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveNarrativeItem(gameSystemKey)
  const title = original === null ? `New ${form.noun}` : `Edit ${original}`

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            The publisher checks the {form.noun} when you save it; nothing is saved if it refuses.
          </DialogDescription>
        </DialogHeader>
        <ItemFormBody
          gameSystemKey={gameSystemKey}
          form={form}
          version={version}
          original={original}
          initial={initial}
          save={save}
          onSaved={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function ItemFormBody<Draft>({
  gameSystemKey,
  form,
  version,
  original,
  initial,
  save,
  onSaved,
}: {
  gameSystemKey: string
  form: ItemForm<Draft>
  version: number
  original: string | null
  initial: Draft
  save: ReturnType<typeof useSaveNarrativeItem>
  onSaved: () => void
}) {
  const [draft, setDraft] = useState(initial)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [reloadFailed, setReloadFailed] = useState(false)
  const latest = useGameSystemNarrative(gameSystemKey)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = form.write(draft)
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    save.mutate(
      { expectedVersion: version, section: form.section, original, item: result.item },
      { onSuccess: onSaved },
    )
  }

  const { error } = save

  return (
    <form
      aria-label={original === null ? `New ${form.noun}` : `Edit ${original}`}
      className="space-y-4"
      onSubmit={handleSubmit}
    >
      <form.Fields draft={draft} onChange={setDraft} errors={errors} />
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error.message}
          {error instanceof NarrativeError && error.paths.length > 0
            ? ` Used by ${error.paths.join(', ')}.`
            : ''}
        </p>
      )}
      {reloadFailed && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t reload the latest version. Try again.
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={save.isPending}>
          {original === null ? `Create ${form.noun}` : `Save ${form.noun}`}
        </Button>
        {error instanceof StudioError && error.status === 409 && (
          <Button
            type="button"
            variant="outline"
            onClick={() => {
              void latest.refetch().then((result) => {
                setReloadFailed(result.isError)
                if (!result.isError) {
                  save.reset()
                }
              })
            }}
          >
            Reload the latest version
          </Button>
        )}
      </div>
    </form>
  )
}
