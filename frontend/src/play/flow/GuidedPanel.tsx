import type { Campaign } from '../campaigns/useCampaigns'
import { GuidedBody, GuidedControls } from './GuidedParts'
import { progressText } from './progress'
import { useFlowRunCommand, type FlowRun, type FlowRunCommand } from './useFlowRun'

/**
 * What the campaign's FlowRun waits on, in the journal: where it stands, the step or the scene
 * pick to answer, the next step named, and the controls to end the scene or session and to pause
 * or resume guidance. Paused, the free-play tools stay as they are.
 */
export function GuidedPanel({ campaign, flowRun }: { campaign: Campaign; flowRun: FlowRun }) {
  const command = useFlowRunCommand(campaign.id)
  const parts = {
    campaign,
    flowRun,
    command,
    onCommand: (next: FlowRunCommand) => {
      command.mutate(next)
    },
  }

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
      <GuidedBody {...parts} />
      <GuidedControls {...parts} />
    </section>
  )
}
