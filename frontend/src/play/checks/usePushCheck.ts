import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { campaignKey, campaignError, explainedRefusals } from '../campaigns/useCampaigns'
import { journalKey, type JournalEntry } from '../journal/useJournal'

export type PushCheckRequest = components['schemas']['PushCheckRequest']

/**
 * `POST /api/campaigns/{campaignId}/journal/{entryId}/push`: pushes a pool check (ADR 0041): the
 * dice that failed are re-rolled, the cost changes the Character's sheet and the entry is completed
 * in the journal. A refusal (not the latest entry, already pushed, a pick that does not fit) is the
 * mutation's error, with the API's message. The cost changes sheet values, so the campaign is read again.
 */
export function usePushCheck(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      entryId,
      ...body
    }: PushCheckRequest & { entryId: string }): Promise<JournalEntry> => {
      const { data, error, response } = await api.POST(
        '/api/campaigns/{campaignId}/journal/{entryId}/push',
        { params: { path: { campaignId, entryId } }, body },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Pushing the check')
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
      void queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })
    },
  })
}
