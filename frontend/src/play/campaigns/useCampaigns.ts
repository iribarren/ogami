import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type CampaignSummary = components['schemas']['CampaignSummaryResponse']
export type Campaign = components['schemas']['CampaignResponse']
export type GameSystemSummary = components['schemas']['GameSystemSummaryResponse']
type CreateCampaignRequest = components['schemas']['CreateCampaignRequest']

/**
 * A campaign request the API refused or failed; `message` is the API's reason when it gave
 * one, and `status` the HTTP status.
 */
export class CampaignError extends Error {
  override name = 'CampaignError'

  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

/** Uses the API's message for the statuses that explain the refusal, a generic one otherwise. */
function campaignError(
  response: Response,
  error: { error: string } | undefined,
  explained: readonly number[],
  failure: string,
) {
  const reason = explained.includes(response.status) ? error?.error : undefined
  return new CampaignError(
    reason ?? `${failure} failed with HTTP ${String(response.status)}.`,
    response.status,
  )
}

const campaignsKey = ['play', 'campaigns'] as const
const campaignKey = (campaignId: string) => [...campaignsKey, campaignId] as const

/** `GET /api/campaigns`: my campaigns, newest first. */
export function useCampaigns() {
  const api = useApiClient()

  return useQuery({
    queryKey: campaignsKey,
    queryFn: async ({ signal }): Promise<CampaignSummary[]> => {
      const { data, error, response } = await api.GET('/api/campaigns', { signal })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, [], 'Loading your campaigns')
    },
  })
}

/** `GET /api/play/game-systems`: the latest release of each published GameSystem. */
export function useGameSystems() {
  const api = useApiClient()

  return useQuery({
    queryKey: ['play', 'game-systems'],
    queryFn: async ({ signal }): Promise<GameSystemSummary[]> => {
      const { data, error, response } = await api.GET('/api/play/game-systems', { signal })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, [], 'Loading the GameSystems')
    },
  })
}

/** `GET /api/campaigns/{campaignId}`; a 404 (unknown or someone else's) is not retried. */
export function useCampaign(campaignId: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: campaignKey(campaignId),
    queryFn: async ({ signal }): Promise<Campaign> => {
      const { data, error, response } = await api.GET('/api/campaigns/{campaignId}', {
        params: { path: { campaignId } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, [404], 'Loading the campaign')
    },
    retry: (failureCount, error) =>
      !(error instanceof CampaignError && error.status === 404) && failureCount < 1,
  })
}

/**
 * `POST /api/campaigns`: creates a campaign pinned to the GameSystem's latest release, then
 * refreshes my campaigns and opens the new one.
 */
export function useCreateCampaign() {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (body: CreateCampaignRequest): Promise<Campaign> => {
      const { data, error, response } = await api.POST('/api/campaigns', { body })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, [400, 404, 409, 422], 'Creating the campaign')
    },
    onSuccess: async (campaign) => {
      queryClient.setQueryData(campaignKey(campaign.id), campaign)
      void queryClient.invalidateQueries({ queryKey: campaignsKey, exact: true })
      await navigate({ to: '/play/campaigns/$campaignId', params: { campaignId: campaign.id } })
    },
  })
}
