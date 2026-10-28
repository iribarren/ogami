import { markedLabels, valueOrDefault } from '@/shared/sheet/sheetValues'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import type { Character, SheetField } from './useCharacters'

/** A field's value; a derived one is the server's, or a dash when it has none. */
function FieldValue({ field, character }: { field: SheetField; character: Character }) {
  if (field.type === 'derived') {
    return String(character.derived[field.key] ?? '—')
  }
  const value = valueOrDefault(field, character.values[field.key])
  if (field.type === 'inventory') {
    // Its read-only view lands with play-items-economy slice 4.
    return String(Array.isArray(value) ? value.length : 0)
  }
  if (field.type === 'conditions' && Array.isArray(value)) {
    const labels = markedLabels(
      field,
      value.filter((item) => typeof item === 'string'),
    )
    return labels.length === 0 ? 'None' : labels.join(', ')
  }
  if (Array.isArray(value)) {
    return (
      <ul>
        {value
          .filter((item) => typeof item === 'string')
          .map((item, index) => (
            <li key={`${String(index)}-${item}`}>{item}</li>
          ))}
      </ul>
    )
  }
  if (typeof value === 'object') {
    return `${String(value.current)} / ${String(value.max)}`
  }
  return String(value)
}

/** Consecutive fields with the same group, as the form lays them out. */
function blocks(sheet: SheetField[]) {
  const held: { group: string | null; fields: SheetField[] }[] = []
  for (const field of sheet) {
    const last = held.at(-1)
    if (last?.group === field.group) {
      last.fields.push(field)
    } else {
      held.push({ group: field.group, fields: [field] })
    }
  }
  return held
}

/**
 * A "View sheet" button that opens the Character's sheet read-only in a dialog, so the player
 * can look at it during play without leaving the screen they are on.
 */
export function CharacterSheetDialog({
  character,
  sheet,
}: {
  character: Character
  sheet: SheetField[]
}) {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="xs"
          aria-label={`View sheet of ${character.name}`}
        >
          View sheet
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{character.name}</DialogTitle>
          <DialogDescription>
            The sheet to read. Edit it from the Characters panel.
          </DialogDescription>
        </DialogHeader>
        {sheet.length === 0 && (
          <p className="text-muted-foreground">This release has no sheet fields.</p>
        )}
        {blocks(sheet).map(({ group, fields }) => (
          <div
            key={fields[0]?.key}
            role={group === null ? undefined : 'group'}
            aria-label={group ?? undefined}
            className="space-y-1"
          >
            {group !== null && <h3 className="text-sm font-medium">{group}</h3>}
            <dl className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-3 gap-y-1">
              {fields.map((field) => (
                <div key={field.key} className="contents">
                  <dt className="text-muted-foreground">{field.label}</dt>
                  <dd className="break-words whitespace-pre-line">
                    <FieldValue field={field} character={character} />
                  </dd>
                </div>
              ))}
            </dl>
          </div>
        ))}
      </DialogContent>
    </Dialog>
  )
}
