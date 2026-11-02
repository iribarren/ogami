import { ClipboardPaste } from 'lucide-react'
import { useId, useMemo, useState } from 'react'

import { FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { IconTile } from '@/shared/ui/icon-tile'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { TextField } from '../narrative/TextField'
import { SelectField } from './SheetFieldDialog'
import { parseFieldPaste, pastedField, pasteTypes, type PasteSettings } from './pasteFields'
import {
  isVersionConflict,
  useGameSystemSheet,
  useSaveSheetField,
  type GameSystemSheet,
} from './useGameSystemSheet'

const typeLabels = { number: 'Number', text: 'Text', list: 'List' }

/** How a run ended: what was added, and why it stopped when it did. */
interface Outcome {
  added: number
  total: number
  stopped: { name: string; reason: string; conflict: boolean } | null
}

/**
 * Adds several fields at once: one name per line, one set of settings for all. The fields go one
 * by one through the same request as the New field dialog, each with the version the last
 * answer returned. A failure stops the run and says how many were added; the lines not added
 * stay in the box, so a retry adds only those.
 */
export function PasteFieldsDialog({
  gameSystemKey,
  sheet,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
}) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [settings, setSettings] = useState<PasteSettings>(() => blank(sheet))
  const [running, setRunning] = useState(false)
  const [outcome, setOutcome] = useState<Outcome | null>(null)
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  const textId = useId()
  const save = useSaveSheetField(gameSystemKey)
  const latest = useGameSystemSheet(gameSystemKey)

  const lines = useMemo(
    () =>
      parseFieldPaste(
        text,
        sheet.fields.flatMap((field) => (typeof field.key === 'string' ? [field.key] : [])),
      ),
    [text, sheet.fields],
  )
  // The settings are checked on a sample line: what they refuse, every pasted field would be refused.
  const sample = pastedField({ name: 'Sample', key: 'sample' }, settings, sheet.groups)
  const settingsErrors = 'errors' in sample ? sample.errors : {}
  const blocked = lines.some(({ error }) => error !== null) || 'errors' in sample
  const count = lines.length

  function change(next: boolean) {
    if (running) {
      return
    }
    setOpen(next)
    if (!next) {
      setText('')
      setSettings(blank(sheet))
      setOutcome(null)
      setReloadNote(null)
      save.reset()
    }
  }

  async function run() {
    setRunning(true)
    setOutcome(null)
    setReloadNote(null)
    let version = sheet.version
    let added = 0
    let stopped: Outcome['stopped'] = null
    for (const line of lines) {
      const result = pastedField(line, settings, sheet.groups)
      if ('errors' in result) {
        stopped = { name: line.name, reason: 'The settings are not valid.', conflict: false }
        break
      }
      try {
        const next = await save.mutateAsync({
          expectedVersion: version,
          original: null,
          field: result.field,
        })
        version = next.version
        added += 1
      } catch (error) {
        const reason = error instanceof Error ? error.message : 'The request failed.'
        stopped = { name: line.name, reason, conflict: isVersionConflict(error) }
        // A request that got no answer may have landed: read the sheet again so a retry sees it.
        if (!(error instanceof Error && 'status' in error)) {
          void latest.refetch()
        }
        break
      }
    }
    // What was added leaves the box; what was not stays, as pasted.
    setText(
      lines
        .slice(added)
        .map(({ name }) => name)
        .join('\n'),
    )
    setOutcome({ added, total: lines.length, stopped })
    setRunning(false)
  }

  function reload() {
    void latest.refetch().then((result) => {
      if (result.isError) {
        setReloadNote("Couldn't reload the latest version. Try again.")
        return
      }
      setReloadNote(null)
      setOutcome(null)
    })
  }

  return (
    <Dialog open={open} onOpenChange={change}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm">
          <ClipboardPaste aria-hidden="true" />
          Paste fields
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <IconTile size="lg">
            <ClipboardPaste />
          </IconTile>
          <DialogTitle>Paste fields</DialogTitle>
          <DialogDescription>
            One field name per line, blank lines ignored. Each name is the field&apos;s label and
            its key follows it; the settings below are the same for every field. The fields are
            added one by one: if one is refused, the run stops and the lines not added stay here.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-1">
          <Label htmlFor={textId}>Field names to paste</Label>
          <Textarea
            id={textId}
            rows={8}
            className="font-mono"
            value={text}
            disabled={running}
            onChange={(event) => {
              setText(event.target.value)
            }}
          />
        </div>
        <SelectField
          label="Type"
          value={settings.type}
          onChange={(type) => {
            setSettings({ ...settings, type: type as PasteSettings['type'] })
          }}
          options={pasteTypes.map((type) => ({ value: type, label: typeLabels[type] }))}
        />
        {sheet.groups === null ? (
          <TextField
            label="Group"
            value={settings.group}
            onChange={(group) => {
              setSettings({ ...settings, group })
            }}
            error={settingsErrors.group}
          />
        ) : (
          <SelectField
            label="Group"
            value={settings.group}
            onChange={(group) => {
              setSettings({ ...settings, group })
            }}
            options={sheet.groups.map(({ name }) => ({ value: name, label: name }))}
            error={settingsErrors.group}
          />
        )}
        {settings.type === 'number' && (
          <div className="grid grid-cols-2 gap-3">
            <TextField
              label="Min"
              value={settings.min}
              onChange={(min) => {
                setSettings({ ...settings, min })
              }}
              error={settingsErrors.min}
            />
            <TextField
              label="Max"
              value={settings.max}
              onChange={(max) => {
                setSettings({ ...settings, max })
              }}
              error={settingsErrors.max}
            />
          </div>
        )}
        {count > 0 && (
          <ul aria-label="Fields to add" className="space-y-1 text-sm">
            {lines.map(({ line, name, key, error }) => (
              <li key={line}>
                <span className="text-muted-foreground">Line {line}: </span>
                {name} <span className="list-row-key break-all">{key}</span>
                {error !== null && <FieldError>{error}</FieldError>}
              </li>
            ))}
          </ul>
        )}
        {outcome !== null && (
          <div role={outcome.stopped === null ? 'status' : 'alert'} className="space-y-1 text-sm">
            <p>
              Added {outcome.added} of {outcome.total} {outcome.total === 1 ? 'field' : 'fields'}.
            </p>
            {outcome.stopped !== null && (
              <p className="text-destructive">
                Stopped at “{outcome.stopped.name}”: {outcome.stopped.reason} The{' '}
                {outcome.total - outcome.added === 1
                  ? 'line'
                  : `${String(outcome.total - outcome.added)} lines`}{' '}
                not added {outcome.total - outcome.added === 1 ? 'stays' : 'stay'} above.
              </p>
            )}
          </div>
        )}
        {reloadNote !== null && (
          <p role="alert" className="text-sm text-destructive">
            {reloadNote}
          </p>
        )}
        <div className="flex flex-wrap justify-end gap-3">
          {outcome?.stopped?.conflict === true && (
            <Button type="button" variant="outline" onClick={reload}>
              Reload the latest version
            </Button>
          )}
          <DialogClose asChild>
            <Button type="button" variant="ghost" disabled={running}>
              Close
            </Button>
          </DialogClose>
          <Button
            type="button"
            disabled={count === 0 || blocked || running}
            onClick={() => {
              void run()
            }}
          >
            Add {count} {count === 1 ? 'field' : 'fields'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

const blank = (sheet: GameSystemSheet): PasteSettings => ({
  type: 'number',
  group: sheet.groups?.[0]?.name ?? '',
  min: '',
  max: '',
})
