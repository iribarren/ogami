import { CampaignError, type Campaign } from '../campaigns/useCampaigns'

/**
 * The clear message of a FlowRun answer refused for a sheet rule (ADR 0045): money names the
 * release's currency field, as its label reads on the sheet. Any other refusal keeps its own message.
 */
export function flowRunRefusal(
  error: CampaignError,
  campaign: Campaign | undefined,
): CampaignError {
  if (error.code === 'insufficient_funds') {
    const currency = campaign?.pinnedRelease.currency
    const label = campaign?.pinnedRelease.sheet.find(({ key }) => key === currency?.field)?.label
    return new CampaignError(`Not enough ${label ?? 'money'} for that.`, error.status, error.code)
  }
  if (error.code === 'inventory_full') {
    return new CampaignError(
      'There is no room in the inventory for that. Sell or remove something first.',
      error.status,
      error.code,
    )
  }
  return error
}
