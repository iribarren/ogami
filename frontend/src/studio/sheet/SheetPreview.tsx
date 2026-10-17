import { useId } from 'react'

import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { computeDerived } from '@/shared/sheet/derivedValues'
import { SheetFields } from '@/shared/sheet/SheetFields'

import type { GameSystemSheet } from './useGameSystemSheet'
import type { SheetSamples } from './useSheetSamples'

/**
 * The sheet as a player will see it, with the shared renderer and derived values. Sample values
 * and sample Trackers are typed here and never saved; they live in `samples`, which the Sheet tab
 * shares with the formula inputs. The template shows the layout panel's unsaved draft while it is
 * valid, instead of the saved layout.
 */
export function SheetPreview({
  sheet,
  samples,
}: {
  sheet: GameSystemSheet
  samples: SheetSamples
}) {
  const { template, drafts, trackerSamples, readings } = samples
  const headingId = useId()
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
          onChange={samples.setSample}
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
                  samples.setTrackerSample(key, value)
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
