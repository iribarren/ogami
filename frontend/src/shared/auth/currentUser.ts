import {
  queryOptions,
  useMutation,
  type QueryClient,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'

import type { ApiClient } from '@/shared/api/client'
import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type CurrentUser = components['schemas']['CurrentUserResponse']
export type Role = components['schemas']['Role']
export type Credentials = components['schemas']['LoginRequest']

const currentUserQueryKey = ['auth', 'currentUser'] as const

/** `GET /api/auth/me`: the signed-in user, or `null` for an anonymous visitor. */
export function currentUserQueryOptions(api: ApiClient) {
  return queryOptions({
    queryKey: currentUserQueryKey,
    queryFn: async ({ signal }): Promise<CurrentUser | null> => {
      const { data, response } = await api.GET('/api/auth/me', { signal })
      if (response.status === 401) {
        return null
      }
      if (data === undefined) {
        throw new Error(`Loading the current user failed with HTTP ${String(response.status)}`)
      }
      return data
    },
  })
}

/**
 * The current user for route guards: the cached value when there is one,
 * otherwise fetched once. Freshness is left to `useCurrentUser` and the
 * login/logout mutations, which keep the cache in step with the session.
 */
export function loadCurrentUser(queryClient: QueryClient, api: ApiClient) {
  return queryClient.query({ ...currentUserQueryOptions(api), staleTime: 'static' })
}

export function useCurrentUser() {
  return useQuery(currentUserQueryOptions(useApiClient()))
}

/** A rejected sign-in; `message` is the API's human-readable reason. */
export class LoginError extends Error {
  override name = 'LoginError'
}

/** `POST /api/auth/login`; on success the session cookie is set and the user is cached. */
export function useLogin() {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (credentials: Credentials) => {
      const { data, error, response } = await api.POST('/api/auth/login', { body: credentials })
      if (data !== undefined) {
        return data
      }
      throw new LoginError(error?.error ?? `Sign-in failed with HTTP ${String(response.status)}.`)
    },
    onSuccess: (user) => {
      queryClient.setQueryData(currentUserQueryKey, user)
    },
  })
}

/** `POST /api/auth/logout`; forgets the user and every query cached on their behalf. */
export function useLogout() {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => {
      const { response } = await api.POST('/api/auth/logout')
      if (!response.ok) {
        throw new Error(`Sign-out failed with HTTP ${String(response.status)}`)
      }
    },
    onSuccess: () => {
      queryClient.removeQueries({
        predicate: (query) => query.queryKey[0] !== currentUserQueryKey[0],
      })
      queryClient.setQueryData(currentUserQueryKey, null)
    },
  })
}
