import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from '../campaigns/useCampaigns'

/**
 * `PUT /api/campaigns/{campaignId}/scenes/current/cast`: replaces the whole cast of the current
 * scene, which the answer returns, so it replaces the cast in the cached campaign. A refusal is
 * the mutation's error, with the API's message; a 409 (no current scene, or the campaign changed
 * meanwhile) also refetches the campaign.
 */
export function useEditSceneCast(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (npcIds: string[]) => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/scenes/current/cast',
        { params: { path: { campaignId } }, body: { npcIds } },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Editing the cast')
    },
    onSuccess: (scene) => {
      queryClient.setQueryData(campaignKey(campaignId), (campaign: Campaign | undefined) =>
        campaign === undefined
          ? campaign
          : {
              ...campaign,
              sessions: campaign.sessions.map((session) =>
                session.number === campaign.currentSessionNumber
                  ? {
                      ...session,
                      scenes: session.scenes.map((held) =>
                        held.number === scene.number ? { ...held, cast: scene.cast } : held,
                      ),
                    }
                  : session,
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
