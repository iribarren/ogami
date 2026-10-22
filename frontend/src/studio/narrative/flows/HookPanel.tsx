import { StepListEditor } from '../steps/StepListEditor'
import type { StepContext } from '../steps/stepContext'
import { setHook, type PhaseDraft, type PhaseHook } from './flowDraft'
import type { PhaseAnswer } from './flowError'

/**
 * The step list of one hook of a phase. For a step node the same list shows, with that step
 * marked and scrolled into view. While `locked` (a save is on its way) the list is disabled.
 */
export function HookPanel({
  phase,
  hook,
  step,
  context,
  answer,
  locked,
  onChange,
}: {
  phase: PhaseDraft
  hook: PhaseHook
  /** The position of the selected step, when a step is selected. */
  step?: number
  context: StepContext
  answer: PhaseAnswer | undefined
  locked: boolean
  onChange: (phase: PhaseDraft) => void
}) {
  return (
    <fieldset disabled={locked}>
      <StepListEditor
        steps={phase.hooks[hook] ?? []}
        context={context}
        errors={answer?.hooks[hook]}
        current={step}
        onChange={(steps) => {
          onChange(setHook(phase, hook, steps))
        }}
      />
    </fieldset>
  )
}
