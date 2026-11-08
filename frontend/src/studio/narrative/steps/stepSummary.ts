import type { StepDraft, StepGuide } from './stepDraft'

const count = (n: number, one: string, many: string) => `${String(n)} ${n === 1 ? one : many}`

/**
 * What a collapsed step card says under the title: the next step it names and how many effects and
 * kind-specific items (outcomes, options, branches, bands) it has. A part that does not apply is
 * left out, so a bare step has an empty summary. A stored step is kept as it is and has none.
 */
export function stepSummary(step: StepDraft): string {
  if (step.kind === 'stored') {
    return ''
  }
  // Only lists are counted: a string's length is not a number of items.
  const listed = (items: unknown) => (Array.isArray(items) ? items.length : 0)
  const items: [number, string, string][] = [
    [listed(step.effects), 'effect', 'effects'],
    [listed('outcomes' in step ? step.outcomes : null), 'outcome', 'outcomes'],
    [listed('options' in step ? step.options : null), 'option', 'options'],
    [listed('branches' in step ? step.branches : null), 'branch', 'branches'],
    [listed('bands' in step ? step.bands : null), 'band', 'bands'],
  ]
  return [
    ...(step.next === '' ? [] : [`next: ${step.next}`]),
    ...items.filter(([n]) => n > 0).map(([n, one, many]) => count(n, one, many)),
  ].join(' · ')
}

/** Whether the client guides have anything to say about a step: a note on a field, band, scene, next or item. */
export function hasGuideNotes(guide: StepGuide): boolean {
  const { bands, scenes, nexts, items, ...fields } = guide
  return (
    Object.values(fields).some(Boolean) ||
    [bands, scenes, nexts, items].some((notes) => Object.keys(notes).length > 0)
  )
}
