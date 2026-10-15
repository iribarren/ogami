import type { Campaign } from '../campaigns/useCampaigns'
import { Disclosure } from './Disclosure'

/**
 * The Flow's introduction, at the top of the play area where a new player reads it first. Open
 * before the first session; keyed so it folds when that session starts. Nothing for a campaign
 * without a FlowRun or a Flow without an introduction.
 */
export function FlowIntroduction({ campaign }: { campaign: Campaign }) {
  const introduction = campaign.flows.find(({ key }) => key === campaign.flowKey)?.introduction
  if (campaign.flowRun === null || !introduction) {
    return null
  }
  const beforeFirstSession = campaign.sessions.length === 0
  return (
    <Disclosure
      key={beforeFirstSession ? 'before-first-session' : 'started'}
      title="How this flow works"
      defaultOpen={beforeFirstSession}
    >
      {introduction}
    </Disclosure>
  )
}
