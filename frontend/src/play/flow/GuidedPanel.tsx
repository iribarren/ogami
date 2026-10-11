import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { ScenePickCard } from './ScenePickCard'
import { StepCard } from './StepCard'
import { useFlowRunCommand, type FlowRun, type FlowRunCommand } from './useFlowRun'

const partLabels: Record<NonNullable<NonNullable<FlowRun['progress']>['part']>, string> = {
  sceneOpening: 'Scene opening',
  setup: 'Setup',
  play: 'Play',
  open: 'Open play',
  closing: 'Closing',
  sceneClosing: 'Scene closing',
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  worldTurn: 'World turn',
}

const hooks = new Set<string | null>([
  'sessionOpening',
  'sessionClosing',
  'phaseOpening',
  'phaseClosing',
  'worldTurn',
])

/** "Act 1 › The start › Tour › Setup · step 1 of 3", whatever the Flow tells. */
function progressText(progress: NonNullable<FlowRun['progress']>) {
  const place = [
    progress.act,
    progress.phase,
    progress.sceneType,
    progress.part === null ? null : partLabels[progress.part],
  ].filter((part) => part !== null)
  const step =
    progress.stepNumber === null || progress.stepCount === null
      ? ''
      : ` · step ${String(progress.stepNumber)} of ${String(progress.stepCount)}`
  return `${place.join(' › ')}${step}`
}

/**
 * What the campaign's FlowRun waits on, in the journal: where it stands, the step or the scene
 * pick to answer, the next step named, and the controls to end the scene or session and to pause
 * or resume guidance. Paused, the free-play tools stay as they are.
 */
export function GuidedPanel({ campaign, flowRun }: { campaign: Campaign; flowRun: FlowRun }) {
  const command = useFlowRunCommand(campaign.id)
  const run = (next: FlowRunCommand) => {
    command.mutate(next)
  }
  const active = flowRun.status === 'active'
  const part = flowRun.progress?.part ?? null
  // Open play in a scene of play: nothing waits, so the scene can end.
  const canEndScene =
    active &&
    flowRun.step === null &&
    flowRun.pick === null &&
    !flowRun.waitsForSession &&
    campaign.currentSceneNumber !== null &&
    !hooks.has(part)

  return (
    <section
      aria-label="Guided play"
      className="space-y-4 rounded-xl p-4 ring-1 ring-foreground/10"
    >
      <div className="space-y-1">
        <h3 className="font-heading text-lg font-semibold">Guided play</h3>
        {flowRun.progress !== null && (
          <p aria-label="Progress" className="text-sm text-muted-foreground">
            {progressText(flowRun.progress)}
          </p>
        )}
      </div>
      {flowRun.status === 'completed' && (
        <p className="font-medium">Flow complete. Play on freely.</p>
      )}
      {flowRun.status === 'paused' && (
        <p className="text-sm">
          Guidance is paused: play freely, and resume it when you are ready.
        </p>
      )}
      {active && flowRun.waitsForSession && (
        <p className="text-sm text-muted-foreground">Start a session to go on.</p>
      )}
      {active && flowRun.step !== null && (
        <StepCard
          key={flowRun.step.key}
          campaign={campaign}
          step={flowRun.step}
          pending={command.isPending}
          onCommand={run}
        />
      )}
      {active && flowRun.pick !== null && (
        <ScenePickCard
          campaign={campaign}
          pick={flowRun.pick}
          pending={command.isPending}
          onCommand={run}
        />
      )}
      <p>
        <span className="text-muted-foreground">Next: </span>
        <span className="font-medium">{flowRun.next}</span>
      </p>
      <div className="flex flex-wrap gap-2">
        {canEndScene && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              run({ type: 'endScene', sceneNumber: campaign.currentSceneNumber ?? 0 })
            }}
          >
            End scene
          </Button>
        )}
        {active && flowRun.canMoveOn && flowRun.progress !== null && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              run({ type: 'moveOn', phase: flowRun.progress?.phaseKey ?? '' })
            }}
          >
            Move on
          </Button>
        )}
        {active && flowRun.canEndSession && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              run({ type: 'endSession' })
            }}
          >
            End session
          </Button>
        )}
        {active && (
          <Button
            type="button"
            variant="ghost"
            disabled={command.isPending}
            onClick={() => {
              run({ type: 'pause' })
            }}
          >
            Pause guidance
          </Button>
        )}
        {flowRun.status === 'paused' && (
          <Button
            type="button"
            disabled={command.isPending}
            onClick={() => {
              run({ type: 'resume' })
            }}
          >
            Resume guidance
          </Button>
        )}
      </div>
      {command.error && (
        <p role="alert" className="text-sm text-destructive">
          {command.error.message}
        </p>
      )}
    </section>
  )
}
