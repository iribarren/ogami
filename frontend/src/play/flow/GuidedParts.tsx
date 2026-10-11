import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { canEndScene } from './progress'
import { ScenePickCard } from './ScenePickCard'
import { StepCard } from './StepCard'
import type { FlowRun, FlowRunCommand, useFlowRunCommand } from './useFlowRun'

interface PartProps {
  campaign: Campaign
  flowRun: FlowRun
  command: ReturnType<typeof useFlowRunCommand>
  onCommand: (command: FlowRunCommand) => void
}

/**
 * What the FlowRun waits on, the same in the journal and in focus mode: how guidance stands,
 * the step or the scene pick to answer, and the next step named.
 */
export function GuidedBody({ campaign, flowRun, command, onCommand }: PartProps) {
  const active = flowRun.status === 'active'
  return (
    <>
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
      <p>
        <span className="text-muted-foreground">Next: </span>
        <span className="font-medium">{flowRun.next}</span>
      </p>
    </>
  )
}

/** The controls to end the scene, move on, end the session, and to pause or resume guidance. */
export function GuidedControls({ campaign, flowRun, command, onCommand }: PartProps) {
  const active = flowRun.status === 'active'
  return (
    <>
      <div className="flex flex-wrap gap-2">
        {canEndScene(campaign, flowRun) && (
          <Button
            type="button"
            variant="outline"
            disabled={command.isPending}
            onClick={() => {
              onCommand({ type: 'endScene', sceneNumber: campaign.currentSceneNumber ?? 0 })
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
              onCommand({ type: 'moveOn', phase: flowRun.progress?.phaseKey ?? '' })
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
              onCommand({ type: 'endSession' })
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
              onCommand({ type: 'pause' })
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
              onCommand({ type: 'resume' })
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
    </>
  )
}
