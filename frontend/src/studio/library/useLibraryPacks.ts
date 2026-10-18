import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  explainedRefusals,
  gameSystemKey,
  libraryPacksKey,
  studioError,
  StudioError,
  type GameSystem,
} from '../useGameSystems'

export type LibraryPackSummary = components['schemas']['LibraryPackSummaryResponse']
export type LibraryPackCatalog = components['schemas']['LibraryPackCatalogResponse']
export type LibraryPackCatalogSection = components['schemas']['LibraryPackCatalogSectionResponse']
export type CreateLibraryPackRequest = components['schemas']['CreateLibraryPackRequest']

export const libraryPackKey = (pack: string) => [...libraryPacksKey, pack] as const

/** `GET /api/studio/library/packs`: every pack, mine first, then by name. */
export function useLibraryPacks() {
  const api = useApiClient()

  return useQuery({
    queryKey: libraryPacksKey,
    queryFn: async ({ signal }): Promise<LibraryPackSummary[]> => {
      const { data, error, response } = await api.GET('/api/studio/library/packs', { signal })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [], 'Loading the library packs')
    },
  })
}

/** `GET /api/studio/library/packs/{pack}`: the items of a pack by section. A 404 is not retried. */
export function useLibraryPack(pack: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: libraryPackKey(pack),
    queryFn: async ({ signal }): Promise<LibraryPackCatalog> => {
      const { data, error, response } = await api.GET('/api/studio/library/packs/{pack}', {
        params: { path: { pack } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the library pack')
    },
    retry: (failureCount, error) =>
      !(error instanceof StudioError && error.status === 404) && failureCount < 1,
  })
}

/**
 * `POST /api/studio/library/packs`: creates a pack, then refreshes the packs and opens the new
 * one on its GameSystem page (a pack is edited like a draft).
 */
export function useCreateLibraryPack() {
  const api = useApiClient()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: async (body: CreateLibraryPackRequest): Promise<GameSystem> => {
      const { data, error, response } = await api.POST('/api/studio/library/packs', { body })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Creating the pack')
    },
    onSuccess: async (pack) => {
      queryClient.setQueryData(gameSystemKey(pack.key), pack)
      void queryClient.invalidateQueries({ queryKey: libraryPacksKey, exact: true })
      await navigate({ to: '/studio/game-systems/$key', params: { key: pack.key } })
    },
  })
}
