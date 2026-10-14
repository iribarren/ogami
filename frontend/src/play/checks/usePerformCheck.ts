import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { useRecordEntry } from '../journal/useJournal'

export type PerformCheckRequest = components['schemas']['PerformCheckRequest']

/**
 * `POST /api/campaigns/{campaignId}/journal/checks`: rolls a check of the pinned release for any
 * Character of the campaign and records the result, which appears in the journal. A refusal (an
 * unknown check or Character, a difficulty out of range) is the mutation's error, with the API's message.
 */
export function usePerformCheck(campaignId: string) {
  const api = useApiClient()
  return useRecordEntry(campaignId, 'Rolling the check', (body: PerformCheckRequest) =>
    api.POST('/api/campaigns/{campaignId}/journal/checks', {
      params: { path: { campaignId } },
      body,
    }),
  )
}
