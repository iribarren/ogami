import {
  ArrowDown,
  ArrowUp,
  Hash,
  List,
  ListChecks,
  Pencil,
  Plus,
  Sigma,
  Trash2,
  Type,
  UserRound,
} from 'lucide-react'
import { useId, useState, type ReactNode } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'
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
import { SheetPointBudgetPanel } from './SheetPointBudgetPanel'
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
      <div className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <UserRound />
          </IconTile>
          <h2 className="font-heading text-panel-title">Sheet fields</h2>
          <div className="ml-auto">
            <SheetFieldDialog
              gameSystemKey={gameSystemKey}
              sheet={data}
              samples={samples}
              original={null}
              trigger={
                <Button variant="outline" size="sm">
                  <Plus aria-hidden="true" />
                  Add field
                </Button>
              }
            />
          </div>
        </div>
        <SheetLayoutPanel
          gameSystemKey={gameSystemKey}
          sheet={data}
          onDraftChange={setLayoutDraft}
        />
        <div className="space-y-4 panel-body">
          {move.error && (
            <div
              role="alert"
              className="flex flex-wrap items-center gap-3 text-sm text-destructive"
            >
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
            <div className="empty-state">
              <IconTile size="sm">
                <UserRound />
              </IconTile>
              <p>This draft has no sheet fields yet.</p>
              <p className="hint">Add a field to start the sheet.</p>
            </div>
          )}
          {sections.map((section, position) => (
            <section
              // A heading can repeat when groups are not declared, so the position is part of the key.
              key={`${String(position)}:${section.heading ?? ''}`}
              aria-label={section.heading ?? 'Fields without a group'}
              className="space-y-2"
            >
              {section.heading !== null && <h3 className="group-label">{section.heading}</h3>}
              {section.rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">No fields</p>
              ) : (
                <ul className="overflow-hidden rounded-lg border">
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
        </div>
      </div>
      <SheetPointBudgetPanel gameSystemKey={gameSystemKey} sheet={data} />
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
  const hint = rangeHint(field) ?? optionsHint(field)
  const up = moveTarget(sheet.fields, index, 'up', sheet.groups !== null)
  const down = moveTarget(sheet.fields, index, 'down', sheet.groups !== null)

  return (
    <li className="list-row">
      <IconTile size="xs">{typeIcons[typeof type === 'string' ? type : ''] ?? <Type />}</IconTile>
      <div className="min-w-0 flex-1">
        <p className="flex flex-wrap items-baseline gap-x-2">
          <span className="list-row-title">{typeof label === 'string' ? label : key}</span>
          <span className="list-row-key break-all">{key}</span>
          <Badge variant="secondary">{typeof type === 'string' ? type : 'unknown type'}</Badge>
          {!editable && <Badge variant="outline">Can&apos;t be edited here</Badge>}
        </p>
        {hint !== null && <p className="hint">{hint}</p>}
      </div>
      <SheetFieldDialog
        gameSystemKey={gameSystemKey}
        sheet={sheet}
        samples={samples}
        original={key}
        trigger={
          <IconButton label={`Edit ${key}`} dense disabled={!editable}>
            <Pencil />
          </IconButton>
        }
      />
      <IconButton
        label={`Move up ${key}`}
        dense
        disabled={up === null || busy}
        onClick={() => {
          if (up !== null) {
            onMove(up)
          }
        }}
      >
        <ArrowUp />
      </IconButton>
      <IconButton
        label={`Move down ${key}`}
        dense
        disabled={down === null || busy}
        onClick={() => {
          if (down !== null) {
            onMove(down)
          }
        }}
      >
        <ArrowDown />
      </IconButton>
      <DeleteFieldDialog gameSystemKey={gameSystemKey} fieldKey={key} version={sheet.version} />
    </li>
  )
}

const typeIcons: Record<string, ReactNode> = {
  text: <Type />,
  number: <Hash />,
  derived: <Sigma />,
  list: <List />,
  conditions: <ListChecks />,
}

/** "Hurt, Exhausted" for a conditions field: its options in order, those that are not labelled left out. */
function optionsHint({ type, options }: StoredField): string | null {
  if (type !== 'conditions' || !Array.isArray(options)) {
    return null
  }
  const labels = (options as unknown[]).flatMap((option) =>
    typeof option === 'object' &&
    option !== null &&
    typeof (option as Record<string, unknown>).label === 'string'
      ? [(option as Record<string, string>).label]
      : [],
  )
  return labels.length > 0 ? `Options: ${labels.join(', ')}` : null
}

/** "−1 to 3" for a field with bounds; legacy fields without min or max say nothing. */
function rangeHint({ min, max }: StoredField): string | null {
  const show = (bound: number) => String(bound).replace('-', '−')
  if (typeof min === 'number' && typeof max === 'number') {
    return `${show(min)} to ${show(max)}`
  }
  if (typeof min === 'number') {
    return `${show(min)} or more`
  }
  return typeof max === 'number' ? `up to ${show(max)}` : null
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
        <IconButton label={`Delete ${fieldKey}`} dense destructive>
          <Trash2 />
        </IconButton>
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
