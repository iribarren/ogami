import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import type { DerivedResult } from './derivedValues'
import { toDraft, type Draft } from './sheetValues'
import type { SheetField } from './useCharacters'

const maxItemLength = 200

/**
 * The fields of the sheet as form controls, consecutive fields with the same group together
 * under the group's name. The drafts are the form's state, kept by the caller.
 */
export function SheetFields({
  fields,
  drafts,
  derived,
  onChange,
}: {
  fields: SheetField[]
  drafts: Record<string, Draft>
  /** What derived fields show, by key; a field without an entry shows a dash. */
  derived: Record<string, DerivedResult>
  onChange: (key: string, draft: Draft) => void
}) {
  const blocks: { group: string | null; fields: SheetField[] }[] = []
  let current: (typeof blocks)[number] | undefined
  for (const field of fields) {
    if (current?.group === field.group) {
      current.fields.push(field)
    } else {
      current = { group: field.group, fields: [field] }
      blocks.push(current)
    }
  }

  return (
    <>
      {blocks.map(({ group, fields: held }) => {
        const controls = held.map((field) => (
          <FieldControl
            key={field.key}
            field={field}
            draft={drafts[field.key] ?? toDraft(field, undefined)}
            derived={derived[field.key]}
            onChange={(draft) => {
              onChange(field.key, draft)
            }}
          />
        ))
        return group === null ? (
          <div key={held[0]?.key} className="space-y-3">
            {controls}
          </div>
        ) : (
          <fieldset key={held[0]?.key} className="space-y-3">
            <legend className="text-sm font-medium">{group}</legend>
            {controls}
          </fieldset>
        )
      })}
    </>
  )
}

function FieldControl({
  field,
  draft,
  derived,
  onChange,
}: {
  field: SheetField
  draft: Draft
  derived: DerivedResult | undefined
  onChange: (draft: Draft) => void
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const hint = field.hint !== null && (
    <p id={hintId} className="text-xs text-muted-foreground">
      {field.hint}
    </p>
  )

  if (field.type === 'derived') {
    // Computed from the other fields: shown, never edited or submitted.
    return (
      <div className="space-y-1">
        <Label htmlFor={id}>{field.label}</Label>
        <output
          id={id}
          aria-describedby={field.hint === null ? undefined : hintId}
          className="block text-sm font-medium"
        >
          {derived !== undefined && 'value' in derived ? (
            String(derived.value)
          ) : (
            <>
              —
              <span className="text-xs font-normal text-muted-foreground">
                {' '}
                ({derived?.reason ?? 'no value'})
              </span>
            </>
          )}
        </output>
        {hint}
        <p className="text-xs text-muted-foreground">
          <code>{field.formula}</code>
        </p>
      </div>
    )
  }

  if (Array.isArray(draft)) {
    const item = field.itemLabel ?? 'Item'
    return (
      <fieldset className="space-y-1" aria-describedby={field.hint === null ? undefined : hintId}>
        <legend className="text-sm font-medium">{field.label}</legend>
        {hint}
        {draft.map((row, index) => (
          <div key={index} className="flex gap-2">
            <Input
              aria-label={`${item} ${String(index + 1)}`}
              value={row}
              maxLength={maxItemLength}
              onChange={(event) => {
                onChange(draft.map((held, at) => (at === index ? event.target.value : held)))
              }}
            />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-label={`Remove ${item} ${String(index + 1)}`}
              onClick={() => {
                onChange(draft.filter((_, at) => at !== index))
              }}
            >
              Remove
            </Button>
          </div>
        ))}
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={field.maxItems !== null && draft.length >= field.maxItems}
          onClick={() => {
            onChange([...draft, ''])
          }}
        >
          Add {item}
        </Button>
      </fieldset>
    )
  }

  if (typeof draft === 'object') {
    // A max the field fixes is shown, not edited.
    const fixed = field.max !== null
    const max = Number(draft.max)
    return (
      <fieldset className="space-y-1" aria-describedby={field.hint === null ? undefined : hintId}>
        <legend className="text-sm font-medium">{field.label}</legend>
        {hint}
        <div className="flex items-end gap-3">
          <div className="space-y-1">
            <Label htmlFor={`${id}-current`}>{field.label} current</Label>
            <Input
              id={`${id}-current`}
              type="number"
              required
              min={0}
              max={Number.isNaN(max) ? undefined : max}
              value={draft.current}
              onChange={(event) => {
                onChange({ ...draft, current: event.target.value })
              }}
            />
          </div>
          <div className="space-y-1">
            <Label htmlFor={`${id}-max`}>{field.label} max</Label>
            <Input
              id={`${id}-max`}
              type="number"
              required
              min={0}
              readOnly={fixed}
              value={draft.max}
              onChange={(event) => {
                onChange({ ...draft, max: event.target.value })
              }}
            />
          </div>
        </div>
      </fieldset>
    )
  }

  const describedBy = field.hint === null ? undefined : hintId
  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{field.label}</Label>
      {field.type === 'number' ? (
        <Input
          id={id}
          type="number"
          required
          min={field.min ?? undefined}
          max={field.max ?? undefined}
          aria-describedby={describedBy}
          value={draft}
          onChange={(event) => {
            onChange(event.target.value)
          }}
        />
      ) : field.multiline === true ? (
        <Textarea
          id={id}
          maxLength={field.maxLength ?? undefined}
          aria-describedby={describedBy}
          value={draft}
          onChange={(event) => {
            onChange(event.target.value)
          }}
        />
      ) : (
        <Input
          id={id}
          maxLength={field.maxLength ?? undefined}
          aria-describedby={describedBy}
          value={draft}
          onChange={(event) => {
            onChange(event.target.value)
          }}
        />
      )}
      {hint}
    </div>
  )
}
