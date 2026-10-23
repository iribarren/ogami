import { X } from 'lucide-react'
import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import type { Campaign } from '../campaigns/useCampaigns'
import { useFactActions, type Fact, type FactLink, type FactSlot } from './useFacts'

const maxLabel = 100
const maxText = 2000
const maxLinks = 20
/** The longest name of a new NPC or Thread, as the lists take them. */
const maxNewName = { npc: 100, thread: 200 } as const

const kindNouns = { npc: 'NPC', thread: 'Thread', fact: 'Fact' } as const

/** A link as shown: its target's name, resolved by the server. */
type NamedLink = Fact['links'][number]

/** A link is its kind and id: an NPC and a Thread may share an id. */
const sameLink = (a: NamedLink, b: NamedLink) => a.kind === b.kind && a.id === b.id

/** Links as the API takes them: a kind and an id, never the name it resolved. */
const asRequest = (links: NamedLink[]): FactLink[] => links.map(({ kind, id }) => ({ kind, id }))

const fits = (value: string, max: number) => value.trim() !== '' && value.trim().length <= max

/**
 * The Campaign facts: the release's Fact slots, filled or not, then the free facts the player
 * wrote. One form is open at a time: fill a slot, add or edit a free fact, or edit the links of
 * any fact. The API validates; its refusals show beside the form, which keeps what was typed.
 */
export function FactsPanel({ campaign }: { campaign: Campaign }) {
  const { add, edit, fill } = useFactActions(campaign.id)
  // 'add', 'fill:<slot>', 'edit:<fact id>' or 'links:<fact id>'; none when no form is open.
  const [form, setForm] = useState<string | null>(null)
  const freeFacts = campaign.facts.filter(({ slot }) => slot === null)

  function openForm(next: string | null) {
    add.reset()
    edit.reset()
    fill.reset()
    setForm(next)
  }
  const close = () => {
    setForm(null)
  }

  function linksForm(fact: Fact) {
    return (
      <LinksForm
        key={fact.id}
        campaign={campaign}
        fact={fact}
        pending={edit.isPending}
        error={edit.error?.message}
        onSubmit={(links) => {
          // A slot fact takes only its links; a free fact keeps its label and text.
          const body =
            fact.slot === null ? { label: fact.label, text: fact.text ?? '', links } : { links }
          edit.mutate({ factId: fact.id, body }, { onSuccess: close })
        }}
        onCancel={() => {
          openForm(null)
        }}
      />
    )
  }

  function actions(fact: Fact | undefined, label: string) {
    return (
      fact !== undefined && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label={`Edit links of ${label}`}
          onClick={() => {
            openForm(`links:${fact.id}`)
          }}
        >
          Links
        </Button>
      )
    )
  }

  return (
    <div className="space-y-4">
      {campaign.factSlots.length === 0 && freeFacts.length === 0 && (
        <p className="text-sm text-muted-foreground">No facts yet.</p>
      )}
      {campaign.factSlots.length > 0 && (
        <ul className="space-y-3">
          {campaign.factSlots.map((slot) => {
            const fact = campaign.facts.find((held) => held.slot === slot.key)
            return (
              <li key={slot.key} className="space-y-1">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="font-medium">{slot.label}</span>
                  <span className="flex gap-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      aria-label={`${fact === undefined ? 'Fill' : 'Refill'} ${slot.label}`}
                      onClick={() => {
                        openForm(`fill:${slot.key}`)
                      }}
                    >
                      {fact === undefined ? 'Fill' : 'Refill'}
                    </Button>
                    {actions(fact, slot.label)}
                  </span>
                </div>
                {slot.hint !== null && <p className="text-sm text-muted-foreground">{slot.hint}</p>}
                {fact === undefined ? (
                  <p className="text-sm text-muted-foreground">Not filled</p>
                ) : (
                  <p className="whitespace-pre-wrap">{fact.value}</p>
                )}
                {fact !== undefined && <LinkNames links={fact.links} />}
                {form === `fill:${slot.key}` && (
                  <SlotForm
                    campaign={campaign}
                    slot={slot}
                    current={fact}
                    pending={fill.isPending}
                    error={fill.error?.message}
                    onSubmit={(value) => {
                      fill.mutate({ slot: slot.key, value }, { onSuccess: close })
                    }}
                    onCancel={() => {
                      openForm(null)
                    }}
                  />
                )}
                {fact !== undefined && form === `links:${fact.id}` && linksForm(fact)}
              </li>
            )
          })}
        </ul>
      )}
      {freeFacts.length > 0 && (
        <ul className="space-y-3">
          {freeFacts.map((fact) =>
            form === `edit:${fact.id}` ? (
              <li key={fact.id}>
                <FreeFactForm
                  fact={fact}
                  pending={edit.isPending}
                  error={edit.error?.message}
                  onSubmit={(fields) => {
                    edit.mutate(
                      { factId: fact.id, body: { ...fields, links: asRequest(fact.links) } },
                      { onSuccess: close },
                    )
                  }}
                  onCancel={() => {
                    openForm(null)
                  }}
                />
              </li>
            ) : (
              <li key={fact.id} className="space-y-1">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="font-medium">{fact.label}</span>
                  <span className="flex gap-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      aria-label={`Edit ${fact.label}`}
                      onClick={() => {
                        openForm(`edit:${fact.id}`)
                      }}
                    >
                      Edit
                    </Button>
                    {actions(fact, fact.label)}
                  </span>
                </div>
                <p className="whitespace-pre-wrap">{fact.value}</p>
                <LinkNames links={fact.links} />
                {form === `links:${fact.id}` && linksForm(fact)}
              </li>
            ),
          )}
        </ul>
      )}
      {form === 'add' ? (
        <FreeFactForm
          fact={null}
          pending={add.isPending}
          error={add.error?.message}
          onSubmit={(fields) => {
            add.mutate({ ...fields, links: [] }, { onSuccess: close })
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
            openForm('add')
          }}
        >
          Add fact
        </Button>
      )}
    </div>
  )
}

/** The names a fact links to, each with its kind. */
function LinkNames({ links }: { links: NamedLink[] }) {
  return (
    links.length > 0 && (
      <p className="text-xs text-muted-foreground">
        Links: {links.map(({ kind, name }) => `${name} (${kindNouns[kind]})`).join(', ')}
      </p>
    )
  )
}

const formClass = 'space-y-3 rounded-lg border border-border p-3'

function FormError({ error }: { error: string | undefined }) {
  return (
    error !== undefined && (
      <p role="alert" className="text-sm text-destructive">
        {error}
      </p>
    )
  )
}

function FormButtons({ disabled, onCancel }: { disabled: boolean; onCancel: () => void }) {
  return (
    <div className="flex gap-2">
      <Button type="submit" size="sm" disabled={disabled}>
        Save
      </Button>
      <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
        Cancel
      </Button>
    </div>
  )
}

/** A free fact's label and text. */
function FreeFactForm({
  fact,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  fact: Fact | null
  pending: boolean
  error: string | undefined
  onSubmit: (fields: { label: string; text: string }) => void
  onCancel: () => void
}) {
  const id = useId()
  const [label, setLabel] = useState(fact?.label ?? '')
  const [text, setText] = useState(fact?.text ?? '')

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    onSubmit({ label: label.trim(), text: text.trim() })
  }

  return (
    <form
      aria-label={fact === null ? 'Add fact' : `Edit ${fact.label}`}
      className={formClass}
      onSubmit={handleSubmit}
    >
      <fieldset disabled={pending} className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`${id}-label`}>Label</Label>
          <Input
            id={`${id}-label`}
            value={label}
            maxLength={maxLabel}
            onChange={(event) => {
              setLabel(event.target.value)
            }}
          />
        </div>
        <div className="space-y-1">
          <Label htmlFor={`${id}-text`}>Text</Label>
          <Textarea
            id={`${id}-text`}
            value={text}
            maxLength={maxText}
            onChange={(event) => {
              setText(event.target.value)
            }}
          />
        </div>
        <FormButtons
          disabled={!fits(label, maxLabel) || !fits(text, maxText)}
          onCancel={onCancel}
        />
      </fieldset>
      <FormError error={error} />
    </form>
  )
}

/**
 * Fills or refills a Fact slot: a text slot takes a text, an NPC or Thread slot an existing item
 * (closed ones too) or a new one by name. Exactly one value goes to the API.
 */
function SlotForm({
  campaign,
  slot,
  current,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  campaign: Campaign
  slot: FactSlot
  current: Fact | undefined
  pending: boolean
  error: string | undefined
  onSubmit: (value: { text: string } | { subject: string } | { name: string }) => void
  onCancel: () => void
}) {
  const id = useId()
  const [text, setText] = useState(current?.text ?? '')
  // The id of the item picked; '' to create a new one.
  const [subject, setSubject] = useState(current?.subject?.id ?? '')
  const [name, setName] = useState('')

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (slot.type === 'text') {
      onSubmit({ text: text.trim() })
    } else {
      onSubmit(subject === '' ? { name: name.trim() } : { subject })
    }
  }

  if (slot.type === 'text') {
    return (
      <form aria-label={`Fill ${slot.label}`} className={formClass} onSubmit={handleSubmit}>
        <fieldset disabled={pending} className="space-y-3">
          <div className="space-y-1">
            <Label htmlFor={`${id}-text`}>Text</Label>
            <Textarea
              id={`${id}-text`}
              value={text}
              maxLength={maxText}
              onChange={(event) => {
                setText(event.target.value)
              }}
            />
          </div>
          <FormButtons disabled={!fits(text, maxText)} onCancel={onCancel} />
        </fieldset>
        <FormError error={error} />
      </form>
    )
  }

  const noun = kindNouns[slot.type]
  const items = slot.type === 'npc' ? campaign.npcs : campaign.threads
  return (
    <form aria-label={`Fill ${slot.label}`} className={formClass} onSubmit={handleSubmit}>
      <fieldset disabled={pending} className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`${id}-subject`}>{noun}</Label>
          <NativeSelect
            id={`${id}-subject`}
            value={subject}
            onChange={(event) => {
              setSubject(event.target.value)
            }}
          >
            <option value="">New {noun}…</option>
            {items.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
                {item.status === 'closed' ? ' (closed)' : ''}
              </option>
            ))}
          </NativeSelect>
        </div>
        {subject === '' && (
          <div className="space-y-1">
            <Label htmlFor={`${id}-name`}>{`New ${noun} name`}</Label>
            <Input
              id={`${id}-name`}
              value={name}
              maxLength={maxNewName[slot.type]}
              onChange={(event) => {
                setName(event.target.value)
              }}
            />
          </div>
        )}
        <FormButtons
          disabled={subject === '' && !fits(name, maxNewName[slot.type])}
          onCancel={onCancel}
        />
      </fieldset>
      <FormError error={error} />
    </form>
  )
}

/**
 * The links of a fact, edited as a draft and saved whole. It offers every NPC and Thread (closed
 * ones too) and every other fact, never the fact itself nor a link already there, up to 20.
 */
function LinksForm({
  campaign,
  fact,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  campaign: Campaign
  fact: Fact
  pending: boolean
  error: string | undefined
  onSubmit: (links: FactLink[]) => void
  onCancel: () => void
}) {
  const id = useId()
  const [draft, setDraft] = useState<NamedLink[]>(fact.links)
  const [target, setTarget] = useState('')
  const targets: NamedLink[] = [
    ...campaign.npcs.map(({ id, name }) => ({ kind: 'npc' as const, id, name })),
    ...campaign.threads.map(({ id, name }) => ({ kind: 'thread' as const, id, name })),
    ...campaign.facts
      .filter((held) => held.id !== fact.id)
      .map(({ id, label }) => ({ kind: 'fact' as const, id, name: label })),
  ].filter((target) => !draft.some((held) => sameLink(held, target)))
  const full = draft.length >= maxLinks

  function addLink() {
    const added = targets.find(({ kind, id: targetId }) => `${kind}:${targetId}` === target)
    if (added !== undefined && !full) {
      setDraft([...draft, added])
      setTarget('')
    }
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    onSubmit(asRequest(draft))
  }

  return (
    <form aria-label={`Links of ${fact.label}`} className={formClass} onSubmit={handleSubmit}>
      <fieldset disabled={pending} className="space-y-3">
        {draft.length > 0 && (
          <ul className="flex flex-wrap gap-1">
            {draft.map((link) => (
              <li
                key={`${link.kind}:${link.id}`}
                className="flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium"
              >
                {link.name} ({kindNouns[link.kind]})
                <button
                  type="button"
                  aria-label={`Remove link ${link.name}`}
                  onClick={() => {
                    setDraft(draft.filter((held) => !sameLink(held, link)))
                  }}
                >
                  <X aria-hidden="true" className="size-3" />
                </button>
              </li>
            ))}
          </ul>
        )}
        <div className="space-y-1">
          <Label htmlFor={`${id}-target`}>Link to</Label>
          <div className="flex gap-2">
            <NativeSelect
              id={`${id}-target`}
              value={target}
              disabled={full}
              onChange={(event) => {
                setTarget(event.target.value)
              }}
            >
              <option value="">Choose…</option>
              {targets.map(({ kind, id: targetId, name }) => (
                <option key={`${kind}:${targetId}`} value={`${kind}:${targetId}`}>
                  {kindNouns[kind]}: {name}
                </option>
              ))}
            </NativeSelect>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={target === '' || full}
              onClick={addLink}
            >
              Add link
            </Button>
          </div>
          {full && (
            <p className="text-xs text-muted-foreground">A fact takes at most {maxLinks} links.</p>
          )}
        </div>
        <FormButtons disabled={false} onCancel={onCancel} />
      </fieldset>
      <FormError error={error} />
    </form>
  )
}
