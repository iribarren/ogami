import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  explainedRefusals,
  gameSystemKey,
  gameSystemsKey,
  StudioError,
  studioError,
} from '../useGameSystems'
import type { LibrarySection } from './sections'

export type LibraryImports = components['schemas']['LibraryImportsResponse']
export type LibraryImport = components['schemas']['LibraryImportResponse']
export type LibraryImportPreview = components['schemas']['LibraryImportPreviewResponse']
export type LibraryItemRef = components['schemas']['LibraryItemRefRequest']

const importsSegment = 'library-imports'

export const libraryImportsKey = (key: string) => [...gameSystemKey(key), importsSegment] as const

/** `GET /api/studio/game-systems/{key}/library-imports`: what the draft imported, and its version. */
export function useLibraryImports(key: string, enabled: boolean) {
  const api = useApiClient()

  return useQuery({
    queryKey: libraryImportsKey(key),
    enabled,
    queryFn: async ({ signal }): Promise<LibraryImports> => {
      const { data, error, response } = await api.GET(
        '/api/studio/game-systems/{key}/library-imports',
        { params: { path: { key } }, signal },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the library imports')
    },
    retry: (count, error) => !(error instanceof StudioError && error.status === 404) && count < 1,
  })
}

/**
 * `POST …/library-imports/preview`: what importing the items would do, without changing the draft.
 * The answer is not cached: it only describes the selection it was asked for.
 */
export function usePreviewLibraryImport(key: string) {
  const api = useApiClient()

  return useMutation({
    mutationFn: async (body: {
      pack: string
      items: LibraryItemRef[]
    }): Promise<LibraryImportPreview> => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/library-imports/preview',
        { params: { path: { key } }, body },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Previewing the import')
    },
  })
}

/**
 * Every write answers with the imports and the new draft version: they replace the cached ones,
 * so the next write carries the right `expectedVersion`. An import or update changes the draft's
 * items, so everything else cached for the draft and the draft list are refetched.
 */
function useImportsWrite<Variables>(
  key: string,
  write: (variables: Variables) => Promise<LibraryImports>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: write,
    onSuccess: (imports) => {
      queryClient.setQueryData(libraryImportsKey(key), imports)
      void queryClient.invalidateQueries({
        queryKey: gameSystemKey(key),
        predicate: (query) => query.queryKey[3] !== importsSegment,
      })
      void queryClient.invalidateQueries({ queryKey: gameSystemsKey, exact: true })
    },
  })
}

/** `POST …/library-imports`: copies the items, and what they need, into the draft. */
export function useImportLibraryItems(key: string) {
  const api = useApiClient()

  return useImportsWrite(
    key,
    async (body: { expectedVersion: number; pack: string; items: LibraryItemRef[] }) => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/library-imports',
        { params: { path: { key } }, body },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Importing the items')
    },
  )
}

/** `POST …/library-imports/{section}/{item}/update`: replaces the item with the pack's current one. */
export function useUpdateLibraryImport(key: string) {
  const api = useApiClient()

  return useImportsWrite(
    key,
    async ({
      expectedVersion,
      section,
      item,
    }: {
      expectedVersion: number
      section: LibrarySection
      item: string
    }) => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/library-imports/{section}/{item}/update',
        { params: { path: { key, section, item } }, body: { expectedVersion } },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Updating the item')
    },
  )
}

/** `DELETE …/library-imports/{section}/{item}?expectedVersion=N`: forgets the pack; the item stays. */
export function useDetachLibraryImport(key: string) {
  const api = useApiClient()

  return useImportsWrite(
    key,
    async ({
      expectedVersion,
      section,
      item,
    }: {
      expectedVersion: number
      section: LibrarySection
      item: string
    }) => {
      const { data, error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/library-imports/{section}/{item}',
        { params: { path: { key, section, item }, query: { expectedVersion } } },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, explainedRefusals, 'Detaching the item')
    },
  )
}
