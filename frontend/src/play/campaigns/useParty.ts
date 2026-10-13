import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from './useCampaigns'

/**
 * `PUT /api/campaigns/{campaignId}/sessions/current/party`: replaces the whole party of the
 * current Session, ordered. The answer is the Session, whose party replaces the one in the cached
 * campaign. A refusal is the mutation's error, with the API's message; a 409 (no Session under
 * way, or the campaign changed meanwhile) also refetches the campaign.
 */
export function useEditParty(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (characterIds: string[]) => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/sessions/current/party',
        { params: { path: { campaignId } }, body: { characterIds } },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Editing the party')
    },
    onSuccess: (session) => {
      queryClient.setQueryData(campaignKey(campaignId), (campaign: Campaign | undefined) =>
        campaign === undefined
          ? campaign
          : {
              ...campaign,
              sessions: campaign.sessions.map((held) =>
                held.number === session.number ? { ...held, party: session.party } : held,
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
