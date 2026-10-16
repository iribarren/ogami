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
  type GameSystemNarrative,
  useSaveNarrativeItem,
  type NarrativeSection,
  type StoredItem,
} from './useGameSystemNarrative'

/** The property of the narrative view that holds the items of a section. */
const sectionProperty = {
  trackers: 'trackers',
  tags: 'tags',
  'fact-slots': 'factSlots',
  'scene-types': 'sceneTypes',
  flows: 'flows',
} as const satisfies Record<NarrativeSection, keyof GameSystemNarrative>

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
  // The form is filled once, when the dialog opens, so the version it was read at is kept with
  // it: a refetch while the dialog is open must not make a stale draft look current.
  const [readAt, setReadAt] = useState(version)
  const [errors, setErrors] = useState<FieldErrors>({})
  // Why a reload did not replace the draft; the old version is kept then, so a save still conflicts.
  const [reloadNote, setReloadNote] = useState<string | null>(null)
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
      { expectedVersion: readAt, section: form.section, original, item: result.item },
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
      {reloadNote && (
        <p role="alert" className="text-sm text-destructive">
          {reloadNote}
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
                if (result.isError || result.data === undefined) {
                  setReloadNote("Couldn't reload the latest version. Try again.")
                  return
                }
                // The item and its version come from the same read, and the version is only taken
                // with a replaced draft: otherwise a later save would overwrite what was not seen.
                const stored =
                  original === null
                    ? undefined
                    : result.data[sectionProperty[form.section]].find(
                        (item) => item.key === original,
                      )
                const reloaded = stored === undefined ? null : form.read(stored.definition)
                if (original !== null && reloaded === null) {
                  setReloadNote(
                    stored === undefined
                      ? `The ${form.noun} ${original} no longer exists. Your changes are kept; close this dialog to see the list.`
                      : `The ${form.noun} changed to a shape this editor cannot read. Your changes are kept; the next save still conflicts.`,
                  )
                  return
                }
                // A new item keeps its draft: there is nothing to overwrite.
                if (reloaded !== null) {
                  setDraft(reloaded)
                }
                setReloadNote(null)
                setReadAt(result.data.version)
                save.reset()
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
