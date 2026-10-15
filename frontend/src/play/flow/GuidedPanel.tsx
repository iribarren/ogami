import type { Campaign } from '../campaigns/useCampaigns'
import { GuidedBody } from './GuidedParts'
import { progressText } from './progress'
import type { FlowRun, useFlowRunCommand } from './useFlowRun'

/**
 * What the campaign's FlowRun waits on, in the journal: where it stands, the step or the scene
 * pick to answer and the next step named. The controls to end the scene or session and to pause
 * or resume guidance sit below the note composer. Paused, the free-play tools stay as they are.
 */
export function GuidedPanel({
  campaign,
  flowRun,
  command,
}: {
  campaign: Campaign
  flowRun: FlowRun
  command: ReturnType<typeof useFlowRunCommand>
}) {
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
      <GuidedBody campaign={campaign} flowRun={flowRun} command={command} />
    </section>
  )
}
