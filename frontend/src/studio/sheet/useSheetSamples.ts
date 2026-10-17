import { useMemo, useState } from 'react'

import { integer } from '@/shared/sheet/derivedValues'
import { toDraft, type Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, SheetTemplateView, TrackerReading } from '@/shared/sheet/types'

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

/** The sample values of the Sheet tab, shared by the preview and the formula inputs. */
export interface SheetSamples {
  /** The sheet as the preview shows it, with the layout being edited. */
  template: SheetTemplateView
  /** One draft per field of the template: its sample value, or the empty one. */
  drafts: Record<string, Draft>
  /** What the sample Tracker inputs hold, as typed. */
  trackerSamples: Record<string, string>
  /** The Trackers that have a sample reading: a value that is not a whole number has none. */
  readings: TrackerReading[]
  setSample: (key: string, draft: Draft) => void
  setTrackerSample: (key: string, value: string) => void
}

/**
 * The sample field values and sample Tracker values of the sheet, never saved. A sample is
 * dropped when its field is gone or changed type.
 */
export function useSheetSamples(
  sheet: GameSystemSheet,
  layoutDraft: LayoutDraft | null,
): SheetSamples {
  const template = useMemo(() => previewTemplate(sheet, layoutDraft), [sheet, layoutDraft])
  const [samples, setSamples] = useState<Record<string, Sample>>({})
  const [trackerSamples, setTrackerSamples] = useState<Record<string, string>>({})

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

  return {
    template,
    drafts,
    trackerSamples,
    readings,
    setSample: (key, draft) => {
      const field = template.fields.find((held) => held.key === key)
      if (field !== undefined) {
        setSamples({ ...kept, [key]: { type: field.type, draft } })
      }
    },
    setTrackerSample: (key, value) => {
      setTrackerSamples({ ...trackerSamples, [key]: value })
    },
  }
}
