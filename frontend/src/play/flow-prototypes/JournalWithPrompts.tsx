import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/shared/ui/card'
import { Label } from '@/shared/ui/label'
import { Textarea } from '@/shared/ui/textarea'

import { JournalEntryView } from '../journal/JournalEntryView'
import {
  addNote,
  advance,
  currentStep,
  progress,
  startRun,
  upcomingSteps,
  type FlowInput,
  type FlowRunState,
  type FlowSession,
  type FlowStep,
} from './flowRun'
import { StepForm, StepKindBadge, UpcomingSteps } from './StepForm'

/** What the player did in the journal: answered the current step, or wrote a free note. */
export type JournalAction = { kind: 'answer'; input: FlowInput } | { kind: 'note'; text: string }

function replayActions(session: FlowSession, actions: JournalAction[]): FlowRunState {
  return actions.reduce(
    (run, action) =>
      action.kind === 'answer' ? advance(run, action.input) : addNote(run, action.text),
    startRun(session),
  )
}

/**
 * Flow presentation prototype: the campaign journal with inline prompts. The scene's
 * journal stream, with the current flow step as a prompt card at its end, answered in
 * place; every answer appends an entry, and free notes can be written between steps.
 */
export function JournalWithPrompts({
  session,
  initialActions = [],
}: {
  session: FlowSession
  /** What the player already did when the journal opens, e.g. to start mid-session. */
  initialActions?: JournalAction[]
}) {
  const sessionHeadingId = useId()
  const [actions, setActions] = useState(initialActions)
  const run = replayActions(session, actions)
  const step = currentStep(run)
  const act = (action: JournalAction) => {
    setActions([...actions, action])
  }
  // Which step recorded an entry, to caption it in the stream.
  const stepTitles = new Map(run.history.map(({ entryId, title }) => [entryId, title]))

  return (
    <div className="max-w-xl space-y-6">
      <h2 className="font-heading text-xl font-semibold">Journal</h2>
      <section aria-labelledby={sessionHeadingId} className="space-y-3">
        <h3 id={sessionHeadingId} className="font-heading text-lg font-semibold">
          Session {run.session.sessionNumber}
        </h3>
        <h4 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
          Scene {run.session.sceneNumber} — {run.session.sceneTitle}
        </h4>
        {run.entries.length > 0 && (
          <ol className="space-y-3" aria-label="Scene journal">
            {run.entries.map((entry) => {
              const stepTitle = stepTitles.get(entry.id)
              return (
                <li key={entry.id} className="space-y-1">
                  <p className="text-xs text-muted-foreground">
                    {stepTitle === undefined ? 'Free note' : `Flow step · ${stepTitle}`}
                  </p>
                  <JournalEntryView entry={entry} />
                </li>
              )
            })}
          </ol>
        )}
        {step === null ? (
          <SceneFinished
            onRestart={() => {
              setActions([])
            }}
          />
        ) : (
          <>
            <PromptCard
              key={`${step.id}-${String(actions.length)}`}
              run={run}
              step={step}
              onAnswer={(input) => {
                act({ kind: 'answer', input })
              }}
            />
            <FreeNote
              key={actions.length}
              onWrite={(text) => {
                act({ kind: 'note', text })
              }}
            />
          </>
        )}
      </section>
    </div>
  )
}

function PromptCard({
  run,
  step,
  onAnswer,
}: {
  run: FlowRunState
  step: FlowStep
  onAnswer: (input: FlowInput) => void
}) {
  const { number, min, max } = progress(run)
  return (
    <Card
      aria-label={`Current step: ${step.title}`}
      role="region"
      className="ring-2 ring-primary/40"
    >
      <CardHeader>
        <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <StepKindBadge kind={step.kind} />
          <span>
            Step {number} of {min === max ? String(min) : `about ${String(min)}–${String(max)}`}
          </span>
        </p>
        <CardTitle>
          <h5>{step.title}</h5>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <StepForm step={step} onAnswer={onAnswer} />
      </CardContent>
      <CardFooter>
        <UpcomingSteps upcoming={upcomingSteps(run)} />
      </CardFooter>
    </Card>
  )
}

function FreeNote({ onWrite }: { onWrite: (text: string) => void }) {
  const noteId = useId()
  const [text, setText] = useState('')

  const submit = (event: SubmitEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (text.trim() !== '') {
      onWrite(text)
    }
  }

  return (
    <form aria-label="Write a note" className="space-y-2" onSubmit={submit}>
      <Label htmlFor={noteId}>Free note</Label>
      <Textarea
        id={noteId}
        value={text}
        rows={2}
        placeholder="Anything else, between steps…"
        onChange={(event) => {
          setText(event.target.value)
        }}
      />
      <Button type="submit" variant="outline" disabled={text.trim() === ''}>
        Write note
      </Button>
    </form>
  )
}

function SceneFinished({ onRestart }: { onRestart: () => void }) {
  return (
    <div className="space-y-2 rounded-xl p-3 text-sm ring-1 ring-foreground/10">
      <p className="font-medium">The scene is finished.</p>
      <Button variant="ghost" onClick={onRestart}>
        Play the scene again
      </Button>
    </div>
  )
}
