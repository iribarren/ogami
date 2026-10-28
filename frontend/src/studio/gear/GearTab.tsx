import { Backpack, Pencil, Plus, Trash2 } from 'lucide-react'
import { useId, useState, type SubmitEvent } from 'react'

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

import { TextField } from '../narrative/TextField'
import { ReloadLatest } from '../sheet/SheetLayoutPanel'
import { gearAnswer } from '../sheet/sheetError'
import {
  isVersionConflict,
  SheetError,
  useGameSystemSheet,
  useRemoveGearEntry,
  useSaveGearEntry,
  type GameSystemSheet,
} from '../sheet/useGameSystemSheet'
import { StudioError } from '../useGameSystems'
import {
  readGear,
  withName,
  writeGear,
  type GearDraft,
  type GearEntry,
  type GearErrors,
} from './gearDraft'

/**
 * The gear catalog of a draft (ADR 0045): what shops sell and inventories hold. Add, edit or
 * delete an entry; the publisher checks it when you save it, and an entry a shop offers cannot
 * be deleted.
 */
export function GearTab({ gameSystemKey }: { gameSystemKey: string }) {
  const sheet = useGameSystemSheet(gameSystemKey)

  if (sheet.data === undefined) {
    if (sheet.isPending) {
      return <p className="text-muted-foreground">Loading the gear…</p>
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
        Schema version 1 drafts have no gear: the catalog exists only in schema version 2 drafts.
      </p>
    )
  }

  return (
    <>
      {sheet.isRefetchError && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t refresh the gear. {sheet.error.message}
        </p>
      )}
      <GearCatalog gameSystemKey={gameSystemKey} sheet={sheet.data} />
    </>
  )
}

function GearCatalog({ gameSystemKey, sheet }: { gameSystemKey: string; sheet: GameSystemSheet }) {
  return (
    <section aria-label="Gear" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <Backpack />
        </IconTile>
        <h2 className="font-heading text-panel-title">Gear</h2>
        <div className="ml-auto">
          <GearDialog gameSystemKey={gameSystemKey} sheet={sheet} entry={null} />
        </div>
      </div>
      <div className="space-y-3 panel-body">
        {sheet.currency === null && (
          <p className="hint">
            The sheet has no currency yet: costs have no unit. Set one in the Sheet section.
          </p>
        )}
        {sheet.gear.length === 0 ? (
          <div className="empty-state">
            <IconTile size="sm">
              <Backpack />
            </IconTile>
            <p>This draft has no gear yet.</p>
            <p className="hint">Add the entries shops sell and inventories hold.</p>
          </div>
        ) : (
          <ul className="overflow-hidden rounded-lg border">
            {sheet.gear.map((entry) => (
              <li key={entry.key} className="list-row">
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="list-row-title">{entry.name}</span>
                    <span className="list-row-key break-all">{entry.key}</span>
                    {entry.category !== null && <Badge variant="secondary">{entry.category}</Badge>}
                  </p>
                  <p className="hint">
                    Cost {entry.cost}
                    {sheet.currency?.unit ? ` ${sheet.currency.unit}` : ''} · {entry.slots}{' '}
                    {entry.slots === 1 ? 'slot' : 'slots'}
                  </p>
                </div>
                <GearDialog gameSystemKey={gameSystemKey} sheet={sheet} entry={entry} />
                <DeleteGearDialog
                  gameSystemKey={gameSystemKey}
                  entry={entry}
                  version={sheet.version}
                />
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

/** The Add button or an entry's Edit button, and its dialog. Failing keeps it open with what was typed. */
function GearDialog({
  gameSystemKey,
  sheet,
  entry,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  entry: GearEntry | null
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveGearEntry(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>
        {entry === null ? (
          <Button variant="outline" size="sm">
            <Plus aria-hidden="true" />
            Add gear
          </Button>
        ) : (
          <IconButton label={`Edit ${entry.key}`} dense>
            <Pencil />
          </IconButton>
        )}
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{entry === null ? 'New gear' : `Edit ${entry.key}`}</DialogTitle>
          <DialogDescription>
            The publisher checks the entry when you save it; nothing is saved if it refuses.
          </DialogDescription>
        </DialogHeader>
        <GearForm
          gameSystemKey={gameSystemKey}
          sheet={sheet}
          entry={entry}
          save={save}
          onSaved={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function GearForm({
  gameSystemKey,
  sheet,
  entry,
  save,
  onSaved,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  entry: GearEntry | null
  save: ReturnType<typeof useSaveGearEntry>
  onSaved: () => void
}) {
  // Filled once, when the dialog opens: a refetch while it is open must not overwrite what was typed.
  const [draft, setDraft] = useState(() => readGear(entry))
  const [errors, setErrors] = useState<GearErrors>({})
  // The draft the last save sent: a 422 answer says nothing about a draft that has changed since.
  const [sent, setSent] = useState<GearDraft | null>(null)
  const { error } = save

  // The path indexes the catalog after the write: an edit keeps its position, an add is the last.
  const position =
    entry === null ? sheet.gear.length : sheet.gear.findIndex(({ key }) => key === entry.key)
  const refused = error instanceof SheetError && error.status === 422
  const answer = refused && sent === draft ? gearAnswer(error.message, position) : null
  const inputs: GearErrors = { ...answer?.inputs, ...errors }
  const message = answer ? answer.form : refused ? null : (error?.message ?? null)
  const change = (changes: Partial<GearDraft>) => {
    setDraft({ ...draft, ...changes })
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    // An edit keeps its own key; an add must not replace an entry the keyed PUT would overwrite.
    const result = writeGear(draft, entry === null ? sheet.gear.map(({ key }) => key) : [])
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    setSent(draft)
    save.mutate(
      { expectedVersion: sheet.version, gear: draft.key, request: result.request },
      { onSuccess: onSaved },
    )
  }

  return (
    <form
      aria-label={entry === null ? 'New gear' : `Edit ${entry.key}`}
      className="space-y-4"
      onSubmit={handleSubmit}
    >
      <TextField
        label="Name"
        value={draft.name}
        onChange={(name) => {
          setDraft(withName(draft, name))
        }}
        error={inputs.name}
      />
      {entry === null ? (
        <TextField
          label="Key"
          keyField
          value={draft.key}
          onChange={(key) => {
            change({ key, follow: false })
          }}
          error={inputs.key}
        />
      ) : (
        <div className="space-y-2">
          <p className="text-sm font-medium">Key</p>
          <p className="list-row-key">{draft.key}</p>
          <p className="hint">The key never changes once the entry is saved.</p>
        </div>
      )}
      <TextField
        label="Cost"
        value={draft.cost}
        onChange={(cost) => {
          change({ cost })
        }}
        error={inputs.cost}
      />
      <TextField
        label="Slots"
        value={draft.slots}
        onChange={(slots) => {
          change({ slots })
        }}
        error={inputs.slots}
        help="Inventory slots one unit takes. Empty means 1."
      />
      <TextField
        label="Category"
        value={draft.category}
        onChange={(category) => {
          change({ category })
        }}
        error={inputs.category}
        help="Inventories and shops select gear by category, e.g. Weapons."
      />
      <TextField
        label="Description"
        value={draft.description}
        onChange={(description) => {
          change({ description })
        }}
        error={inputs.description}
      />
      {message !== null && message !== '' && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Button type="submit" disabled={save.isPending}>
          Save gear
        </Button>
        {isVersionConflict(error) && (
          <ReloadLatest gameSystemKey={gameSystemKey} onLoaded={save.reset} />
        )}
      </div>
    </form>
  )
}

/** Deleting is refused while a shop offers the entry; the refusal lists where. */
function DeleteGearDialog({
  gameSystemKey,
  entry,
  version,
}: {
  gameSystemKey: string
  entry: GearEntry
  version: number
}) {
  const [open, setOpen] = useState(false)
  const remove = useRemoveGearEntry(gameSystemKey)
  const reasonId = useId()
  const stale = isVersionConflict(remove.error)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        remove.reset()
      }}
    >
      <DialogTrigger asChild>
        <IconButton label={`Delete ${entry.key}`} dense destructive>
          <Trash2 />
        </IconButton>
      </DialogTrigger>
      <DialogContent aria-describedby={reasonId}>
        <DialogHeader>
          <DialogTitle>Delete {entry.key}?</DialogTitle>
          <DialogDescription id={reasonId}>
            The entry is removed from the catalog. It cannot be deleted while a shop step offers it.
          </DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {remove.error.message}
          </p>
        )}
        {stale ? (
          <ReloadLatest
            gameSystemKey={gameSystemKey}
            onLoaded={() => {
              setOpen(false)
            }}
          />
        ) : (
          <Button
            variant="destructive"
            disabled={remove.isPending}
            onClick={() => {
              remove.mutate(
                { expectedVersion: version, gear: entry.key },
                {
                  onSuccess: () => {
                    setOpen(false)
                  },
                },
              )
            }}
          >
            Delete the gear
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}
