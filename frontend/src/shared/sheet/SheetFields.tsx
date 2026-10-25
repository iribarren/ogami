import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Checkbox } from '@/shared/ui/checkbox'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import type { DerivedResult } from './derivedValues'
import { toDraft, type Draft } from './sheetValues'
import type { SheetFieldView, SheetGroupView, SheetTemplateView } from './types'

const maxItemLength = 200

// Tailwind finds classes only as whole strings, so the grid classes are spelled out. Everything
// is one column below `sm`; the spans apply from there up.
const sheetColumns: Record<number, string> = { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2' }
const groupColumns: Record<number, string> = {
  1: 'sm:grid-cols-1',
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-3',
  4: 'sm:grid-cols-4',
}
const groupSpans: Record<number, string> = { 1: '', 2: 'sm:col-span-2' }
const fieldSpans: Record<number, string> = {
  1: '',
  2: 'sm:col-span-2',
  3: 'sm:col-span-3',
  4: 'sm:col-span-4',
}

/**
 * The fields of the sheet as form controls. With a layout, the groups in declared order as boxes
 * in a grid of the sheet's columns, each with its own grid of columns and the fields' spans; a
 * group without fields renders nothing. Without one, consecutive fields with the same group sit
 * together under the group's name. The drafts are the form's state, kept by the caller. A field
 * with an entry in `notes` is shown read-only with that text instead of a control: the server
 * sets it when the Character is created.
 */
export function SheetFields({
  template,
  drafts,
  derived,
  notes = {},
  onChange,
}: {
  template: SheetTemplateView
  drafts: Record<string, Draft>
  /** What derived fields show, by key; a field without an entry shows a dash. */
  derived: Record<string, DerivedResult>
  /** Read-only text by field key, shown in place of the field's control. */
  notes?: Record<string, string>
  onChange: (key: string, draft: Draft) => void
}) {
  const control = (field: SheetFieldView) => (
    <FieldControl
      key={field.key}
      field={field}
      draft={drafts[field.key] ?? toDraft(field, undefined)}
      derived={derived[field.key]}
      note={notes[field.key]}
      onChange={(draft) => {
        onChange(field.key, draft)
      }}
    />
  )

  if (template.layout !== null) {
    const { columns, groups } = template.layout
    return (
      <div className={`grid grid-cols-1 gap-3 ${sheetColumns[columns] ?? ''}`}>
        {groups.map((group) => (
          <GroupBox
            key={group.name}
            group={group}
            fields={template.fields.filter((field) => field.group === group.name)}
            control={control}
          />
        ))}
      </div>
    )
  }

  const blocks: { group: string | null; fields: SheetFieldView[] }[] = []
  let current: (typeof blocks)[number] | undefined
  for (const field of template.fields) {
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
        const controls = held.map(control)
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

/** One group of the layout: a box with its own columns, placed across `span` columns of the sheet. */
function GroupBox({
  group,
  fields,
  control,
}: {
  group: SheetGroupView
  fields: SheetFieldView[]
  control: (field: SheetFieldView) => React.ReactNode
}) {
  if (fields.length === 0) {
    return null
  }
  return (
    <fieldset className={`min-w-0 space-y-3 ${groupSpans[group.span] ?? ''}`}>
      <legend className="text-sm font-medium">{group.name}</legend>
      <div className={`grid grid-cols-1 gap-3 ${groupColumns[group.columns] ?? ''}`}>
        {fields.map((field) => (
          <div key={field.key} className={`min-w-0 ${fieldSpans[field.span] ?? ''}`}>
            {control(field)}
          </div>
        ))}
      </div>
    </fieldset>
  )
}

/** A number that can only be 0 or 1; a value outside that range (legacy data) stays editable as a number. */
function isToggle(field: SheetFieldView, draft: Draft): boolean {
  return (
    field.type === 'number' &&
    field.min === 0 &&
    field.max === 1 &&
    (draft === '0' || draft === '1')
  )
}

function FieldControl({
  field,
  draft,
  derived,
  note,
  onChange,
}: {
  field: SheetFieldView
  draft: Draft
  derived: DerivedResult | undefined
  note: string | undefined
  onChange: (draft: Draft) => void
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const hint = field.hint !== null && (
    <p id={hintId} className="text-xs text-muted-foreground">
      {field.hint}
    </p>
  )

  if (note !== undefined) {
    return (
      <div className="space-y-1">
        <Label htmlFor={id}>{field.label}</Label>
        <output id={id} className="block text-sm text-muted-foreground">
          {note}
        </output>
        {hint}
      </div>
    )
  }

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

  if (field.type === 'conditions' && Array.isArray(draft)) {
    // One checkbox per option; the marked keys are the draft, in the order they were ticked.
    return (
      <fieldset className="space-y-1" aria-describedby={field.hint === null ? undefined : hintId}>
        <legend className="text-sm font-medium">{field.label}</legend>
        {hint}
        {(field.options ?? []).map((option) => (
          <div key={option.key} className="flex items-center gap-2">
            <Checkbox
              id={`${id}-${option.key}`}
              checked={draft.includes(option.key)}
              onCheckedChange={(checked) => {
                onChange(
                  checked === true
                    ? [...draft.filter((key) => key !== option.key), option.key]
                    : draft.filter((key) => key !== option.key),
                )
              }}
            />
            <Label htmlFor={`${id}-${option.key}`}>{option.label}</Label>
          </div>
        ))}
      </fieldset>
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
  if (isToggle(field, draft)) {
    // A 0..1 number is a flag, such as a condition: same '0'/'1' draft as the number input.
    return (
      <div className="space-y-1">
        <div className="flex items-center gap-2">
          <Checkbox
            id={id}
            checked={draft === '1'}
            aria-describedby={describedBy}
            onCheckedChange={(checked) => {
              onChange(checked === true ? '1' : '0')
            }}
          />
          <Label htmlFor={id}>{field.label}</Label>
        </div>
        {hint}
      </div>
    )
  }
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
