import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { Textarea } from '@/shared/ui/textarea'

import type { Campaign } from '../campaigns/useCampaigns'
import { PickForm } from './PickForm'
import type { FlowRunCommand, FlowStep } from './useFlowRun'

const kindLabels: Record<FlowStep['kind'], string> = {
  prompt: 'Prompt',
  oracle: 'Oracle question',
  table: 'Table roll',
  roll: 'Dice roll',
  choice: 'Choice',
  pick: 'Pick',
  character: 'Character',
}

interface StepProps {
  campaign: Campaign
  step: FlowStep
  /** Whether a command is under way, which disables every control. */
  pending: boolean
  onCommand: (command: FlowRunCommand) => void
}

/**
 * The step the FlowRun waits on, with the control that completes it by kind (the rolls happen on
 * the server) and "Skip" unless the step is mandatory. Key it by the step and its place (scene,
 * part), so the same step in the next scene of a loop starts with an empty form.
 */
export function StepCard({ campaign, step, pending, onCommand }: StepProps) {
  return (
    <section
      aria-label={`Current step: ${step.title}`}
      className="space-y-3 rounded-xl p-4 ring-2 ring-primary/40"
    >
      <div className="space-y-1">
        <p className="text-xs">
          <span className="rounded-md bg-muted px-1.5 py-0.5 font-medium text-muted-foreground">
            {kindLabels[step.kind]}
          </span>
        </p>
        <h4 className="font-heading text-base font-semibold">{step.title}</h4>
        {step.prompt !== null && <p className="whitespace-pre-wrap">{step.prompt}</p>}
        {step.tip !== null && <p className="text-sm text-muted-foreground">Tip: {step.tip}</p>}
        {step.fills.length > 0 && (
          <div className="text-sm">
            <p className="text-muted-foreground">Fills:</p>
            <ul className="list-inside list-disc">
              {step.fills.map((slot) => (
                <li key={slot.key}>
                  <span className="font-medium">{slot.label}</span>
                  {slot.hint !== null && (
                    <span className="text-muted-foreground"> — {slot.hint}</span>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      <StepForm campaign={campaign} step={step} pending={pending} onCommand={onCommand} />
      {!step.mandatory && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          disabled={pending}
          onClick={() => {
            onCommand({ type: 'skip', stepKey: step.key })
          }}
        >
          Skip
        </Button>
      )}
    </section>
  )
}

function StepForm({ campaign, step, pending, onCommand }: StepProps) {
  const answer = (fields: Omit<Extract<FlowRunCommand, { type: 'answer' }>['body'], 'stepKey'>) => {
    onCommand({ type: 'answer', body: { stepKey: step.key, ...fields } })
  }

  switch (step.kind) {
    case 'prompt':
      return (
        <PromptForm
          pending={pending}
          onSubmit={(text) => {
            answer({ text })
          }}
        />
      )
    case 'oracle':
      return (
        <OracleForm
          oracle={campaign.likelihoodOracles.find(({ key }) => key === step.oracle)}
          step={step}
          pending={pending}
          onAsk={(likelihood) => {
            answer(likelihood === undefined ? {} : { likelihood })
          }}
        />
      )
    case 'table': {
      const table = campaign.oracleTables.find(({ key }) => key === step.table)
      return (
        <Button
          type="button"
          disabled={pending}
          onClick={() => {
            answer({})
          }}
        >
          Roll on {table?.name ?? step.table}
        </Button>
      )
    }
    case 'roll':
      return (
        <Button
          type="button"
          disabled={pending}
          onClick={() => {
            answer({})
          }}
        >
          Roll <span className="font-mono">{step.dice}</span>
        </Button>
      )
    case 'choice':
      return (
        <div role="group" aria-label="Options" className="flex flex-wrap gap-2">
          {step.options.map((option) => (
            <Button
              key={option.key}
              type="button"
              variant="outline"
              disabled={pending}
              onClick={() => {
                answer({ optionKey: option.key })
              }}
            >
              {option.label}
            </Button>
          ))}
        </div>
      )
    case 'pick':
      return (
        <PickForm
          pick={step.pick}
          pending={pending}
          onPick={(pickedIds) => {
            answer(pickedIds === undefined ? {} : { pickedIds })
          }}
        />
      )
    case 'character':
      // The card that picks or creates the Character comes with the party UI.
      return <p className="text-sm text-muted-foreground">This step is not playable here yet.</p>
  }
}

function PromptForm({ pending, onSubmit }: { pending: boolean; onSubmit: (text: string) => void }) {
  const [text, setText] = useState('')
  const textId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    onSubmit(text)
  }

  return (
    <form className="space-y-2" onSubmit={handleSubmit}>
      <Label htmlFor={textId}>Your answer</Label>
      <Textarea
        id={textId}
        value={text}
        onChange={(event) => {
          setText(event.target.value)
        }}
      />
      <Button type="submit" disabled={pending || text.trim() === ''}>
        Record
      </Button>
    </form>
  )
}

/** The player picks the likelihood unless the step fixes it. */
function OracleForm({
  oracle,
  step,
  pending,
  onAsk,
}: {
  oracle: Campaign['likelihoodOracles'][number] | undefined
  step: FlowStep
  pending: boolean
  onAsk: (likelihood: string | undefined) => void
}) {
  const [likelihood, setLikelihood] = useState(oracle?.levels[0]?.key ?? '')
  const likelihoodId = useId()
  const fixed = oracle?.levels.find(({ key }) => key === step.likelihood)

  return (
    <div className="space-y-2">
      <p className="text-sm text-muted-foreground">{oracle?.name ?? step.oracle}</p>
      {step.likelihood === null ? (
        <>
          <Label htmlFor={likelihoodId}>Likelihood</Label>
          <NativeSelect
            id={likelihoodId}
            value={likelihood}
            onChange={(event) => {
              setLikelihood(event.target.value)
            }}
          >
            {oracle?.levels.map((level) => (
              <option key={level.key} value={level.key}>
                {level.label}
              </option>
            ))}
          </NativeSelect>
        </>
      ) : (
        <p className="text-sm">Likelihood: {fixed?.label ?? step.likelihood}</p>
      )}
      <Button
        type="button"
        disabled={pending || (step.likelihood === null && likelihood === '')}
        onClick={() => {
          onAsk(step.likelihood === null ? likelihood : undefined)
        }}
      >
        Ask
      </Button>
    </div>
  )
}
