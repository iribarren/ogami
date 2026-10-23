import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  gameSystemKey,
  invalidateOtherDraftReads,
  StudioError,
  studioError,
} from '../useGameSystems'

export type GameSystemNarrative = components['schemas']['GameSystemNarrativeResponse']
export type NarrativeItem = components['schemas']['NarrativeItemResponse']

/** The HTTP slug of a narrative section (the document keys are camel case, the paths kebab case). */
export type NarrativeSection = 'trackers' | 'tags' | 'fact-slots' | 'scene-types' | 'flows'

/** A free-form item as stored: the publisher validates it, the editor keeps what it does not know. */
export type StoredItem = Record<string, unknown>

/** A refused narrative write; `paths` says where the draft uses the item when that is why. */
export class NarrativeError extends StudioError {
  override name = 'NarrativeError'

  readonly paths: string[]

  constructor(message: string, status: number, paths: string[] = []) {
    super(message, status)
    this.paths = paths
  }
}

/** Every status that carries the API's own reason for a refused narrative request. */
const explained = [400, 404, 409, 422]

function narrativeError(
  response: Response,
  error: { error: string; paths?: string[] } | undefined,
  failure: string,
) {
  const base = studioError(response, error, explained, failure)
  return new NarrativeError(base.message, base.status, error?.paths ?? [])
}

export const narrativeKey = (key: string) => [...gameSystemKey(key), 'narrative'] as const

/** `GET /api/studio/game-systems/{key}/narrative`: the draft's narrative sections, version and "used by". */
export function useGameSystemNarrative(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: narrativeKey(key),
    queryFn: async ({ signal }): Promise<GameSystemNarrative> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/narrative', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the narrative')
    },
    retry: (count, error) => !(error instanceof StudioError && error.status === 404) && count < 1,
  })
}

/**
 * Every write answers with the whole narrative view and the new version: it replaces the cached
 * one, so the next write carries the right `expectedVersion`. The draft changed, so what the
 * GameSystem page shows, its validation and every other section read are refetched: they carry
 * the version this write replaced.
 */
function useNarrativeWrite<Variables>(
  key: string,
  write: (variables: Variables) => Promise<GameSystemNarrative>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: write,
    onSuccess: (narrative) => {
      queryClient.setQueryData(narrativeKey(key), narrative)
      invalidateOtherDraftReads(queryClient, key, narrativeKey(key))
    },
  })
}

/**
 * `POST /narrative/{section}` appends an item; with `original`, `PUT /narrative/{section}/{original}`
 * replaces that item in place, renaming it when the item's key differs.
 */
export function useSaveNarrativeItem(key: string) {
  const api = useApiClient()

  return useNarrativeWrite(
    key,
    async ({
      expectedVersion,
      section,
      original,
      item,
    }: {
      expectedVersion: number
      section: NarrativeSection
      original: string | null
      item: StoredItem
    }) => {
      const body = { expectedVersion, item }
      const { data, error, response } =
        original === null
          ? await api.POST('/api/studio/game-systems/{key}/narrative/{section}', {
              params: { path: { key, section } },
              body,
            })
          : await api.PUT('/api/studio/game-systems/{key}/narrative/{section}/{item}', {
              params: { path: { key, section, item: original } },
              body,
            })
      if (data !== undefined) {
        return data
      }
      throw narrativeError(response, error, 'Saving the item')
    },
  )
}

/** `DELETE /narrative/{section}/{item}?expectedVersion=N`. */
export function useDeleteNarrativeItem(key: string) {
  const api = useApiClient()

  return useNarrativeWrite(
    key,
    async ({
      expectedVersion,
      section,
      item,
    }: {
      expectedVersion: number
      section: NarrativeSection
      item: string
    }) => {
      const { data, error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/narrative/{section}/{item}',
        { params: { path: { key, section, item }, query: { expectedVersion } } },
      )
      if (data !== undefined) {
        return data
      }
      throw narrativeError(response, error, 'Deleting the item')
    },
  )
}
