import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/shared/ui/card'

import { JournalEntryView } from '../journal/JournalEntryView'
import {
  currentStep,
  progress,
  replay,
  upcomingSteps,
  type FlowInput,
  type FlowRunState,
  type FlowSession,
} from './flowRun'
import { StepForm, StepKindBadge, UpcomingSteps } from './StepForm'

/** "5" when every branch has the same length, "5–6" otherwise. */
function sceneLength({ min, max }: { min: number; max: number }) {
  return min === max ? String(min) : `${String(min)}–${String(max)}`
}

/**
 * Flow presentation prototype: a step-by-step wizard. One step per screen, with the step's
 * progress, a compact summary of the results so far and the next step always visible; the
 * scene's journal shows once the scene is finished.
 */
export function Wizard({
  session,
  initialInputs = [],
}: {
  session: FlowSession
  /** Answers already given when the wizard opens, e.g. to start mid-session. */
  initialInputs?: FlowInput[]
}) {
  // The run is replayed from the answers, so going back is dropping the last one.
  const [inputs, setInputs] = useState(initialInputs)
  const run = replay(session, inputs)
  const step = currentStep(run)

  const back = () => {
    setInputs(inputs.slice(0, -1))
  }

  return (
    <div className="max-w-xl space-y-6">
      <header className="space-y-1">
        <p className="text-sm text-muted-foreground">Session {run.session.sessionNumber}</p>
        <h2 className="font-heading text-xl font-semibold">
          Scene {run.session.sceneNumber} — {run.session.sceneTitle}
        </h2>
      </header>

      {step === null ? (
        <SceneSummary
          run={run}
          onBack={back}
          onRestart={() => {
            setInputs([])
          }}
        />
      ) : (
        <>
          <StepProgress run={run} />
          <Card>
            <CardHeader>
              <div>
                <StepKindBadge kind={step.kind} />
              </div>
              <CardTitle>
                <h3>{step.title}</h3>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <StepForm
                key={`${step.id}-${String(inputs.length)}`}
                step={step}
                onAnswer={(input) => {
                  setInputs([...inputs, input])
                }}
              />
            </CardContent>
            <CardFooter className="flex-col items-start gap-3">
              <UpcomingSteps upcoming={upcomingSteps(run)} />
            </CardFooter>
          </Card>
          {inputs.length > 0 && (
            <Button variant="ghost" onClick={back}>
              Back
            </Button>
          )}
          <ResultsSoFar run={run} />
        </>
      )}
    </div>
  )
}

function StepProgress({ run }: { run: FlowRunState }) {
  const { number, min, max } = progress(run)
  return (
    <div className="space-y-1">
      <p className="text-sm font-medium">
        Step {number} of {min === max ? '' : 'about '}
        {sceneLength({ min, max })}
      </p>
      <div aria-hidden="true" className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div
          className="h-full rounded-full bg-primary"
          style={{ width: `${String(Math.round(((number - 1) / max) * 100))}%` }}
        />
      </div>
    </div>
  )
}

function ResultsSoFar({ run }: { run: FlowRunState }) {
  if (run.history.length === 0) {
    return null
  }
  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
        So far
      </h3>
      <ol className="space-y-1 text-sm">
        {run.history.map((result, index) => (
          <li key={result.stepId} className="flex gap-2">
            <span className="w-5 shrink-0 text-right text-muted-foreground tabular-nums">
              {index + 1}.
            </span>
            <span>
              <span className="font-medium">{result.title}:</span>{' '}
              <span className="text-muted-foreground">{result.summary}</span>
            </span>
          </li>
        ))}
      </ol>
    </section>
  )
}

function SceneSummary({
  run,
  onBack,
  onRestart,
}: {
  run: FlowRunState
  onBack: () => void
  onRestart: () => void
}) {
  return (
    <section className="space-y-4">
      <div className="space-y-1">
        <h3 className="font-heading text-lg font-semibold">Scene finished</h3>
        <p className="text-sm text-muted-foreground">
          {run.history.length} steps answered. The scene&apos;s journal:
        </p>
      </div>
      <ol className="space-y-3" aria-label="Scene journal">
        {run.entries.map((entry) => (
          <li key={entry.id}>
            <JournalEntryView entry={entry} />
          </li>
        ))}
      </ol>
      <div className="flex gap-2">
        <Button variant="outline" onClick={onBack}>
          Back
        </Button>
        <Button variant="ghost" onClick={onRestart}>
          Play the scene again
        </Button>
      </div>
    </section>
  )
}
