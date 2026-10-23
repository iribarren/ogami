import { useCallback, useEffect, useMemo, useState } from 'react'

import { integer } from '@/shared/sheet/derivedValues'
import { toDraft, type Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, SheetTemplateView, TrackerReading } from '@/shared/sheet/types'

import { sampleDefaults } from '../checks/checkSandbox'
import type { LayoutDraft } from './layoutDraft'
import { previewTemplate } from './previewTemplate'
import type { GameSystemSheet } from './useGameSystemSheet'

/** A sample value, with the type it was typed for: another type starts it again. */
export interface Sample {
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
 * The sample values the Sheet tab's preview and the Checks tab's sandbox share, never saved: what
 * was typed for each field (with its type) and for each Tracker, as typed. The GameSystem page
 * owns them so a value typed in one tab is there in the other.
 */
export interface SampleValues {
  /** What was typed for a field, by its key; a field nobody typed for has none. */
  samples: Record<string, Sample>
  /** What was typed for a Tracker, by its key, as typed. */
  trackerSamples: Record<string, string>
  setSample: (key: string, sample: Sample) => void
  setTrackerSample: (key: string, value: string) => void
  /** Drops the samples of fields that are not in `fields` or have another type there. */
  keepOnly: (fields: SheetFieldView[]) => void
}

/** The page-level owner of the sample values; the tabs derive what they show from it. */
export function useSampleValues(): SampleValues {
  const [samples, setSamples] = useState<Record<string, Sample>>({})
  const [trackerSamples, setTrackerSamples] = useState<Record<string, string>>({})

  const setSample = useCallback((key: string, sample: Sample) => {
    setSamples((held) => ({ ...held, [key]: sample }))
  }, [])
  const setTrackerSample = useCallback((key: string, value: string) => {
    setTrackerSamples((held) => ({ ...held, [key]: value }))
  }, [])
  const keepOnly = useCallback((fields: SheetFieldView[]) => {
    setSamples((held) => pruned(held, fields))
  }, [])

  return { samples, trackerSamples, setSample, setTrackerSample, keepOnly }
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
 * What the Sheet tab derives from the shared sample values: the template with the layout being
 * edited, one draft per field (an untyped number at the sandbox default), the Tracker readings. A sample is dropped when its field is gone
 * or changed type.
 */
export function useSheetSamples(
  values: SampleValues,
  sheet: GameSystemSheet,
  layoutDraft: LayoutDraft | null,
): SheetSamples {
  const template = useMemo(() => previewTemplate(sheet, layoutDraft), [sheet, layoutDraft])
  const { samples, trackerSamples, keepOnly } = values

  const kept = pruned(samples, template.fields)
  // The owner is pruned after the render; this render already shows only what is kept.
  useEffect(() => {
    keepOnly(template.fields)
  }, [keepOnly, template.fields])

  // An untyped number starts where the check sandbox starts it: its default held within its range.
  const starts = sampleDefaults(template.fields)
  const drafts = Object.fromEntries(
    template.fields.map((field) => [
      field.key,
      kept[field.key]?.draft ??
        (field.type === 'number' ? String(starts[field.key]) : toDraft(field, undefined)),
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
        values.setSample(key, { type: field.type, draft })
      }
    },
    setTrackerSample: values.setTrackerSample,
  }
}
