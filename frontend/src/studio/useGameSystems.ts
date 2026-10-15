import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type GameSystemSummary = components['schemas']['GameSystemSummaryResponse2']
export type GameSystem = components['schemas']['GameSystemResponse']
export type GameSystemValidation = components['schemas']['GameSystemValidationResponse']
export type CreateGameSystemRequest = components['schemas']['CreateGameSystemRequest']
export type EditMetadataRequest = components['schemas']['EditGameSystemMetadataRequest']

/**
 * A Studio request the API refused or failed; `message` is the API's reason when it gave one,
 * and `status` the HTTP status.
 */
export class StudioError extends Error {
  override name = 'StudioError'

  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

/** The statuses whose API message explains why a Studio change was refused. */
export const explainedRefusals = [400, 404, 409, 422] as const

/** Uses the API's message for the statuses that explain the refusal, a generic one otherwise. */
export function studioError(
  response: Response,
  error: { error: string } | undefined,
  explained: readonly number[],
  failure: string,
) {
  const reason = explained.includes(response.status) ? error?.error : undefined
  return new StudioError(
    reason ?? `${failure} failed with HTTP ${String(response.status)}.`,
    response.status,
  )
}

const gameSystemsKey = ['studio', 'game-systems'] as const
export const gameSystemKey = (key: string) => [...gameSystemsKey, key] as const
const validationKey = (key: string) => [...gameSystemKey(key), 'validation'] as const

/** A 404 (unknown key, or someone else's) will not change by asking again. */
const retryUnlessNotFound = (failureCount: number, error: Error) =>
  !(error instanceof StudioError && error.status === 404) && failureCount < 1

/** `GET /api/studio/game-systems`: my GameSystems, the most recently updated first. */
export function useMyGameSystems() {
  const api = useApiClient()

  return useQuery({
    queryKey: gameSystemsKey,
    queryFn: async ({ signal }): Promise<GameSystemSummary[]> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems', { signal })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [], 'Loading your GameSystems')
    },
  })
}

/** `GET /api/studio/game-systems/{key}`. */
export function useStudioGameSystem(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: gameSystemKey(key),
    queryFn: async ({ signal }): Promise<GameSystem> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the GameSystem')
    },
    retry: retryUnlessNotFound,
  })
}

/** `GET /api/studio/game-systems/{key}/validation`: would the draft be accepted as a release? */
export function useGameSystemValidation(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: validationKey(key),
    queryFn: async ({ signal }): Promise<GameSystemValidation> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/validation', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Validating the GameSystem')
    },
    retry: retryUnlessNotFound,
  })
}

/**
 * `POST /api/studio/game-systems`: creates a draft, blank or as a copy of a published release,
 * then refreshes my GameSystems and opens the new one.
 */
export function useCreateGameSystem() {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (body: CreateGameSystemRequest): Promise<GameSystem> => {
      const { data, error, response } = await api.POST('/api/studio/game-systems', { body })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Creating the GameSystem')
    },
    onSuccess: async (gameSystem) => {
      queryClient.setQueryData(gameSystemKey(gameSystem.key), gameSystem)
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
      await navigate({ to: '/studio/game-systems/$key', params: { key: gameSystem.key } })
    },
  })
}

/**
 * `PUT /api/studio/game-systems/{key}/metadata`: replaces the whole metadata. The answer
 * replaces the cached GameSystem; the list and the validation are refetched.
 */
export function useEditMetadata(key: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (body: EditMetadataRequest): Promise<GameSystem> => {
      const { data, error, response } = await api.PUT('/api/studio/game-systems/{key}/metadata', {
        params: { path: { key } },
        body,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Saving the GameSystem')
    },
    onSuccess: (gameSystem) => {
      queryClient.setQueryData(gameSystemKey(key), gameSystem)
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
      void queryClient.invalidateQueries({ queryKey: validationKey(key) })
    },
  })
}

/**
 * `DELETE /api/studio/game-systems/{key}`: discards a draft that was never published, then
 * goes back to Studio. A refusal (409: it has releases) is shown and nothing changes.
 */
export function useDiscardGameSystem(key: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (): Promise<void> => {
      const { error, response } = await api.DELETE('/api/studio/game-systems/{key}', {
        params: { path: { key } },
      })
      if (!response.ok) {
        throw studioError(response, error, explainedRefusals, 'Discarding the GameSystem')
      }
    },
    onSuccess: async () => {
      queryClient.removeQueries({ queryKey: gameSystemKey(key) })
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
      await navigate({ to: '/studio' })
    },
  })
}
