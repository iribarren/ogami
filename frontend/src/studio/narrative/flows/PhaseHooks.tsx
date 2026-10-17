import { useState } from 'react'

import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'

import { StepListEditor } from '../steps/StepListEditor'
import type { StepContext } from '../steps/stepContext'
import { phaseHooks, setHook, type PhaseDraft, type PhaseHook } from './flowDraft'
import type { PhaseAnswer } from './flowError'

const hookLabels: Record<PhaseHook, string> = {
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  sceneOpening: 'Scene opening',
  sceneClosing: 'Scene closing',
  worldTurn: 'World turn',
}

const count = (steps: number) => (steps === 1 ? '1 step' : `${String(steps)} steps`)

/**
 * The seven step lists of a phase (its hooks and the world turn), each collapsed until opened. A
 * list that holds a message opens by itself, so the message is seen.
 */
export function PhaseHooks({
  phase,
  context,
  answer,
  onChange,
}: {
  phase: PhaseDraft
  context: StepContext
  answer: PhaseAnswer | undefined
  onChange: (phase: PhaseDraft) => void
}) {
  return (
    <div className="space-y-2">
      <p className="text-sm font-medium">Hooks</p>
      {phaseHooks.map((hook) => (
        <HookEditor
          key={hook}
          hook={hook}
          steps={phase.hooks[hook] ?? []}
          context={context}
          answer={answer}
          onChange={(steps) => {
            onChange(setHook(phase, hook, steps))
          }}
        />
      ))}
    </div>
  )
}

function HookEditor({
  hook,
  steps,
  context,
  answer,
  onChange,
}: {
  hook: PhaseHook
  steps: NonNullable<PhaseDraft['hooks'][PhaseHook]>
  context: StepContext
  answer: PhaseAnswer | undefined
  onChange: (steps: NonNullable<PhaseDraft['hooks'][PhaseHook]>) => void
}) {
  const [open, setOpen] = useState(false)
  const errors = answer?.hooks[hook]

  return (
    <Collapsible
      open={open || (errors?.length ?? 0) > 0}
      onOpenChange={setOpen}
      className="rounded-lg p-2 ring-1 ring-foreground/10"
    >
      <CollapsibleTrigger className="text-left text-sm font-medium">
        {hookLabels[hook]} ({count(steps.length)})
      </CollapsibleTrigger>
      <CollapsibleContent className="pt-2">
        <StepListEditor steps={steps} context={context} errors={errors} onChange={onChange} />
      </CollapsibleContent>
    </Collapsible>
  )
}
