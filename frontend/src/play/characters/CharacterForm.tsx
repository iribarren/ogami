import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { TagFields, type ReleaseTag } from '../lists/TagFields'
import { useFreeTags } from '../lists/useFreeTags'
import type { Tracker } from '../trackers/useTrackers'
import { computeDerived } from './derivedValues'
import { SheetFields } from './SheetFields'
import { fromDraft, storedFields, toDraft, type Draft } from './sheetValues'
import type { Character, CharacterFields, SheetField } from './useCharacters'

const maxName = 100

/**
 * Adds or edits a Character: name, the pinned release's sheet fields, Tags and free tags. It
 * always submits the whole Character. The API validates; its refusal shows as it comes and the
 * form keeps what was typed. Derived fields are shown read-only, recomputed as the player types
 * from the drafts and the Campaign's `trackers`; they are never submitted.
 */
export function CharacterForm({
  character,
  sheet,
  trackers,
  releaseTags,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  character: Character | null
  /** Every field of the sheet, derived ones included. */
  sheet: SheetField[]
  trackers: Tracker[]
  releaseTags: ReleaseTag[]
  pending: boolean
  error: string | undefined
  onSubmit: (fields: CharacterFields) => void
  /** Without it the form has no Cancel: the step that shows it has nothing to go back to. */
  onCancel?: (() => void) | undefined
}) {
  const id = useId()
  const free = useFreeTags(character?.freeTags ?? [])
  // A field the Character has no value for starts as its default.
  const stored = storedFields(sheet)
  const [drafts, setDrafts] = useState<Record<string, Draft>>(() =>
    Object.fromEntries(
      stored.map((field) => [field.key, toDraft(field, character?.values[field.key])]),
    ),
  )

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const name = data.get('name')
    onSubmit({
      name: typeof name === 'string' ? name : '',
      tags: data.getAll('tags').filter((tag) => typeof tag === 'string'),
      freeTags: free.toSave(),
      values: Object.fromEntries(
        stored.map((field) => [
          field.key,
          fromDraft(field, drafts[field.key] ?? toDraft(field, undefined)),
        ]),
      ),
    })
  }

  return (
    <form
      aria-label={character === null ? 'Add Character' : `Edit ${character.name}`}
      className="space-y-3 rounded-lg border border-border p-3"
      onSubmit={handleSubmit}
    >
      <fieldset disabled={pending} className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`${id}-name`}>Name</Label>
          <Input
            id={`${id}-name`}
            name="name"
            required
            maxLength={maxName}
            defaultValue={character?.name ?? ''}
          />
        </div>
        <SheetFields
          fields={sheet}
          drafts={drafts}
          derived={computeDerived(sheet, drafts, trackers)}
          onChange={(key, draft) => {
            setDrafts({ ...drafts, [key]: draft })
          }}
        />
        <TagFields id={id} releaseTags={releaseTags} held={character?.tags ?? []} free={free} />
        <div className="flex gap-2">
          <Button type="submit" size="sm">
            Save
          </Button>
          {onCancel !== undefined && (
            <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
              Cancel
            </Button>
          )}
        </div>
      </fieldset>
      {error !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </form>
  )
}
