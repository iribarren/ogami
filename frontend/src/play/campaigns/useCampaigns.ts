import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { journalKey } from '../journal/useJournal'

export type CampaignSummary = components['schemas']['CampaignSummaryResponse']
export type Campaign = components['schemas']['CampaignResponse']
export type GameSystemSummary = components['schemas']['GameSystemSummaryResponse']
/** Which rule of structured character creation a 422 broke (ADR 0040); the message is for people. */
export type CharacterCreationErrorCode = components['schemas']['CharacterCreationErrorCode']
/** Which sheet rule a FlowRun answer broke: a spend over the currency or a purchase over an inventory (ADR 0045). */
export type FlowRunErrorCode = components['schemas']['FlowRunErrorCode']
type CreateCampaignRequest = components['schemas']['CreateCampaignRequest']
export type TransferCampaignRequest = components['schemas']['TransferCampaignRequest']
type StartSceneRequest = components['schemas']['StartSceneRequest']

/** The API's limit on a campaign name (`Campaign::MAX_NAME_LENGTH`), after trimming. */
export const MAX_NAME_LENGTH = 100

/** The choice for playing without guidance; the other choices are Flow keys. */
export const PLAY_FREELY = ''

/**
 * A campaign request the API refused or failed; `message` is the API's reason when it gave
 * one, and `status` the HTTP status.
 */
export class CampaignError extends Error {
  override name = 'CampaignError'

  readonly status: number
  /** Set on a refusal that broke a creation rule or a sheet rule of a FlowRun answer. */
  readonly code: CharacterCreationErrorCode | FlowRunErrorCode | undefined

  constructor(
    message: string,
    status: number,
    code?: CharacterCreationErrorCode | FlowRunErrorCode,
  ) {
    super(message)
    this.status = status
    this.code = code
  }
}

/** Uses the API's message for the statuses that explain the refusal, a generic one otherwise. */
export function campaignError(
  response: Response,
  error: { error: string; code?: CharacterCreationErrorCode | FlowRunErrorCode | null } | undefined,
  explained: readonly number[],
  failure: string,
) {
  const reason = explained.includes(response.status) ? error?.error : undefined
  return new CampaignError(
    reason ?? `${failure} failed with HTTP ${String(response.status)}.`,
    response.status,
    explained.includes(response.status) ? (error?.code ?? undefined) : undefined,
  )
}

export const campaignsKey = ['play', 'campaigns'] as const
export const campaignKey = (campaignId: string) => [...campaignsKey, campaignId] as const

/** The statuses whose API message explains why a Play change was refused. */
export const explainedRefusals = [400, 404, 409, 422] as const

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
      throw campaignError(response, error, [], 'Loading the game systems')
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
      throw campaignError(response, error, explainedRefusals, 'Creating the campaign')
    },
    onSuccess: async (campaign) => {
      queryClient.setQueryData(campaignKey(campaign.id), campaign)
      void queryClient.invalidateQueries({ queryKey: campaignsKey, exact: true })
      await navigate({ to: '/play/campaigns/$campaignId', params: { campaignId: campaign.id } })
    },
  })
}

/**
 * `POST /api/campaigns/{campaignId}/sessions`: starts the next session, which becomes the
 * current one (without a scene yet). The answer replaces the cached campaign.
 */
export function useStartSession(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (): Promise<Campaign> => {
      const { data, error, response } = await api.POST('/api/campaigns/{campaignId}/sessions', {
        params: { path: { campaignId } },
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Starting the session')
    },
    onSuccess: (campaign) => {
      queryClient.setQueryData(campaignKey(campaignId), campaign)
    },
  })
}

/**
 * `POST /api/campaigns/{campaignId}/sessions/current/end`: ends the current session. The answer
 * is the session alone, so the campaign is refetched, and the journal too (a session closing
 * can add entries). A refusal (409: no session under way) refetches the campaign as well.
 */
export function useEndSession(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  return useMutation({
    mutationFn: async (): Promise<void> => {
      const { data, error, response } = await api.POST(
        '/api/campaigns/{campaignId}/sessions/current/end',
        { params: { path: { campaignId } } },
      )
      if (data === undefined) {
        throw campaignError(response, error, explainedRefusals, 'Ending the session')
      }
    },
    onSuccess: async () => {
      void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
      await refetch()
    },
    onError: (error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void refetch()
      }
    },
  })
}

/**
 * `POST /api/campaigns/{campaignId}/scenes`: starts the next scene of the current session,
 * which becomes the current scene. The answer replaces the cached campaign.
 */
export function useStartScene(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (body: StartSceneRequest): Promise<Campaign> => {
      const { data, error, response } = await api.POST('/api/campaigns/{campaignId}/scenes', {
        params: { path: { campaignId } },
        body,
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, 'Starting the scene')
    },
    onSuccess: (campaign) => {
      queryClient.setQueryData(campaignKey(campaignId), campaign)
    },
  })
}

/**
 * `PUT /api/campaigns/{campaignId}/scenes/current/title`: renames the current scene. The answer
 * is the scene alone, so the campaign is refetched, and the journal too (its scene headings show
 * the title). A refusal (409: no scene any more) refetches the campaign as well.
 */
export function useRenameScene(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  return useMutation({
    mutationFn: async (title: string): Promise<void> => {
      const { data, error, response } = await api.PUT(
        '/api/campaigns/{campaignId}/scenes/current/title',
        { params: { path: { campaignId } }, body: { title } },
      )
      if (data === undefined) {
        throw campaignError(response, error, explainedRefusals, 'Renaming the scene')
      }
    },
    onSuccess: async () => {
      void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
      await refetch()
    },
    onError: (error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void refetch()
      }
    },
  })
}

/**
 * `POST /api/campaigns/{campaignId}/scenes/current/scene-type`: switches the current scene to
 * another Scene Type; its entries stay and a default title follows the new type. The answer is
 * the scene alone, so the campaign (and the journal) is refetched, which also happens when the switch is refused (409): the screen must not keep a
 * scene the server no longer has.
 */
export function useSwitchSceneType(campaignId: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const refetch = () => queryClient.invalidateQueries({ queryKey: campaignKey(campaignId) })

  return useMutation({
    mutationFn: async (sceneType: string): Promise<void> => {
      const { data, error, response } = await api.POST(
        '/api/campaigns/{campaignId}/scenes/current/scene-type',
        { params: { path: { campaignId } }, body: { sceneType } },
      )
      if (data === undefined) {
        throw campaignError(response, error, explainedRefusals, 'Switching the Scene Type')
      }
    },
    onSuccess: async () => {
      // A default title follows the new type, and the journal's scene headings show it.
      void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
      await refetch()
    },
    onError: (error) => {
      if (error instanceof CampaignError && error.status === 409) {
        void refetch()
      }
    },
  })
}
