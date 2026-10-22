import { useId, useMemo, useState } from 'react'
import { ClipboardPaste } from 'lucide-react'

import { Button } from '@/shared/ui/button'
import { FieldError } from '@/shared/ui/alert'
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

import { parsePaste, type PasteMode, type PastedEntry } from './pasteEntries'
import type { TableDraft } from './tableDraft'

/**
 * Pastes entries as text, one per line. Nothing is applied until "Use N entries", and a line the
 * parser cannot read keeps the button disabled and names the line: the draft never changes by a guess.
 */
export function PasteEntriesDialog({
  draft,
  onApply,
}: {
  draft: TableDraft
  onApply: (entries: PastedEntry[], mode: PasteMode) => void
}) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [mode, setMode] = useState<PasteMode>('append')
  const textId = useId()
  const errorsId = useId()
  const { entries, errors } = useMemo(() => parsePaste(draft, text, mode), [draft, text, mode])

  function change(next: boolean) {
    setOpen(next)
    if (!next) {
      setText('')
      setMode('append')
    }
  }

  return (
    <Dialog open={open} onOpenChange={change}>
      <DialogTrigger asChild>
        <Button type="button" variant="ghost" size="sm">
          <ClipboardPaste aria-hidden="true" />
          Paste entries
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <IconTile size="lg">
            <ClipboardPaste />
          </IconTile>
          <DialogTitle>Paste entries</DialogTitle>
          <DialogDescription>
            One entry per line; blank lines are ignored.
            {draft.ranged
              ? ' A leading number or range (3, 4-6, 4–6, then a space, ".", ":" or ")") sets the range; other lines follow on from the previous one.'
              : ' Every entry gets weight 1, and a leading number stays part of the text.'}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-1">
          <Label htmlFor={textId}>Entries to paste</Label>
          <Textarea
            id={textId}
            rows={8}
            className="font-mono"
            value={text}
            aria-invalid={errors.length > 0}
            aria-describedby={errors.length > 0 ? errorsId : undefined}
            onChange={(event) => {
              setText(event.target.value)
            }}
          />
          {errors.length > 0 && (
            <ul id={errorsId} role="alert" className="space-y-1">
              {errors.map(({ line, message }) => (
                <li key={line}>
                  <FieldError>{message}</FieldError>
                </li>
              ))}
            </ul>
          )}
        </div>
        <fieldset className="space-y-1">
          <legend className="text-sm font-medium">Where the entries go</legend>
          {(
            [
              ['append', 'Add after the last'],
              ['replace', 'Replace all'],
            ] as const
          ).map(([value, label]) => (
            <label key={value} className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name="paste-mode"
                checked={mode === value}
                onChange={() => {
                  setMode(value)
                }}
              />
              {label}
            </label>
          ))}
        </fieldset>
        <div className="flex justify-end gap-3">
          <DialogClose asChild>
            <Button type="button" variant="ghost">
              Cancel
            </Button>
          </DialogClose>
          <Button
            type="button"
            disabled={entries.length === 0}
            onClick={() => {
              onApply(entries, mode)
              change(false)
            }}
          >
            Use {entries.length} {entries.length === 1 ? 'entry' : 'entries'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
