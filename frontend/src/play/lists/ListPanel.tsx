import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import type { Campaign } from '../campaigns/useCampaigns'
import { useListActions, type ListFields, type ListItem, type ListKind } from './useLists'

type ReleaseTag = Campaign['tags'][number]

/** What differs between the two lists: the word, the name limit and the agenda. */
const kinds = {
  npc: { noun: 'NPC', maxName: 100, hasAgenda: true },
  thread: { noun: 'Thread', maxName: 200, hasAgenda: false },
} as const

const maxAgenda = 2000

/**
 * The NPCs or the Threads of a campaign: open items first, closed ones under a collapsed
 * "Closed", with an inline form to add and edit. The API validates; its refusals show as they
 * come and keep the form as typed. Lists belong to the campaign, so no scene is needed.
 */
export function ListPanel({ campaign, kind }: { campaign: Campaign; kind: ListKind }) {
  const { noun } = kinds[kind]
  const { save, setStatus } = useListActions(kind, campaign.id)
  // The id of the item being edited, 'new' for the add form, none when no form is open.
  const [form, setForm] = useState<string | null>(null)
  const items: ListItem[] = kind === 'npc' ? campaign.npcs : campaign.threads
  const open = items.filter(({ status }) => status === 'open')
  const closed = items.filter(({ status }) => status === 'closed')

  function openForm(next: string | null) {
    save.reset()
    setForm(next)
  }

  function renderItem(item: ListItem) {
    return form === item.id ? (
      <li key={item.id}>
        <ItemForm
          kind={kind}
          item={item}
          releaseTags={campaign.tags}
          pending={save.isPending}
          error={save.error?.message}
          onSubmit={(fields) => {
            save.mutate(
              { id: item.id, fields },
              {
                onSuccess: () => {
                  setForm(null)
                },
              },
            )
          }}
          onCancel={() => {
            openForm(null)
          }}
        />
      </li>
    ) : (
      <ItemRow
        key={item.id}
        item={item}
        releaseTags={campaign.tags}
        pending={setStatus.isPending}
        onEdit={() => {
          openForm(item.id)
        }}
        onToggle={() => {
          setStatus.mutate({ id: item.id, close: item.status === 'open' })
        }}
      />
    )
  }

  return (
    <div className="space-y-3">
      {open.length === 0 ? (
        <p className="text-sm text-muted-foreground">No open {noun}s yet.</p>
      ) : (
        <ul className="space-y-3">{open.map(renderItem)}</ul>
      )}
      {closed.length > 0 && (
        <details className="space-y-3">
          <summary className="cursor-pointer text-sm text-muted-foreground">
            Closed ({closed.length})
          </summary>
          <ul className="mt-3 space-y-3">{closed.map(renderItem)}</ul>
        </details>
      )}
      {setStatus.error && (
        <p role="alert" className="text-sm text-destructive">
          {setStatus.error.message}
        </p>
      )}
      {form === 'new' ? (
        <ItemForm
          kind={kind}
          item={null}
          releaseTags={campaign.tags}
          pending={save.isPending}
          error={save.error?.message}
          onSubmit={(fields) => {
            save.mutate(
              { id: null, fields },
              {
                onSuccess: () => {
                  setForm(null)
                },
              },
            )
          }}
          onCancel={() => {
            openForm(null)
          }}
        />
      ) : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            openForm('new')
          }}
        >
          Add {noun}
        </Button>
      )}
    </div>
  )
}

const chip = 'rounded-md px-1.5 py-0.5 text-xs font-medium'

function ItemRow({
  item,
  releaseTags,
  pending,
  onEdit,
  onToggle,
}: {
  item: ListItem
  releaseTags: ReleaseTag[]
  pending: boolean
  onEdit: () => void
  onToggle: () => void
}) {
  const agenda = 'agenda' in item ? item.agenda : null
  return (
    <li className="space-y-1">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-medium">
          {item.name}
          {item.weight > 1 && (
            <span className="ml-2 text-xs font-normal text-muted-foreground">
              <span aria-hidden="true">×{item.weight}</span>
              <span className="sr-only">Weight {item.weight}</span>
            </span>
          )}
        </span>
        <span className="flex gap-1">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={`Edit ${item.name}`}
            onClick={onEdit}
          >
            Edit
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={`${item.status === 'open' ? 'Close' : 'Reopen'} ${item.name}`}
            disabled={pending}
            onClick={onToggle}
          >
            {item.status === 'open' ? 'Close' : 'Reopen'}
          </Button>
        </span>
      </div>
      {agenda !== null && agenda !== '' && (
        <p className="text-sm text-muted-foreground">{agenda}</p>
      )}
      {(item.tags.length > 0 || item.freeTags.length > 0) && (
        <div className="flex flex-wrap gap-1">
          {item.tags.map((key) => {
            // A key the release no longer lists shows as it is.
            const tag = releaseTags.find((held) => held.key === key)
            return (
              <span
                key={key}
                title={tag?.hint ?? undefined}
                className={`${chip} bg-muted text-muted-foreground`}
              >
                {tag?.label ?? key}
              </span>
            )
          })}
          {item.freeTags.map((label) => (
            <span key={label} className={`${chip} border border-dashed border-border`}>
              {label}
            </span>
          ))}
        </div>
      )}
    </li>
  )
}

function ItemForm({
  kind,
  item,
  releaseTags,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  kind: ListKind
  item: ListItem | null
  releaseTags: ReleaseTag[]
  pending: boolean
  error: string | undefined
  onSubmit: (fields: ListFields) => void
  onCancel: () => void
}) {
  const { noun, maxName, hasAgenda } = kinds[kind]
  const id = useId()
  const agenda = item !== null && 'agenda' in item ? item.agenda : null
  // Free tags are a list, never one string: a tag may hold a comma. Unsaved text in the
  // input is kept as one more tag on save, so typing and saving loses nothing.
  const [freeTags, setFreeTags] = useState<string[]>(item?.freeTags ?? [])
  const [draftTag, setDraftTag] = useState('')
  // The release's Tags, then the item's keys the release does not list, so saving keeps them.
  const options: ReleaseTag[] = [
    ...releaseTags,
    ...(item?.tags ?? [])
      .filter((key) => !releaseTags.some((tag) => tag.key === key))
      .map((key) => ({ key, label: key, hint: null })),
  ]

  function addFreeTag() {
    const tag = draftTag.trim()
    if (tag !== '') {
      setFreeTags([...freeTags, tag])
      setDraftTag('')
    }
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const text = (name: string) => {
      const value = data.get(name)
      return typeof value === 'string' ? value : ''
    }
    const fields: ListFields = {
      name: text('name'),
      tags: data.getAll('tags').filter((tag) => typeof tag === 'string'),
      freeTags: draftTag.trim() === '' ? freeTags : [...freeTags, draftTag.trim()],
      weight: Number(text('weight')),
    }
    if (hasAgenda) {
      fields.agenda = text('agenda').trim() === '' ? null : text('agenda')
    }
    onSubmit(fields)
  }

  return (
    <form
      aria-label={item === null ? `Add ${noun}` : `Edit ${item.name}`}
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
            defaultValue={item?.name ?? ''}
          />
        </div>
        {hasAgenda && (
          <div className="space-y-1">
            <Label htmlFor={`${id}-agenda`}>Agenda</Label>
            <Textarea
              id={`${id}-agenda`}
              name="agenda"
              maxLength={maxAgenda}
              defaultValue={agenda ?? ''}
            />
          </div>
        )}
        {options.length > 0 && (
          <fieldset className="space-y-1">
            <legend className="text-sm font-medium">Tags</legend>
            {options.map((tag) => (
              <label key={tag.key} title={tag.hint ?? undefined} className="flex items-start gap-2">
                <input
                  type="checkbox"
                  name="tags"
                  value={tag.key}
                  defaultChecked={item?.tags.includes(tag.key) ?? false}
                  className="mt-1"
                />
                <span>
                  {tag.label}
                  {tag.hint !== null && (
                    <span className="block text-xs text-muted-foreground">{tag.hint}</span>
                  )}
                </span>
              </label>
            ))}
          </fieldset>
        )}
        <div className="space-y-1">
          <Label htmlFor={`${id}-free`}>Free tags</Label>
          {freeTags.length > 0 && (
            <ul className="flex flex-wrap gap-1">
              {freeTags.map((label, index) => (
                <li
                  key={index}
                  className={`${chip} flex items-center gap-1 border border-dashed border-border`}
                >
                  {label}
                  <button
                    type="button"
                    aria-label={`Remove free tag ${label}`}
                    onClick={() => {
                      setFreeTags(freeTags.filter((_, held) => held !== index))
                    }}
                  >
                    <span aria-hidden="true">×</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="flex gap-2">
            <Input
              id={`${id}-free`}
              value={draftTag}
              aria-describedby={`${id}-free-help`}
              onChange={(event) => {
                setDraftTag(event.target.value)
              }}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  addFreeTag()
                }
              }}
            />
            <Button type="button" variant="outline" size="sm" onClick={addFreeTag}>
              Add free tag
            </Button>
          </div>
          <p id={`${id}-free-help`} className="text-xs text-muted-foreground">
            One tag at a time; commas are part of the tag.
          </p>
        </div>
        <div className="space-y-1">
          <Label htmlFor={`${id}-weight`}>Weight</Label>
          <NativeSelect
            id={`${id}-weight`}
            name="weight"
            aria-describedby={`${id}-weight-help`}
            defaultValue={String(item?.weight ?? 1)}
          >
            {[1, 2, 3].map((weight) => (
              <option key={weight} value={weight}>
                {weight}
              </option>
            ))}
          </NativeSelect>
          <p id={`${id}-weight-help`} className="text-xs text-muted-foreground">
            How often it comes up in random picks.
          </p>
        </div>
        <div className="flex gap-2">
          <Button type="submit" size="sm">
            Save
          </Button>
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            Cancel
          </Button>
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
