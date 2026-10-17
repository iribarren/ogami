import { useId, useMemo, useState } from 'react'

import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { computeDerived, integer } from '@/shared/sheet/derivedValues'
import { SheetFields } from '@/shared/sheet/SheetFields'
import { toDraft, type Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, TrackerReading } from '@/shared/sheet/types'

import type { LayoutDraft } from './layoutDraft'
import { previewTemplate } from './previewTemplate'
import type { GameSystemSheet } from './useGameSystemSheet'

/** A sample value, with the type it was typed for: another type starts it again. */
interface Sample {
  type: SheetFieldView['type']
  draft: Draft
}

/** Without the samples of fields that are gone or changed type; the same object when none is. */
function pruned(samples: Record<string, Sample>, fields: SheetFieldView[]) {
  const kept = Object.entries(samples).filter(([key, sample]) =>
    fields.some((field) => field.key === key && field.type === sample.type),
  )
  return kept.length === Object.keys(samples).length ? samples : Object.fromEntries(kept)
}

/**
 * The sheet as a player will see it, with the shared renderer and derived values. Sample values
 * and sample Trackers are typed here and never saved. `layoutDraft` is the layout panel's unsaved
 * draft: while it is valid it is shown instead of the saved layout.
 */
export function SheetPreview({
  sheet,
  layoutDraft,
}: {
  sheet: GameSystemSheet
  layoutDraft: LayoutDraft | null
}) {
  const template = useMemo(() => previewTemplate(sheet, layoutDraft), [sheet, layoutDraft])
  const [samples, setSamples] = useState<Record<string, Sample>>({})
  const [trackerSamples, setTrackerSamples] = useState<Record<string, string>>({})
  const headingId = useId()

  const kept = pruned(samples, template.fields)
  if (kept !== samples) {
    setSamples(kept)
  }
  const drafts = Object.fromEntries(
    template.fields.map((field) => [
      field.key,
      kept[field.key]?.draft ?? toDraft(field, undefined),
    ]),
  )

  // A Tracker whose sample is not a whole number has no reading: a derived field that reads it
  // says its value is incomplete.
  const readings = sheet.trackers.flatMap(({ key }): TrackerReading[] => {
    const value = integer(trackerSamples[key] ?? '0')
    return value === undefined ? [] : [{ key, value }]
  })
  const derived = computeDerived(template.fields, drafts, readings)

  return (
    <section
      aria-labelledby={headingId}
      className="space-y-3 rounded-xl p-4 ring-1 ring-foreground/10"
    >
      <h2 id={headingId} className="font-heading text-lg font-semibold">
        Preview
      </h2>
      <p className="text-sm text-muted-foreground">
        The sheet as a player sees it, with the layout you are editing. Values here are samples:
        they are never saved.
      </p>
      {template.fields.length === 0 ? (
        <p className="text-sm text-muted-foreground">Add a field to preview the sheet.</p>
      ) : (
        <SheetFields
          template={template}
          drafts={drafts}
          derived={derived}
          onChange={(key, draft) => {
            const field = template.fields.find((held) => held.key === key)
            if (field !== undefined) {
              setSamples({ ...kept, [key]: { type: field.type, draft } })
            }
          }}
        />
      )}
      {sheet.trackers.length > 0 && (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Sample Trackers</legend>
          <div className="flex flex-wrap gap-3">
            {sheet.trackers.map(({ key, label }) => (
              <TrackerSample
                key={key}
                label={label}
                value={trackerSamples[key] ?? '0'}
                onChange={(value) => {
                  setTrackerSamples({ ...trackerSamples, [key]: value })
                }}
              />
            ))}
          </div>
        </fieldset>
      )}
    </section>
  )
}

function TrackerSample({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (value: string) => void
}) {
  const id = useId()

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="number"
        className="w-28"
        value={value}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
    </div>
  )
}
