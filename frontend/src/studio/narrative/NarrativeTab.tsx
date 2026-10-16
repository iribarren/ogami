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
    <section aria-label="Trackers & tags" className="space-y-8">
      <ItemSection
        gameSystemKey={gameSystemKey}
        narrative={data}
        title="Trackers"
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
      <ItemSection
        gameSystemKey={gameSystemKey}
        narrative={data}
        title="Tags"
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
        items={data.factSlots}
        form={factSlotForm}
        titleField="label"
        empty="This draft has no fact slots yet."
        summary={(draft: LabelledDraft) => <Badge variant="secondary">{draft.type}</Badge>}
      />
    </section>
  )
}

function ItemSection<Draft>({
  gameSystemKey,
  narrative,
  title,
  items,
  form,
  titleField,
  empty,
  summary,
}: {
  gameSystemKey: string
  narrative: GameSystemNarrative
  title: string
  items: NarrativeItem[]
  form: ItemForm<Draft>
  /** The stored field that names the item in its row. */
  titleField: 'name' | 'label'
  empty: string
  summary: (draft: Draft) => ReactNode
}) {
  return (
    <section aria-label={title} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-heading text-lg font-semibold">{title}</h2>
        <ItemDialog
          gameSystemKey={gameSystemKey}
          form={form}
          version={narrative.version}
          original={null}
          initial={form.blank()}
          trigger={<Button size="sm">New {form.noun}</Button>}
        />
      </div>
      {items.length === 0 ? (
        <p className="text-muted-foreground">{empty}</p>
      ) : (
        <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
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
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="font-medium">{typeof title === 'string' ? title : item.key}</span>
          <span className="font-mono text-sm break-all text-muted-foreground">{item.key}</span>
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
          <Button
            variant="outline"
            size="sm"
            disabled={draft === null}
            aria-label={`Edit ${item.key}`}
          >
            Edit
          </Button>
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
        <Button
          variant="outline"
          size="sm"
          disabled={usedBy.length > 0}
          aria-describedby={reasonId}
          aria-label={`Delete ${itemKey}`}
        >
          Delete
        </Button>
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
