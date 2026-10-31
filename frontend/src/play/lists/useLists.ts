import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { ApiClient } from '@/shared/api/client'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  campaignError,
  CampaignError,
  campaignKey,
  explainedRefusals,
  type Campaign,
} from '../campaigns/useCampaigns'

export type Npc = Campaign['npcs'][number]
export type Thread = Campaign['threads'][number]
export type Place = Campaign['places'][number]
export type ListItem = Npc | Thread | Place
export type ListKind = 'npc' | 'thread' | 'place'

/** What the player edits on an item; only NPCs have an agenda and only Places have notes. */
export interface ListFields {
  name: string
  agenda?: string | null
  notes?: string | null
  tags: string[]
  freeTags: string[]
  weight: number
}

interface Outcome {
  data?: ListItem
  error?: { error: string }
  response: Response
}

/** The four calls of one kind of item, each answering the item. */
interface Endpoints {
  add: (api: ApiClient, campaignId: string, body: ListFields) => Promise<Outcome>
  edit: (api: ApiClient, campaignId: string, id: string, body: ListFields) => Promise<Outcome>
  close: (api: ApiClient, campaignId: string, id: string) => Promise<Outcome>
  reopen: (api: ApiClient, campaignId: string, id: string) => Promise<Outcome>
}

const endpoints: Record<ListKind, Endpoints> = {
  npc: {
    add: (api, campaignId, body) =>
      api.POST('/api/campaigns/{campaignId}/npcs', { params: { path: { campaignId } }, body }),
    edit: (api, campaignId, npcId, body) =>
      api.PUT('/api/campaigns/{campaignId}/npcs/{npcId}', {
        params: { path: { campaignId, npcId } },
        body,
      }),
    close: (api, campaignId, npcId) =>
      api.POST('/api/campaigns/{campaignId}/npcs/{npcId}/close', {
        params: { path: { campaignId, npcId } },
      }),
    reopen: (api, campaignId, npcId) =>
      api.POST('/api/campaigns/{campaignId}/npcs/{npcId}/reopen', {
        params: { path: { campaignId, npcId } },
      }),
  },
  thread: {
    add: (api, campaignId, body) =>
      api.POST('/api/campaigns/{campaignId}/threads', { params: { path: { campaignId } }, body }),
    edit: (api, campaignId, threadId, body) =>
      api.PUT('/api/campaigns/{campaignId}/threads/{threadId}', {
        params: { path: { campaignId, threadId } },
        body,
      }),
    close: (api, campaignId, threadId) =>
      api.POST('/api/campaigns/{campaignId}/threads/{threadId}/close', {
        params: { path: { campaignId, threadId } },
      }),
    reopen: (api, campaignId, threadId) =>
      api.POST('/api/campaigns/{campaignId}/threads/{threadId}/reopen', {
        params: { path: { campaignId, threadId } },
      }),
  },
  place: {
    add: (api, campaignId, body) =>
      api.POST('/api/campaigns/{campaignId}/places', { params: { path: { campaignId } }, body }),
    edit: (api, campaignId, placeId, body) =>
      api.PUT('/api/campaigns/{campaignId}/places/{placeId}', {
        params: { path: { campaignId, placeId } },
        body,
      }),
    close: (api, campaignId, placeId) =>
      api.POST('/api/campaigns/{campaignId}/places/{placeId}/close', {
        params: { path: { campaignId, placeId } },
      }),
    reopen: (api, campaignId, placeId) =>
      api.POST('/api/campaigns/{campaignId}/places/{placeId}/reopen', {
        params: { path: { campaignId, placeId } },
      }),
  },
}

const nouns: Record<ListKind, string> = { npc: 'NPC', thread: 'Thread', place: 'Place' }

/**
 * The changes to the NPCs, Threads or Places of a campaign. `save` adds an item, or edits the one
 * with `id`: an edit replaces the item in the cached campaign, an add refetches it (the server
 * keeps the open items first). `setStatus` closes or reopens an item and refetches. A refusal
 * of 409 refetches the campaign too.
 */
export function useListActions(kind: ListKind, campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const noun = nouns[kind]
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  /** The item the API answers, or the refusal as a `CampaignError`. */
  function unwrap(outcome: Outcome, failure: string): ListItem {
    if (outcome.data !== undefined) {
      return outcome.data
    }
    throw campaignError(outcome.response, outcome.error, explainedRefusals, failure)
  }
  const onError = (error: Error) => {
    if (error instanceof CampaignError && error.status === 409) {
      void refetch()
    }
  }

  const save = useMutation({
    mutationFn: async ({ id, fields }: { id: string | null; fields: ListFields }) =>
      id === null
        ? unwrap(await endpoints[kind].add(api, campaignId, fields), `Adding the ${noun}`)
        : unwrap(await endpoints[kind].edit(api, campaignId, id, fields), `Editing the ${noun}`),
    onSuccess: (item, { id }) => {
      if (id === null) {
        void refetch()
        return
      }
      queryClient.setQueryData(campaignKey(campaignId), (campaign: Campaign | undefined) => {
        if (campaign === undefined) {
          return campaign
        }
        const replace = <T extends ListItem>(items: T[]) =>
          items.map((held) => (held.id === item.id ? (item as T) : held))
        switch (kind) {
          case 'npc':
            return { ...campaign, npcs: replace(campaign.npcs) }
          case 'thread':
            return { ...campaign, threads: replace(campaign.threads) }
          case 'place':
            return { ...campaign, places: replace(campaign.places) }
        }
      })
    },
    onError,
  })

  const setStatus = useMutation({
    mutationFn: async ({ id, close }: { id: string; close: boolean }) =>
      close
        ? unwrap(await endpoints[kind].close(api, campaignId, id), `Closing the ${noun}`)
        : unwrap(await endpoints[kind].reopen(api, campaignId, id), `Reopening the ${noun}`),
    onSuccess: () => refetch(),
    onError,
  })

  return { save, setStatus }
}
