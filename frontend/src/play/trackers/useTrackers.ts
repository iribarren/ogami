import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from '../campaigns/useCampaigns'

export type Tracker = Campaign['trackers'][number]

/** "3" for a counter, "3/6" for a clock. */
export function trackerValueText(tracker: Tracker) {
  return tracker.kind === 'clock' && tracker.segments !== null
    ? `${String(tracker.value)}/${String(tracker.segments)}`
    : String(tracker.value)
}

/** Trackers wider than this draw no pips: a pip per point would no longer read at a glance. */
export const MAX_PIPS = 12

/**
 * The pips under a Tracker's name: one per point above zero (or above its minimum when that is
 * negative), `filled` of them up to the value, so a 1..9 scale at 5 fills 5 of 9. Null when the
 * range has no points or the bar would be longer than MAX_PIPS.
 */
export function trackerPips(tracker: Tracker): { total: number; filled: number } | null {
  const base = Math.min(tracker.min, 0)
  const total = tracker.max - base
  if (tracker.max <= tracker.min || total > MAX_PIPS) {
    return null
  }
  return { total, filled: Math.min(total, Math.max(0, tracker.value - base)) }
}

/**
 * The Trackers to show: while the campaign's Flow guides it, the Flow's own in its order,
 * otherwise every Tracker of the release.
 */
export function visibleTrackers(campaign: Campaign): Tracker[] {
  const flow = campaign.flows.find(({ key }) => key === campaign.flowKey)
  if (campaign.flowRun?.status !== 'active' || flow === undefined) {
    return campaign.trackers
  }
  return flow.trackers.flatMap(
    (key) => campaign.trackers.find((tracker) => tracker.key === key) ?? [],
  )
}

/**
 * `PUT /api/campaigns/{campaignId}/trackers/{trackerKey}`: sets a Tracker's value by hand (the
 * API clamps it to the Tracker's range). The answer replaces that Tracker in the cached campaign;
 * a refusal of 409 refetches the campaign instead.
 */
export function useSetTrackerValue(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ key, value }: { key: string; value: number }): Promise<Tracker> => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/trackers/{trackerKey}',
        { params: { path: { campaignId, trackerKey: key } }, body: { value } },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Editing the Tracker')
    },
    onSuccess: (tracker) => {
      queryClient.setQueryData(campaignKey(campaignId), (campaign: Campaign | undefined) =>
        campaign === undefined
          ? campaign
          : {
              ...campaign,
              trackers: campaign.trackers.map((held) =>
                held.key === tracker.key ? tracker : held,
              ),
            },
      )
    },
    onError: (error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })
      }
    },
  })
}
