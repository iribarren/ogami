import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { campaignError, campaignKey, explainedRefusals, type Campaign } from './useCampaigns'

export type ReleaseUpgrade = components['schemas']['ReleaseUpgradeResponse']
export type ReleaseUpgradePlan = components['schemas']['ReleaseUpgradePlanResponse']
export type UpgradeFieldChange = components['schemas']['UpgradeFieldChangeResponse']
export type UpgradeTrackerChange = components['schemas']['UpgradeTrackerChangeResponse']

/** A 500 is explained too: after a saved upgrade the API says so and asks for a reload. */
const explainedUpgradeFailures = [...explainedRefusals, 500]

const previewKey = (campaignId: string) => [...campaignKey(campaignId), 'release-upgrade'] as const

/**
 * `GET /api/campaigns/{campaignId}/release-upgrade`: what upgrading to the latest release changes,
 * or that the campaign is up to date. Only loaded while `enabled` (the dialog is open), and always
 * fetched again on opening: a preview that stayed in the cache may no longer be the change the
 * server would apply.
 */
export function useReleaseUpgradePreview(campaignId: string, enabled: boolean) {
  const api = useApiClient()

  return useQuery({
    queryKey: previewKey(campaignId),
    queryFn: async ({ signal }): Promise<ReleaseUpgrade> => {
      const { data, error, response } = await api.GET(
        '/api/campaigns/{campaignId}/release-upgrade',
        {
          params: { path: { campaignId } },
          signal,
        },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Loading the release upgrade')
    },
    enabled,
    gcTime: 0,
    retry: false,
  })
}

/**
 * `POST /api/campaigns/{campaignId}/release-upgrade`: upgrades to exactly the previewed change.
 * The answer is the upgraded campaign and replaces the cached one. Whatever the failure, the
 * campaign and the preview are fetched again: a 409 means the preview is stale (or the campaign is
 * already up to date), and a 500 may follow a saved upgrade, which then reads as up to date.
 */
export function useUpgradeCampaignRelease(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      toVersion,
      fingerprint,
    }: {
      toVersion: number
      fingerprint: string
    }): Promise<Campaign> => {
      const { data, error, response } = await api.POST(
        '/api/campaigns/{campaignId}/release-upgrade',
        { params: { path: { campaignId } }, body: { toVersion, fingerprint } },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedUpgradeFailures, 'Upgrading the campaign')
    },
    onSuccess: (campaign) => {
      queryClient.removeQueries({ queryKey: previewKey(campaignId) })
      queryClient.setQueryData(campaignKey(campaignId), campaign)
    },
    onError: () => {
      void queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })
    },
  })
}
