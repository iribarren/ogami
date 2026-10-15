import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  explainedRefusals,
  gameSystemKey,
  gameSystemsKey,
  StudioError,
  studioError,
  validationKey,
  type GameSystem,
} from './useGameSystems'

export type ReleaseSummary = components['schemas']['GameSystemReleaseSummaryResponse']
export type Release = components['schemas']['GameSystemReleaseResponse']
export type PublishRequest = components['schemas']['PublishGameSystemRequest']

const releasesKey = (key: string) => [...gameSystemKey(key), 'releases'] as const

/** A 404 (unknown key or version, or someone else's) will not change by asking again. */
const retryUnlessNotFound = (failureCount: number, error: Error) =>
  !(error instanceof StudioError && error.status === 404) && failureCount < 1

/** `GET /api/studio/game-systems/{key}/releases`: the history, the newest release first. */
export function useGameSystemReleases(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: releasesKey(key),
    queryFn: async ({ signal }): Promise<ReleaseSummary[]> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/releases', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the releases')
    },
    retry: retryUnlessNotFound,
  })
}

/** `GET /api/studio/game-systems/{key}/releases/{version}`: one release, read-only. */
export function useGameSystemRelease(key: string, version: number) {
  const api = useApiClient()

  return useQuery({
    queryKey: [...releasesKey(key), version] as const,
    queryFn: async ({ signal }): Promise<Release> => {
      const { data, error, response } = await api.GET(
        '/api/studio/game-systems/{key}/releases/{version}',
        { params: { path: { key, version: String(version) } }, signal },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the release')
    },
    retry: retryUnlessNotFound,
  })
}

/**
 * `POST /api/studio/game-systems/{key}/restore`: replaces the draft's content with a release's.
 * The answer (the draft) replaces the cached GameSystem; the list and validation are refetched.
 */
export function useRestoreGameSystem(key: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (version: number): Promise<GameSystem> => {
      const { data, error, response } = await api.POST('/api/studio/game-systems/{key}/restore', {
        params: { path: { key } },
        body: { version },
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Restoring the release')
    },
    onSuccess: (gameSystem) => {
      queryClient.setQueryData(gameSystemKey(key), gameSystem)
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
      void queryClient.invalidateQueries({ queryKey: validationKey(key) })
    },
  })
}

/**
 * `POST /api/studio/game-systems/{key}/releases`: publishes the saved draft as the next release.
 * The GameSystem, its validation and its releases (all under its key) and my list are refetched.
 */
export function usePublishGameSystem(key: string) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (body: PublishRequest): Promise<ReleaseSummary> => {
      const { data, error, response } = await api.POST('/api/studio/game-systems/{key}/releases', {
        params: { path: { key } },
        body,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Publishing the GameSystem')
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: gameSystemKey(key) })
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
    },
  })
}

/** The version a publish would create: the one after the latest release, or 1. */
export const nextVersion = (gameSystem: GameSystem) => (gameSystem.latestReleaseVersion ?? 0) + 1
