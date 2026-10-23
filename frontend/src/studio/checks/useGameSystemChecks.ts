import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import {
  gameSystemKey,
  invalidateOtherDraftReads,
  StudioError,
  studioError,
} from '../useGameSystems'
import type { StoredCheck } from './checkDraft'

export type GameSystemChecks = components['schemas']['GameSystemChecksResponse']
export type CheckTestRollRequest = components['schemas']['CheckTestRollRequest']
export type CheckTestRoll = components['schemas']['CheckTestRollResponse']

/** A refused check write; `paths` says where the draft uses the check when that is why. */
export class CheckError extends StudioError {
  override name = 'CheckError'

  readonly paths: string[]

  constructor(message: string, status: number, paths: string[] = []) {
    super(message, status)
    this.paths = paths
  }
}

/** Every status that carries the API's own reason for a refused check request. */
const explained = [400, 404, 409, 422]

function checkError(
  response: Response,
  error: { error: string; paths?: string[] } | undefined,
  failure: string,
) {
  const base = studioError(response, error, explained, failure)
  return new CheckError(base.message, base.status, error?.paths ?? [])
}

export const checksKey = (key: string) => [...gameSystemKey(key), 'checks'] as const

/** `GET /api/studio/game-systems/{key}/checks`: the draft's checks, its version and what they may read. */
export function useGameSystemChecks(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: checksKey(key),
    queryFn: async ({ signal }): Promise<GameSystemChecks> => {
      const { data, error, response } = await api.GET('/api/studio/game-systems/{key}/checks', {
        params: { path: { key } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the checks')
    },
    retry: (count, error) => !(error instanceof StudioError && error.status === 404) && count < 1,
  })
}

/**
 * Every write answers with the whole checks read model and the new version: it replaces the
 * cached one, so the next write carries the right `expectedVersion`. The draft changed, so what
 * the GameSystem page shows, its validation and every other section read are refetched: they
 * carry the version this write replaced. A 409 means the cached version is
 * stale, so the checks are refetched too: the next write must not carry the version just refused.
 */
function useCheckWrite<Variables>(
  key: string,
  write: (variables: Variables) => Promise<GameSystemChecks>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: write,
    onSuccess: (checks) => {
      queryClient.setQueryData(checksKey(key), checks)
      invalidateOtherDraftReads(queryClient, key, checksKey(key), checks.version)
    },
    onError: (error) => {
      if (error instanceof StudioError && error.status === 409) {
        void queryClient.invalidateQueries({ queryKey: checksKey(key), exact: true })
      }
    },
  })
}

/**
 * `POST /checks` appends a check; with `original`, `PUT /checks/{original}` replaces that check
 * in place, renaming it when the check's key differs.
 */
export function useSaveCheck(key: string) {
  const api = useApiClient()

  return useCheckWrite(
    key,
    async ({
      expectedVersion,
      original,
      check,
    }: {
      expectedVersion: number
      original: string | null
      check: StoredCheck
    }) => {
      const body = { expectedVersion, check: check as components['schemas']['GameSystemCheck'] }
      const { data, error, response } =
        original === null
          ? await api.POST('/api/studio/game-systems/{key}/checks', {
              params: { path: { key } },
              body,
            })
          : await api.PUT('/api/studio/game-systems/{key}/checks/{check}', {
              params: { path: { key, check: original } },
              body,
            })
      if (data !== undefined) {
        return data
      }
      throw checkError(response, error, 'Saving the check')
    },
  )
}

/** `DELETE /checks/{check}?expectedVersion=N`. */
export function useDeleteCheck(key: string) {
  const api = useApiClient()

  return useCheckWrite(
    key,
    async ({ expectedVersion, check }: { expectedVersion: number; check: string }) => {
      const { data, error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/checks/{check}',
        { params: { path: { key, check }, query: { expectedVersion } } },
      )
      if (data !== undefined) {
        return data
      }
      throw checkError(response, error, 'Deleting the check')
    },
  )
}

/** `POST /checks/{check}/move`: the check goes to `index` of the whole check list. */
export function useMoveCheck(key: string) {
  const api = useApiClient()

  return useCheckWrite(
    key,
    async ({
      expectedVersion,
      check,
      index,
    }: {
      expectedVersion: number
      check: string
      index: number
    }) => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/checks/{check}/move',
        { params: { path: { key, check } }, body: { expectedVersion, index } },
      )
      if (data !== undefined) {
        return data
      }
      throw checkError(response, error, 'Moving the check')
    },
  )
}

/**
 * `POST /checks/{check}/test-rolls`: rolls the saved check with the sample values; the draft never
 * changes and nothing is recorded. A refusal (the check is gone, a sample is out of range, the
 * formulas fail) throws with the API's message, path included.
 */
export function useTestRollCheck(key: string) {
  const api = useApiClient()

  return useMutation({
    mutationFn: async ({
      check,
      request,
    }: {
      check: string
      request: CheckTestRollRequest
    }): Promise<CheckTestRoll> => {
      const { data, error, response } = await api.POST(
        '/api/studio/game-systems/{key}/checks/{check}/test-rolls',
        { params: { path: { key, check } }, body: request },
      )
      if (data !== undefined) {
        return data
      }
      throw checkError(response, error, 'Rolling the check')
    },
  })
}
