import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import type { FlowInput, FlowStep, UpcomingStep } from './flowRun'

const kindLabels: Record<FlowStep['kind'], string> = {
  prompt: 'Prompt',
  oracle: 'Oracle question',
  roll: 'Roll',
  choice: 'Choice',
  journal: 'Journal entry',
}

/** The type of a flow step, as a small label. */
export function StepKindBadge({ kind }: { kind: FlowStep['kind'] }) {
  return (
    <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
      {kindLabels[kind]}
    </span>
  )
}

/**
 * The controls that answer one flow step, by type. Results are scripted, so asking the
 * oracle or rolling only records the scripted answer. Key it by step id to reset it.
 */
export function StepForm({
  step,
  onAnswer,
}: {
  step: FlowStep
  onAnswer: (input: FlowInput) => void
}) {
  switch (step.kind) {
    case 'prompt':
    case 'journal':
      return (
        <TextForm
          text={step.text}
          placeholder={step.placeholder}
          submitLabel={step.kind === 'prompt' ? 'Record' : 'Write the entry'}
          onSubmit={(text) => {
            onAnswer({ kind: 'text', text })
          }}
        />
      )
    case 'oracle':
      return (
        <div className="space-y-3">
          <p className="text-lg italic">“{step.question}”</p>
          <p className="text-sm text-muted-foreground">
            {step.oracleName} · {step.likelihoodLabel}
          </p>
          <Button
            onClick={() => {
              onAnswer({ kind: 'ask' })
            }}
          >
            Ask the oracle
          </Button>
        </div>
      )
    case 'roll':
      return (
        <div className="space-y-3">
          <p>{step.text}</p>
          <Button
            onClick={() => {
              onAnswer({ kind: 'roll' })
            }}
          >
            Roll <span className="font-mono">{step.expression}</span>
          </Button>
        </div>
      )
    case 'choice':
      return <ChoiceForm step={step} onAnswer={onAnswer} />
  }
}

function TextForm({
  text,
  placeholder,
  submitLabel,
  onSubmit,
}: {
  text: string
  placeholder: string
  submitLabel: string
  onSubmit: (text: string) => void
}) {
  const textId = useId()
  const [value, setValue] = useState('')

  const submit = (event: SubmitEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (value.trim() !== '') {
      onSubmit(value)
    }
  }

  return (
    <form className="space-y-3" onSubmit={submit}>
      <Label htmlFor={textId} className="leading-snug font-normal">
        {text}
      </Label>
      <Textarea
        id={textId}
        value={value}
        placeholder={placeholder}
        onChange={(event) => {
          setValue(event.target.value)
        }}
      />
      <Button type="submit" disabled={value.trim() === ''}>
        {submitLabel}
      </Button>
    </form>
  )
}

function ChoiceForm({
  step,
  onAnswer,
}: {
  step: Extract<FlowStep, { kind: 'choice' }>
  onAnswer: (input: FlowInput) => void
}) {
  const textId = useId()
  return (
    <div className="space-y-3">
      <p id={textId}>{step.text}</p>
      <div role="group" aria-labelledby={textId} className="flex flex-wrap gap-2">
        {step.options.map((option) => (
          <Button
            key={option.id}
            variant="outline"
            onClick={() => {
              onAnswer({ kind: 'choose', optionId: option.id })
            }}
          >
            {option.label}
          </Button>
        ))}
      </div>
    </div>
  )
}

/** The steps that can follow the current one, so the next step is always visible. */
export function UpcomingSteps({ upcoming }: { upcoming: UpcomingStep[] }) {
  if (upcoming.length === 0) {
    return null
  }
  // Not a heading: the prototypes nest it at different heading levels.
  return (
    <section aria-label="Next step" className="text-sm">
      <p className="font-medium text-muted-foreground">Next</p>
      <ul className="mt-1 space-y-1">
        {upcoming.map(({ when, step }, index) => (
          <li key={index} className="flex flex-wrap items-center gap-2">
            {when !== undefined && (
              <span className="text-muted-foreground">
                If <span className="font-medium text-foreground">{when}</span>
                <span aria-hidden="true"> →</span>
              </span>
            )}
            {step === null ? (
              <span>End of scene</span>
            ) : (
              <>
                <span>{step.title}</span>
                <StepKindBadge kind={step.kind} />
              </>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
