import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
} from '../campaigns/useCampaigns'

export type Fact = components['schemas']['FactResponse']
export type FactSlot = components['schemas']['FactSlotResponse']
export type FactLink = components['schemas']['FactLinkRequest']
/** What fills a slot: exactly one of a text, an existing NPC, Thread or Place, or a new one by name. */
export type SlotFill = { text: string } | { subject: string } | { name: string }
/** A free fact's label and text, to add or to edit. */
export interface FreeFactFields {
  label: string
  text: string
}

/**
 * The changes to the Campaign facts. Every change refetches the campaign: a fill may create an
 * NPC, Thread or Place, and the server keeps the facts in their order and resolves names. A refusal of
 * 409 refetches too, as the campaign changed under the player.
 */
export function useFactActions(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })
  const onError = (error: Error) => {
    if (error instanceof CampaignError && error.status === 409) {
      void refetch()
    }
  }

  const add = useMutation({
    mutationFn: async (body: FreeFactFields & { links: FactLink[] }) => {
      const { data, error, response } = await api.POST('/api/campaigns/{campaignId}/facts', {
        params: { path: { campaignId } },
        body,
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Adding the fact')
    },
    onSuccess: () => refetch(),
    onError,
  })

  /** A slot fact takes only `links`; a free fact its label, text and links. */
  const edit = useMutation({
    mutationFn: async ({
      factId,
      body,
    }: {
      factId: string
      body: Partial<FreeFactFields> & { links: FactLink[] }
    }) => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/facts/{factId}',
        { params: { path: { campaignId, factId } }, body },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Editing the fact')
    },
    onSuccess: () => refetch(),
    onError,
  })

  const fill = useMutation({
    mutationFn: async ({ slot, value }: { slot: string; value: SlotFill }) => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/fact-slots/{slot}',
        { params: { path: { campaignId, slot } }, body: value },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Filling the Fact slot')
    },
    onSuccess: () => refetch(),
    onError,
  })

  return { add, edit, fill }
}
