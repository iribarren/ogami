import { useId, useState } from 'react'

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
import { SheetFieldDialog } from './SheetFieldDialog'
import type { LayoutDraft } from './layoutDraft'
import { SheetLayoutPanel } from './SheetLayoutPanel'
import { SheetPreview } from './SheetPreview'
import { useSheetSamples, type SampleValues, type SheetSamples } from './useSheetSamples'
import { moveTarget, readField, sheetSections, type StoredField } from './sheetDraft'
import {
  SheetError,
  useDeleteSheetField,
  useGameSystemSheet,
  useMoveSheetField,
  type GameSystemSheet,
} from './useGameSystemSheet'

/** The sheet template of a draft: its fields by group, to add, edit, move and delete. */
export function SheetTab({
  gameSystemKey,
  sampleValues,
}: {
  gameSystemKey: string
  /** The sample values the page keeps, shared with the Checks tab's sandbox. */
  sampleValues: SampleValues
}) {
  const sheet = useGameSystemSheet(gameSystemKey)

  if (sheet.data === undefined) {
    if (sheet.isPending) {
      return <p className="text-muted-foreground">Loading the sheet…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {sheet.error instanceof StudioError && sheet.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : sheet.error.message}
      </p>
    )
  }

  if (sheet.data.schemaVersion < 2) {
    return (
      <p className="text-muted-foreground">
        Schema version 1 drafts have no sheet fields: the sheet exists only in schema version 2
        drafts.
      </p>
    )
  }

  return (
    <>
      {sheet.isRefetchError && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t refresh the sheet. {sheet.error.message}
        </p>
      )}
      <SheetFields gameSystemKey={gameSystemKey} data={sheet.data} sampleValues={sampleValues} />
    </>
  )
}

function SheetFields({
  gameSystemKey,
  data,
  sampleValues,
}: {
  gameSystemKey: string
  data: GameSystemSheet
  sampleValues: SampleValues
}) {
  const move = useMoveSheetField(gameSystemKey)
  const latest = useGameSystemSheet(gameSystemKey)
  const [reloadFailed, setReloadFailed] = useState(false)
  const [layoutDraft, setLayoutDraft] = useState<LayoutDraft | null>(null)
  const samples = useSheetSamples(sampleValues, data, layoutDraft)
  const sections = sheetSections(data.fields, data.groups)

  return (
    <section aria-label="Sheet" className="space-y-4">
      <SheetLayoutPanel gameSystemKey={gameSystemKey} sheet={data} onDraftChange={setLayoutDraft} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-heading text-lg font-semibold">Sheet fields</h2>
        <SheetFieldDialog
          gameSystemKey={gameSystemKey}
          sheet={data}
          samples={samples}
          original={null}
          trigger={<Button size="sm">Add field</Button>}
        />
      </div>
      {move.error && (
        <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive">
          <p>{move.error.message}</p>
          {move.error instanceof StudioError && move.error.status === 409 && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                void latest.refetch().then((result) => {
                  setReloadFailed(result.isError)
                  if (!result.isError) {
                    move.reset()
                  }
                })
              }}
            >
              Reload the latest version
            </Button>
          )}
        </div>
      )}
      {reloadFailed && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t reload the latest version. Try again.
        </p>
      )}
      {data.fields.length === 0 && (
        <p className="text-muted-foreground">This draft has no sheet fields yet.</p>
      )}
      {sections.map((section, position) => (
        <section
          // A heading can repeat when groups are not declared, so the position is part of the key.
          key={`${String(position)}:${section.heading ?? ''}`}
          aria-label={section.heading ?? 'Fields without a group'}
          className="space-y-2"
        >
          {section.heading !== null && (
            <h3 className="font-heading font-semibold">{section.heading}</h3>
          )}
          {section.rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">No fields</p>
          ) : (
            <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
              {section.rows.map(({ field, index }) => (
                <FieldRow
                  key={typeof field.key === 'string' ? field.key : `#${String(index)}`}
                  gameSystemKey={gameSystemKey}
                  sheet={data}
                  samples={samples}
                  field={field}
                  index={index}
                  busy={move.isPending}
                  onMove={(target) => {
                    setReloadFailed(false)
                    move.mutate({
                      expectedVersion: data.version,
                      field: String(field.key),
                      index: target,
                    })
                  }}
                />
              ))}
            </ul>
          )}
        </section>
      ))}
      <SheetPreview sheet={data} samples={samples} />
    </section>
  )
}

function FieldRow({
  gameSystemKey,
  sheet,
  samples,
  field,
  index,
  busy,
  onMove,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  samples: SheetSamples
  field: StoredField
  index: number
  busy: boolean
  onMove: (target: number) => void
}) {
  const { key, label, type } = field
  if (typeof key !== 'string') {
    return (
      <li className="px-4 py-3 text-sm text-muted-foreground">
        A field without a key can&apos;t be edited here.
      </li>
    )
  }

  const editable = readField(field) !== null
  const up = moveTarget(sheet.fields, index, 'up', sheet.groups !== null)
  const down = moveTarget(sheet.fields, index, 'down', sheet.groups !== null)

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <p className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="font-medium">{typeof label === 'string' ? label : key}</span>
        <span className="font-mono text-sm break-all text-muted-foreground">{key}</span>
        <Badge variant="secondary">{typeof type === 'string' ? type : 'unknown type'}</Badge>
        {!editable && <Badge variant="outline">Can&apos;t be edited here</Badge>}
      </p>
      <SheetFieldDialog
        gameSystemKey={gameSystemKey}
        sheet={sheet}
        samples={samples}
        original={key}
        trigger={
          <Button variant="outline" size="sm" disabled={!editable} aria-label={`Edit ${key}`}>
            Edit
          </Button>
        }
      />
      <Button
        variant="outline"
        size="sm"
        aria-label={`Move up ${key}`}
        disabled={up === null || busy}
        onClick={() => {
          if (up !== null) {
            onMove(up)
          }
        }}
      >
        Move up
      </Button>
      <Button
        variant="outline"
        size="sm"
        aria-label={`Move down ${key}`}
        disabled={down === null || busy}
        onClick={() => {
          if (down !== null) {
            onMove(down)
          }
        }}
      >
        Move down
      </Button>
      <DeleteFieldDialog gameSystemKey={gameSystemKey} fieldKey={key} version={sheet.version} />
    </li>
  )
}

/** Deleting is refused while the draft reads the field; the refusal lists where. */
function DeleteFieldDialog({
  gameSystemKey,
  fieldKey,
  version,
}: {
  gameSystemKey: string
  fieldKey: string
  version: number
}) {
  const [open, setOpen] = useState(false)
  const remove = useDeleteSheetField(gameSystemKey)
  const latest = useGameSystemSheet(gameSystemKey)
  const [reloadFailed, setReloadFailed] = useState(false)
  const reasonId = useId()

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
        <Button variant="outline" size="sm" aria-label={`Delete ${fieldKey}`}>
          Delete
        </Button>
      </DialogTrigger>
      <DialogContent aria-describedby={reasonId}>
        <DialogHeader>
          <DialogTitle>Delete {fieldKey}?</DialogTitle>
          <DialogDescription id={reasonId}>
            The field is removed from the draft. Characters on campaigns that upgrade later lose its
            value if it was published.
          </DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {remove.error.message}
            {remove.error instanceof SheetError && remove.error.paths.length > 0
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
              void latest.refetch().then((result) => {
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
                { expectedVersion: version, field: fieldKey },
                {
                  onSuccess: () => {
                    setOpen(false)
                  },
                },
              )
            }}
          >
            Delete the field
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}
