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
