import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { useStartSession } from '../campaigns/useCampaigns'
import { visibleTrackers } from '../trackers/useTrackers'
import { Disclosure } from './Disclosure'
import { EndPhaseDialog } from './EndPhaseDialog'
import { canEndScene } from './progress'
import { ScenePickCard } from './ScenePickCard'
import { StepCard } from './StepCard'
import type { FlowRun, FlowRunCommand, useFlowRunCommand } from './useFlowRun'

interface PartProps {
  campaign: Campaign
  flowRun: FlowRun
  command: ReturnType<typeof useFlowRunCommand>
}

/** Whether the command answers the step or the scene pick, whose refusal shows with its card. */
const answersTheCard = (command: FlowRunCommand | undefined) =>
  command?.type === 'answer' ||
  command?.type === 'skip' ||
  command?.type === 'pick' ||
  command?.type === 'pickByRoll'

/**
 * The Scene Type of the scene the FlowRun is in; undefined outside a scene (at the scene pick,
 * after End scene, in a hook), where the last scene's tips no longer apply.
 */
function currentSceneType(campaign: Campaign, flowRun: FlowRun) {
  const sceneNumber = flowRun.progress?.sceneNumber ?? null
  if (sceneNumber === null) {
    return undefined
  }
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === sceneNumber)
  return campaign.sceneTypes.find(({ key }) => key === scene?.sceneType)
}

/**
 * What the FlowRun waits on, the same in the journal and in focus mode: how guidance stands,
 * the step or the scene pick to answer, and the next step named. When the Flow waits for a
 * session, this is where Start session is offered.
 */
export function GuidedBody({ campaign, flowRun, command }: PartProps) {
  const active = flowRun.status === 'active'
  const sceneType = currentSceneType(campaign, flowRun)
  const startSession = useStartSession(campaign.id)
  const onCommand = (next: FlowRunCommand) => {
    command.mutate(next)
  }
  return (
    <>
      {active && flowRun.progress?.phaseDescription != null && (
        <p aria-label="Phase description" className="text-sm whitespace-pre-wrap">
          {flowRun.progress.phaseDescription}
        </p>
      )}
      {active && sceneType?.tips && (
        <Disclosure key={sceneType.key} title={`Tips for ${sceneType.name}`} defaultOpen={false}>
          {sceneType.tips}
        </Disclosure>
      )}
      {flowRun.status === 'completed' && (
        <p className="font-medium">Flow complete. Play on freely.</p>
      )}
      {flowRun.status === 'paused' && (
        <p className="text-sm">
          Guidance is paused: play freely, and resume it when you are ready.
        </p>
      )}
      {active && flowRun.waitsForSession && (
        <div className="space-y-2">
          <Button
            type="button"
            disabled={startSession.isPending}
            onClick={() => {
              startSession.mutate()
            }}
          >
            Start session
          </Button>
          {startSession.error && (
            <p role="alert" className="text-sm text-destructive">
              {startSession.error.message}
            </p>
          )}
        </div>
      )}
      {active && flowRun.step !== null && (
        <StepCard
          key={`${String(campaign.currentSceneNumber)}/${flowRun.progress?.part ?? ''}/${flowRun.step.key}`}
          campaign={campaign}
          step={flowRun.step}
          pending={command.isPending}
          onCommand={onCommand}
        />
      )}
      {active && flowRun.pick !== null && (
        <ScenePickCard
          campaign={campaign}
          pick={flowRun.pick}
          pending={command.isPending}
          onCommand={onCommand}
        />
      )}
      {command.error && answersTheCard(command.variables) && (
        <p role="alert" className="text-sm text-destructive">
          {command.error.message}
        </p>
      )}
      <p>
        <span className="text-muted-foreground">Next: </span>
        <span className="font-medium">{flowRun.next}</span>
      </p>
    </>
  )
}

/**
 * "Move on" in words that say what it does: it ends the current phase, which for the last phase ends
 * the guidance. A leading "The" of the phase name is dropped ("End the start phase").
 */
function endPhaseLabel(phase: string): string {
  return `End the ${phase.replace(/^the\s+/i, '')} phase`
}

/**
 * The controls to end the scene, move on, end the session, and to pause or resume guidance,
 * with a hint when nothing else waits in the scene. They sit below the note composer, where
 * the player has just written what happened. Nothing once the Flow is complete.
 */
export function GuidedControls({ campaign, flowRun, command }: PartProps) {
  const active = flowRun.status === 'active'
  if (flowRun.status === 'completed') {
    return null
  }
  const send = (next: FlowRunCommand) => {
    command.mutate(next)
  }
  return (
    <section aria-label="Guided controls" className="space-y-2">
      {canEndScene(campaign, flowRun) && (
        <p className="text-sm text-muted-foreground">
          Nothing else waits in this scene. Write what happened in a note, then end the scene when
          you are ready.
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        {canEndScene(campaign, flowRun) && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              send({ type: 'endScene', sceneNumber: campaign.currentSceneNumber ?? 0 })
            }}
          >
            End scene
          </Button>
        )}
        {active && flowRun.canMoveOn && flowRun.progress !== null && (
          <EndPhaseDialog
            label={endPhaseLabel(flowRun.progress.phase)}
            description={flowRun.progress.phaseDescription}
            trackers={visibleTrackers(campaign)}
            disabled={command.isPending}
            onConfirm={() => {
              send({ type: 'moveOn', phase: flowRun.progress?.phaseKey ?? '' })
            }}
          />
        )}
        {active && flowRun.canEndSession && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              send({ type: 'endSession' })
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
              send({ type: 'pause' })
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
              send({ type: 'resume' })
            }}
          >
            Resume guidance
          </Button>
        )}
      </div>
      {command.error && !answersTheCard(command.variables) && (
        <p role="alert" className="text-sm text-destructive">
          {command.error.message}
        </p>
      )}
    </section>
  )
}
