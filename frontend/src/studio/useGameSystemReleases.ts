import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  explainedRefusals,
  gameSystemKey,
  gameSystemsKey,
  studioError,
  type GameSystem,
} from './useGameSystems'

export type ReleaseSummary = components['schemas']['GameSystemReleaseSummaryResponse']
export type PublishRequest = components['schemas']['PublishGameSystemRequest']

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
