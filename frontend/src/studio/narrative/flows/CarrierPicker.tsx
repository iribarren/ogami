import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import type { StepDraft } from '../steps/stepDraft'
import { carriersOf, stepName } from './flowMapCarriers'

/**
 * Picks where in a list of steps an effect goes: the step, then the step itself or one of its bands,
 * options or outcomes. It reads only the steps, so a hook's list and a Scene Type's lists both use it.
 * A step kept as stored is listed but cannot be picked: the editor cannot write to it.
 */
export function CarrierPicker({
  idPrefix,
  steps,
  labels,
  step,
  part,
  onStep,
  onPart,
}: {
  /** Keeps the labels of two pickers on a page apart. */
  idPrefix: string
  steps: StepDraft[]
  /** What each step is called in the list; by default its place and title. */
  labels?: string[]
  step: number
  part: string
  onStep: (step: number) => void
  onPart: (part: string) => void
}) {
  const carriers = carriersOf(steps[step] ?? { kind: 'stored', stored: null })
  return (
    <>
      <div className="grid gap-1.5">
        <Label htmlFor={`${idPrefix}-step`}>Step</Label>
        <Select
          id={`${idPrefix}-step`}
          value={String(step)}
          onChange={(event) => {
            onStep(Number(event.target.value))
          }}
        >
          {steps.map((candidate, at) => (
            <option key={at} value={String(at)} disabled={carriersOf(candidate).length === 0}>
              {`${labels?.[at] ?? `${String(at + 1)}. ${stepName(candidate, at)}`}${carriersOf(candidate).length === 0 ? ' (not editable here)' : ''}`}
            </option>
          ))}
        </Select>
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor={`${idPrefix}-part`}>Part</Label>
        <Select
          id={`${idPrefix}-part`}
          value={part}
          onChange={(event) => {
            onPart(event.target.value)
          }}
        >
          {carriers.map(({ id, label }) => (
            <option key={id} value={id}>
              {label}
            </option>
          ))}
        </Select>
      </div>
    </>
  )
}
