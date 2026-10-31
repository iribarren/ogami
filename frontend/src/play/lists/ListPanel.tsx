import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import type { Campaign } from '../campaigns/useCampaigns'
import { usePromoteNpc } from '../characters/useCharacters'
import { TagChips, TagFields, type ReleaseTag } from './TagFields'
import { useFreeTags } from './useFreeTags'
import { useListActions, type ListFields, type ListItem, type ListKind } from './useLists'

/** What differs between the lists: the word, the name limit, the agenda and the notes. */
const kinds = {
  npc: { noun: 'NPC', maxName: 100, hasAgenda: true, hasNotes: false },
  thread: { noun: 'Thread', maxName: 200, hasAgenda: false, hasNotes: false },
  place: { noun: 'Place', maxName: 200, hasAgenda: false, hasNotes: true },
} as const

const maxAgenda = 2000
const maxNotes = 2000

/**
 * The NPCs, Threads or Places of a campaign: open items first, closed ones under a collapsed
 * "Closed", with an inline form to add and edit. The API validates; its refusals show as they
 * come and keep the form as typed. Lists belong to the campaign, so no scene is needed.
 */
export function ListPanel({ campaign, kind }: { campaign: Campaign; kind: ListKind }) {
  const { noun } = kinds[kind]
  const { save, setStatus } = useListActions(kind, campaign.id)
  const promote = usePromoteNpc(campaign.id)
  // The id of the NPC whose promotion waits for a confirmation.
  const [confirming, setConfirming] = useState<string | null>(null)
  // The id of the item being edited, 'new' for the add form, none when no form is open.
  const [form, setForm] = useState<string | null>(null)
  const items: ListItem[] = {
    npc: campaign.npcs,
    thread: campaign.threads,
    place: campaign.places,
  }[kind]
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
        promotion={
          kind === 'npc'
            ? {
                confirming: confirming === item.id,
                pending: promote.isPending,
                onAsk: () => {
                  promote.reset()
                  setStatus.reset()
                  setConfirming(item.id)
                },
                onConfirm: () => {
                  // Only a success closes the confirmation: a refusal stays beside its message.
                  promote.mutate(item.id, {
                    onSuccess: () => {
                      setConfirming(null)
                    },
                  })
                },
                onCancel: () => {
                  setConfirming(null)
                },
              }
            : null
        }
        onEdit={() => {
          openForm(item.id)
        }}
        onToggle={() => {
          promote.reset()
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
      {(setStatus.error ?? promote.error) && (
        <p role="alert" className="text-sm text-destructive">
          {(setStatus.error ?? promote.error)?.message}
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
  promotion,
  onEdit,
  onToggle,
}: {
  item: ListItem
  /** The labels of the facts linked to the item. */
  facts: string[]
  releaseTags: ReleaseTag[]
  pending: boolean
  /** The NPC promotion, null for a Thread or a Place. */
  promotion: {
    confirming: boolean
    pending: boolean
    onAsk: () => void
    onConfirm: () => void
    onCancel: () => void
  } | null
  onEdit: () => void
  onToggle: () => void
}) {
  const agenda = 'agenda' in item ? item.agenda : null
  const notes = 'notes' in item ? item.notes : null
  // A promoted NPC is closed for good: it cannot be reopened or promoted again.
  const promoted = 'promotedTo' in item && item.promotedTo !== null
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
          {!(promoted && item.status === 'closed') && (
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
          )}
          {promotion !== null && !promoted && !promotion.confirming && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-label={`Promote ${item.name} to Character`}
              disabled={promotion.pending}
              onClick={promotion.onAsk}
            >
              Promote to Character
            </Button>
          )}
        </span>
      </div>
      {promoted && <p className="text-xs text-muted-foreground">Promoted</p>}
      {promotion?.confirming === true && !promoted && (
        <div role="group" aria-label={`Promote ${item.name}`} className="space-y-2 text-sm">
          <p>Promote {item.name} to a Character? The NPC is closed for good.</p>
          <div className="flex gap-2">
            <Button
              type="button"
              size="sm"
              disabled={promotion.pending}
              onClick={promotion.onConfirm}
            >
              Confirm promotion
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={promotion.onCancel}>
              Keep as NPC
            </Button>
          </div>
        </div>
      )}
      {agenda !== null && agenda !== '' && (
        <p className="text-sm text-muted-foreground">{agenda}</p>
      )}
      {notes !== null && notes !== '' && <p className="text-sm text-muted-foreground">{notes}</p>}
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
  const { noun, maxName, hasAgenda, hasNotes } = kinds[kind]
  const id = useId()
  const agenda = item !== null && 'agenda' in item ? item.agenda : null
  const notes = item !== null && 'notes' in item ? item.notes : null
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
    if (hasNotes) {
      fields.notes = text('notes').trim() === '' ? null : text('notes')
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
        {hasNotes && (
          <div className="space-y-1">
            <Label htmlFor={`${id}-notes`}>Notes</Label>
            <Textarea
              id={`${id}-notes`}
              name="notes"
              maxLength={maxNotes}
              defaultValue={notes ?? ''}
            />
          </div>
        )}
        <TagFields
          id={id}
          kind={kind}
          releaseTags={releaseTags}
          held={item?.tags ?? []}
          free={free}
        />
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
