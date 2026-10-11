import { useState } from 'react'

import type { Campaign } from '../campaigns/useCampaigns'

export type FlowView = 'journal' | 'focus'

const storageKey = (campaignId: string) => `ogami.play.flowView.${campaignId}`

const isFlowView = (value: unknown): value is FlowView => value === 'journal' || value === 'focus'

/** The view remembered for the campaign; storage may be blocked, empty or hold anything. */
function remembered(campaignId: string): FlowView | null {
  try {
    const value = window.localStorage.getItem(storageKey(campaignId))
    return isFlowView(value) ? value : null
  } catch {
    return null
  }
}

/**
 * Which way the player sees a guided campaign: the journal with the guided panel under it, or
 * focus mode. It opens in the view the player last chose for this campaign (kept in
 * `localStorage`), else in the active Flow's `defaultView`.
 */
export function useFlowView(campaign: Campaign): [FlowView, (view: FlowView) => void] {
  const [view, setView] = useState<FlowView>(
    () =>
      remembered(campaign.id) ??
      campaign.flows.find(({ key }) => key === campaign.flowKey)?.defaultView ??
      'journal',
  )

  return [
    view,
    (next) => {
      setView(next)
      try {
        window.localStorage.setItem(storageKey(campaign.id), next)
      } catch {
        // The choice then lasts until the page is left.
      }
    },
  ]
}
