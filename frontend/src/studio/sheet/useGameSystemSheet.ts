import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { gameSystemKey, StudioError, studioError, validationKey } from '../useGameSystems'
import type { StoredField } from './sheetDraft'

export type GameSystemSheet = components['schemas']['GameSystemSheetResponse']

/** A refused sheet write; `paths` says where the draft reads the field when that is why. */
export class SheetError extends StudioError {
  override name = 'SheetError'

  readonly paths: string[]

  constructor(message: string, status: number, paths: string[] = []) {
    super(message, status)
    this.paths = paths
  }
}

/** Every status that carries the API's own reason for a refused sheet request. */
const explained = [400, 404, 409, 422]

function sheetError(
  response: Response,
  error: { error: string; paths?: string[] } | undefined,
  failure: string,
) {
  const base = studioError(response, error, explained, failure)
  return new SheetError(base.message, base.status, error?.paths ?? [])
}

export const sheetKey = (key: string) => [...gameSystemKey(key), 'sheet'] as const

/** `GET /api/studio/game-systems/{key}/sheet`: the draft's sheet fields, groups and version. */
export function useGameSystemSheet(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: sheetKey(key),
    queryFn: async ({ signal }): Promise<GameSystemSheet> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/sheet', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the sheet')
    },
    retry: (count, error) => !(error instanceof StudioError && error.status === 404) && count < 1,
  })
}

/**
 * Every write answers with the whole sheet and the new version: it replaces the cached one, so
 * the next write carries the right `expectedVersion`. The draft changed, so what the GameSystem
 * page shows and its validation are refetched.
 */
function useSheetWrite<Variables>(
  key: string,
  write: (variables: Variables) => Promise<GameSystemSheet>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: write,
    onSuccess: (sheet) => {
      queryClient.setQueryData(sheetKey(key), sheet)
      void queryClient.invalidateQueries({ queryKey: gameSystemKey(key), exact: true })
      void queryClient.invalidateQueries({ queryKey: validationKey(key) })
    },
  })
}

/**
 * `POST /sheet/fields` appends a field; with `original`, `PUT /sheet/fields/{original}` replaces
 * that field in place, renaming it when the field's key differs.
 */
export function useSaveSheetField(key: string) {
  const api = useApiClient()

  return useSheetWrite(
    key,
    async ({
      expectedVersion,
      original,
      field,
    }: {
      expectedVersion: number
      original: string | null
      field: StoredField
    }) => {
      const body = { expectedVersion, field }
      const { data, error, response } =
        original === null
          ? await api.POST('/api/studio/game-systems/{key}/sheet/fields', {
              params: { path: { key } },
              body,
            })
          : await api.PUT('/api/studio/game-systems/{key}/sheet/fields/{field}', {
              params: { path: { key, field: original } },
              body,
            })
      if (data !== undefined) {
        return data
      }
      throw sheetError(response, error, 'Saving the field')
    },
  )
}

/** `DELETE /sheet/fields/{field}?expectedVersion=N`. */
export function useDeleteSheetField(key: string) {
  const api = useApiClient()

  return useSheetWrite(
    key,
    async ({ expectedVersion, field }: { expectedVersion: number; field: string }) => {
      const { data, error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/sheet/fields/{field}',
        { params: { path: { key, field }, query: { expectedVersion } } },
      )
      if (data !== undefined) {
        return data
      }
      throw sheetError(response, error, 'Deleting the field')
    },
  )
}

/** `POST /sheet/fields/{field}/move`: the field goes to `index` of the whole field list. */
export function useMoveSheetField(key: string) {
  const api = useApiClient()

  return useSheetWrite(
    key,
    async ({
      expectedVersion,
      field,
      index,
    }: {
      expectedVersion: number
      field: string
      index: number
    }) => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/sheet/fields/{field}/move',
        { params: { path: { key, field } }, body: { expectedVersion, index } },
      )
      if (data !== undefined) {
        return data
      }
      throw sheetError(response, error, 'Moving the field')
    },
  )
}
