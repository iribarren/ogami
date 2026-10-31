import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'

import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  campaignKey,
  campaignsKey,
  explainedRefusals,
  type Campaign,
  type TransferCampaignRequest,
} from './useCampaigns'

/**
 * `POST /api/campaigns/{campaignId}/transfer`: starts a new campaign from this one (ADR 0046),
 * then refreshes my campaigns and opens the new one. The source is left as it is. A refusal
 * (not found, not on the latest release, invalid name, Flow or Characters) shows the API's message.
 */
export function useTransferCampaign(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (body: TransferCampaignRequest): Promise<Campaign> => {
      const { data, error, response } = await api.POST('/api/campaigns/{campaignId}/transfer', {
        params: { path: { campaignId } },
        body,
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Continuing in a new campaign')
    },
    onSuccess: async (campaign) => {
      queryClient.setQueryData(campaignKey(campaign.id), campaign)
      void queryClient.invalidateQueries({ queryKey: campaignsKey, exact: true })
      await navigate({ to: '/play/campaigns/$campaignId', params: { campaignId: campaign.id } })
    },
  })
}
