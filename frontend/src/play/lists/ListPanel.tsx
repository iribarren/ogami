import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import type { Campaign } from '../campaigns/useCampaigns'
import { TagChips, TagFields, type ReleaseTag } from './TagFields'
import { useFreeTags } from './useFreeTags'
import { useListActions, type ListFields, type ListItem, type ListKind } from './useLists'

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
        facts={linkedFacts(campaign, kind, item)}
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

/** The labels of the facts linked to an item: those that link it and the slot facts it fills. */
function linkedFacts(campaign: Campaign, kind: ListKind, item: ListItem): string[] {
  return campaign.facts
    .filter(
      ({ links, subject }) =>
        links.some((link) => link.kind === kind && link.id === item.id) ||
        (subject?.kind === kind && subject.id === item.id),
    )
    .map(({ label }) => label)
}

function ItemRow({
  item,
  facts,
  releaseTags,
  pending,
  onEdit,
  onToggle,
}: {
  item: ListItem
  /** The labels of the facts linked to the item. */
  facts: string[]
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
      {facts.length > 0 && (
        <p className="text-xs text-muted-foreground">Facts: {facts.join(', ')}</p>
      )}
      <TagChips tags={item.tags} freeTags={item.freeTags} releaseTags={releaseTags} />
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
  const free = useFreeTags(item?.freeTags ?? [])

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
      freeTags: free.toSave(),
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
        <TagFields id={id} releaseTags={releaseTags} held={item?.tags ?? []} free={free} />
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
