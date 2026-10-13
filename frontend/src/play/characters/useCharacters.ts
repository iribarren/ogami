import { useMutation, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from '../campaigns/useCampaigns'

export type Character = Campaign['characters'][number]
export type SheetField = Campaign['pinnedRelease']['sheet'][number]
export type SheetValue = Character['values'][string]
/** What the player edits on a Character: all of it is sent on every save. */
export interface CharacterFields {
  name: string
  tags: string[]
  freeTags: string[]
  values: Character['values']
}

/**
 * The changes to the Characters of a campaign. `save` adds a Character, or edits the one with
 * `id`; either refetches the campaign, which holds the Characters. A refusal shows its message,
 * and a 409 (the campaign changed meanwhile) refetches too.
 */
export function useCharacterActions(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  const save = useMutation({
    mutationFn: async ({ id, fields }: { id: string | null; fields: CharacterFields }) => {
      const outcome =
        id === null
          ? await api.POST('/api/campaigns/{campaignId}/characters', {
              params: { path: { campaignId } },
              body: fields,
            })
          : await api.PUT('/api/campaigns/{campaignId}/characters/{characterId}', {
              params: { path: { campaignId, characterId: id } },
              body: fields,
            })
      if (outcome.data !== undefined) {
        return outcome.data
      }
      throw campaignError(
        outcome.response,
        outcome.error,
        explainedRefusals,
        id === null ? 'Adding the Character' : 'Editing the Character',
      )
    },
    onSuccess: () => refetch(),
    onError: (error: Error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void refetch()
      }
    },
  })

  return { save }
}

/**
 * `POST /api/campaigns/{campaignId}/npcs/{npcId}/promote`: the NPC becomes a Character and is
 * closed for good. The campaign is refetched on success and on a refusal that says it changed
 * (409), so the NPC then shows as promoted.
 */
export function usePromoteNpc(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  return useMutation({
    mutationFn: async (npcId: string) => {
      const { data, error, response } = await api.POST(
        '/api/campaigns/{campaignId}/npcs/{npcId}/promote',
        { params: { path: { campaignId, npcId } } },
      )
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Promoting the NPC')
    },
    onSuccess: () => refetch(),
    onError: (error: Error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void refetch()
      }
    },
  })
}
