import { Gauge, Pencil, Plus, ScrollText, Tag, Trash2 } from 'lucide-react'
import { useId, useState, type ReactNode } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'

import { StudioError } from '../useGameSystems'
import { ItemDialog, type ItemForm } from './ItemDialog'
import { NarrativeGate } from './NarrativeGate'
import { factSlotForm, tagForm, trackerForm } from './itemForms'
import type { LabelledDraft, TrackerDraft } from './narrativeDraft'
import {
  NarrativeError,
  useDeleteNarrativeItem,
  useGameSystemNarrative,
  type GameSystemNarrative,
  type NarrativeItem,
} from './useGameSystemNarrative'

/** The trackers, tags and fact slots of a draft, with where each is used. */
export function NarrativeTab({ gameSystemKey }: { gameSystemKey: string }) {
  const narrative = useGameSystemNarrative(gameSystemKey)

  return (
    <NarrativeGate narrative={narrative}>
      {(data) => <NarrativeSections gameSystemKey={gameSystemKey} data={data} />}
    </NarrativeGate>
  )
}

function NarrativeSections({
  gameSystemKey,
  data,
}: {
  gameSystemKey: string
  data: GameSystemNarrative
}) {
  return (
    <section aria-label="Trackers & tags" className="space-y-6">
      <ItemSection
        gameSystemKey={gameSystemKey}
        narrative={data}
        title="Trackers"
        icon={<Gauge />}
        items={data.trackers}
        form={trackerForm}
        titleField="name"
        empty="This draft has no trackers yet."
        summary={(draft: TrackerDraft) => (
          <>
            <Badge variant="secondary">
              {draft.kind === 'clock'
                ? `Clock, ${draft.segments} segments`
                : `Counter ${draft.min} to ${draft.max}`}
            </Badge>
            {draft.kind === 'counter' && draft.levels.length > 0 && (
              <span className="text-sm text-muted-foreground">
                {draft.levels.length === 1 ? '1 level' : `${String(draft.levels.length)} levels`}
              </span>
            )}
          </>
        )}
      />
      <div className="grid gap-6 lg:grid-cols-2">
        <ItemSection
          gameSystemKey={gameSystemKey}
          narrative={data}
          title="Tags"
          icon={<Tag />}
          items={data.tags}
          form={tagForm}
          titleField="label"
          empty="This draft has no tags yet."
          summary={() => null}
        />
        <ItemSection
          gameSystemKey={gameSystemKey}
          narrative={data}
          title="Fact slots"
          icon={<ScrollText />}
          items={data.factSlots}
          form={factSlotForm}
          titleField="label"
          empty="This draft has no fact slots yet."
          summary={(draft: LabelledDraft) => <Badge variant="secondary">{draft.type}</Badge>}
        />
      </div>
    </section>
  )
}

function ItemSection<Draft>({
  gameSystemKey,
  narrative,
  title,
  icon,
  items,
  form,
  titleField,
  empty,
  summary,
}: {
  gameSystemKey: string
  narrative: GameSystemNarrative
  title: string
  icon: ReactNode
  items: NarrativeItem[]
  form: ItemForm<Draft>
  /** The stored field that names the item in its row. */
  titleField: 'name' | 'label'
  empty: string
  summary: (draft: Draft) => ReactNode
}) {
  return (
    <section aria-label={title} className="self-start panel">
      <div className="panel-head">
        <IconTile size="sm">{icon}</IconTile>
        <h2 className="font-heading text-panel-title">{title}</h2>
        <div className="ml-auto">
          <ItemDialog
            gameSystemKey={gameSystemKey}
            form={form}
            version={narrative.version}
            original={null}
            initial={form.blank()}
            trigger={
              <Button variant="outline" size="sm">
                <Plus aria-hidden="true" />
                New {form.noun}
              </Button>
            }
          />
        </div>
      </div>
      {items.length === 0 ? (
        <div className="panel-body">
          <div className="empty-state">
            <IconTile size="sm">{icon}</IconTile>
            <p>{empty}</p>
            <p className="hint">Use New {form.noun} to add one.</p>
          </div>
        </div>
      ) : (
        <ul>
          {items.map((item) => (
            <ItemRow
              key={item.key}
              gameSystemKey={gameSystemKey}
              item={item}
              version={narrative.version}
              form={form}
              titleField={titleField}
              summary={summary}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

export function UsedBy({ paths }: { paths: string[] }) {
  return paths.length === 0 ? (
    <span className="text-muted-foreground">Not used</span>
  ) : (
    <span className="break-all text-muted-foreground">Used by {paths.join(', ')}</span>
  )
}

function ItemRow<Draft>({
  gameSystemKey,
  item,
  version,
  form,
  titleField,
  summary,
}: {
  gameSystemKey: string
  item: NarrativeItem
  version: number
  form: ItemForm<Draft>
  titleField: 'name' | 'label'
  summary: (draft: Draft) => ReactNode
}) {
  const reasonId = useId()
  const draft = form.read(item.definition)
  const title = item.definition[titleField]

  return (
    <li className="list-row">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="list-row-title">{typeof title === 'string' ? title : item.key}</span>
          <span className="list-row-key break-all">{item.key}</span>
          {draft === null ? (
            <Badge variant="outline">Can&apos;t be edited here</Badge>
          ) : (
            summary(draft)
          )}
        </p>
        {draft !== null && typeof item.definition.hint === 'string' && (
          <p className="text-sm text-muted-foreground">{item.definition.hint}</p>
        )}
        {draft === null && (
          <p className="text-sm text-muted-foreground">
            It has a shape this editor does not know; it stays as it is until it is deleted.
          </p>
        )}
        <p id={reasonId} className="text-sm">
          <UsedBy paths={item.usedBy} />
        </p>
      </div>
      <ItemDialog
        gameSystemKey={gameSystemKey}
        form={form}
        version={version}
        original={item.key}
        initial={draft ?? form.blank()}
        trigger={
          <IconButton label={`Edit ${item.key}`} dense disabled={draft === null}>
            <Pencil />
          </IconButton>
        }
      />
      <DeleteItemDialog
        gameSystemKey={gameSystemKey}
        section={form.section}
        noun={form.noun}
        itemKey={item.key}
        version={version}
        usedBy={item.usedBy}
        reasonId={reasonId}
      />
    </li>
  )
}

/** Delete is refused while the draft uses the item; the button says so instead of failing. */
export function DeleteItemDialog({
  gameSystemKey,
  section,
  noun,
  itemKey,
  version,
  usedBy,
  reasonId,
}: {
  gameSystemKey: string
  section: Parameters<ReturnType<typeof useDeleteNarrativeItem>['mutate']>[0]['section']
  noun: string
  itemKey: string
  version: number
  usedBy: string[]
  reasonId: string
}) {
  const [open, setOpen] = useState(false)
  const remove = useDeleteNarrativeItem(gameSystemKey)
  const narrative = useGameSystemNarrative(gameSystemKey)
  const [reloadFailed, setReloadFailed] = useState(false)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        remove.reset()
        setReloadFailed(false)
      }}
    >
      <DialogTrigger asChild>
        <IconButton
          label={`Delete ${itemKey}`}
          dense
          destructive
          disabled={usedBy.length > 0}
          aria-describedby={reasonId}
        >
          <Trash2 />
        </IconButton>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete {itemKey}?</DialogTitle>
          <DialogDescription>The {noun} is removed from the draft.</DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {remove.error.message}
            {remove.error instanceof NarrativeError && remove.error.paths.length > 0
              ? ` Used by ${remove.error.paths.join(', ')}.`
              : ''}
          </p>
        )}
        {reloadFailed && (
          <p role="alert" className="text-sm text-destructive">
            Couldn&apos;t reload the latest version. Try again.
          </p>
        )}
        {remove.error instanceof StudioError && remove.error.status === 409 ? (
          <Button
            onClick={() => {
              void narrative.refetch().then((result) => {
                setReloadFailed(result.isError)
                if (!result.isError) {
                  setOpen(false)
                }
              })
            }}
          >
            Reload the latest version
          </Button>
        ) : (
          <Button
            variant="destructive"
            disabled={remove.isPending}
            onClick={() => {
              remove.mutate(
                { expectedVersion: version, section, item: itemKey },
                {
                  onSuccess: () => {
                    setOpen(false)
                  },
                },
              )
            }}
          >
            Delete the {noun}
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}
