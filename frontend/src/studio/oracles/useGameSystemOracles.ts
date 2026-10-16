import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { gameSystemKey, StudioError, studioError, validationKey } from '../useGameSystems'
import type { OracleTable, SaveTable } from './tableDraft'

export type GameSystemOracles = components['schemas']['GameSystemOraclesResponse']
export type OracleTableItem = components['schemas']['OracleTableItemResponse']
export type LikelihoodOracleItem = components['schemas']['LikelihoodOracleItemResponse']
export type OracleTableResult = components['schemas']['OracleTableResultResponse']

/** A refused oracle write; `paths` says where the draft uses the oracle when that is why. */
export class OracleError extends StudioError {
  override name = 'OracleError'

  readonly paths: string[]

  constructor(message: string, status: number, paths: string[] = []) {
    super(message, status)
    this.paths = paths
  }
}

/** Every status that carries the API's own reason for a refused oracle request. */
const explained = [400, 404, 409, 422]

function oracleError(
  response: Response,
  error: { error: string; paths?: string[] } | undefined,
  failure: string,
) {
  const base = studioError(response, error, explained, failure)
  return new OracleError(base.message, base.status, error?.paths ?? [])
}

export const oraclesKey = (key: string) => [...gameSystemKey(key), 'oracles'] as const

/** `GET /api/studio/game-systems/{key}/oracles`: the draft's oracles, its version and "used by". */
export function useGameSystemOracles(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: oraclesKey(key),
    queryFn: async ({ signal }): Promise<GameSystemOracles> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/oracles', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the oracles')
    },
    retry: (count, error) => !(error instanceof StudioError && error.status === 404) && count < 1,
  })
}

/**
 * Every write answers with the whole oracles view and the new version: it replaces the cached
 * one, so the next write carries the right `expectedVersion`. The draft changed, so what the
 * GameSystem page shows (counts, "unpublished changes") and its validation are refetched.
 */
function useOracleWrite<Variables>(
  key: string,
  write: (variables: Variables) => Promise<GameSystemOracles>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: write,
    onSuccess: (oracles) => {
      queryClient.setQueryData(oraclesKey(key), oracles)
      void queryClient.invalidateQueries({ queryKey: gameSystemKey(key), exact: true })
      void queryClient.invalidateQueries({ queryKey: validationKey(key) })
    },
  })
}

/** `POST /oracle-tables`: appends a table to the draft. */
export function useCreateOracleTable(key: string) {
  const api = useApiClient()

  return useOracleWrite(
    key,
    async ({ expectedVersion, table }: { expectedVersion: number; table: SaveTable }) => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/oracle-tables',
        { params: { path: { key } }, body: { expectedVersion, table: table as OracleTable } },
      )
      if (data !== undefined) {
        return data
      }
      throw oracleError(response, error, 'Creating the table')
    },
  )
}

/** `PUT /oracle-tables/{table}`: replaces a table, `table` being the key it has now. */
export function useSaveOracleTable(key: string) {
  const api = useApiClient()

  return useOracleWrite(
    key,
    async ({
      expectedVersion,
      current,
      table,
    }: {
      expectedVersion: number
      current: string
      table: SaveTable
    }) => {
      const { data, error, response } = await api.PUT(
        '/api/studio/game-systems/{key}/oracle-tables/{table}',
        {
          params: { path: { key, table: current } },
          body: { expectedVersion, table: table as OracleTable },
        },
      )
      if (data !== undefined) {
        return data
      }
      throw oracleError(response, error, 'Saving the table')
    },
  )
}

/** `DELETE /oracle-tables/{table}?expectedVersion=N`. */
export function useDeleteOracleTable(key: string) {
  const api = useApiClient()

  return useOracleWrite(
    key,
    async ({ expectedVersion, table }: { expectedVersion: number; table: string }) => {
      const { data, error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/oracle-tables/{table}',
        { params: { path: { key, table }, query: { expectedVersion } } },
      )
      if (data !== undefined) {
        return data
      }
      throw oracleError(response, error, 'Deleting the table')
    },
  )
}

/** `POST /oracle-tables/{table}/test-rolls`: rolls the saved table; the draft never changes. */
export function useTestRollOracleTable(key: string) {
  const api = useApiClient()

  return useMutation({
    mutationFn: async (table: string): Promise<OracleTableResult> => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/oracle-tables/{table}/test-rolls',
        { params: { path: { key, table } } },
      )
      if (data !== undefined) {
        return data
      }
      throw oracleError(response, error, 'Rolling the table')
    },
  })
}
