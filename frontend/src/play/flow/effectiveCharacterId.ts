import type { Campaign } from '../campaigns/useCampaigns'

/**
 * The Character a step that changes a sheet answers with: the one the player chose, else the
 * campaign's only Character, else none. Derived on every render, so a list that becomes one
 * Character after mount still preselects it.
 */
export function effectiveCharacterId(campaign: Campaign, chosen: string): string {
  if (chosen !== '') {
    return chosen
  }
  return campaign.characters.length === 1 ? (campaign.characters[0]?.id ?? '') : ''
}
