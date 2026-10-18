import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import { adminError, AdminError } from './adminError'
import type { AdminUser, Role } from './types'

export const adminUsersKey = ['admin', 'users'] as const

const MAIL_FAILURE = 'The email could not be sent. Try again later.'

/** `GET /api/admin/users`: every user, ordered by email. */
export function useAdminUsers() {
  const api = useApiClient()

  return useQuery({
    queryKey: adminUsersKey,
    queryFn: async ({ signal }): Promise<AdminUser[]> => {
      const { data, error, response } = await api.GET('/api/admin/users', { signal })
      if (data !== undefined) {
        return data
      }
      throw adminError(response, error, { subject: 'user', failure: 'Loading the users' })
    },
  })
}

/** Refreshes the users after any attempt: a 404 or a changed row means the list is stale. */
function useRefreshUsers() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: adminUsersKey, exact: true })
}

/**
 * `PUT /api/admin/users/{id}/roles`: replaces the roles. The API signs that user out, so when
 * it is the signed-in owner (`isSelf`) a success skips the refetch (it would be a 401): the
 * caller ends the session.
 */
export function useChangeRoles() {
  const api = useApiClient()
  const refresh = useRefreshUsers()

  return useMutation<AdminUser, AdminError, { id: string; roles: Role[]; isSelf: boolean }>({
    mutationFn: async ({ id, roles }) => {
      const { data, error, response } = await api.PUT('/api/admin/users/{id}/roles', {
        params: { path: { id } },
        body: { roles },
      })
      if (data !== undefined) {
        return data
      }
      throw adminError(response, error, { subject: 'user', failure: 'Changing the roles' })
    },
    onSettled: (_user, error, { isSelf }) => {
      // Own roles saved: the session is gone and the caller leaves, so a refetch would be a 401.
      if (isSelf && error === null) {
        return
      }
      return refresh()
    },
  })
}

/** `POST /api/admin/users/{id}/disable`: the user loses any open session. */
export function useDisableUser() {
  const api = useApiClient()
  const refresh = useRefreshUsers()

  return useMutation<undefined, AdminError, string>({
    mutationFn: async (id) => {
      const { error, response } = await api.POST('/api/admin/users/{id}/disable', {
        params: { path: { id } },
      })
      if (!response.ok) {
        throw adminError(response, error, { subject: 'user', failure: 'Disabling the user' })
      }
    },
    onSettled: refresh,
  })
}

/** `POST /api/admin/users/{id}/enable`. */
export function useEnableUser() {
  const api = useApiClient()
  const refresh = useRefreshUsers()

  return useMutation<undefined, AdminError, string>({
    mutationFn: async (id) => {
      const { error, response } = await api.POST('/api/admin/users/{id}/enable', {
        params: { path: { id } },
      })
      if (!response.ok) {
        throw adminError(response, error, { subject: 'user', failure: 'Enabling the user' })
      }
    },
    onSettled: refresh,
  })
}

/** `POST /api/admin/users/{id}/password-reset`: mails a link; the list only needs a refresh when the user is gone. */
export function useSendResetLink() {
  const api = useApiClient()
  const refresh = useRefreshUsers()

  return useMutation<undefined, AdminError, string>({
    mutationFn: async (id) => {
      const { error, response } = await api.POST('/api/admin/users/{id}/password-reset', {
        params: { path: { id } },
      })
      if (!response.ok) {
        throw adminError(response, error, {
          subject: 'user',
          failure: 'Sending the reset link',
          mailFailure: MAIL_FAILURE,
        })
      }
    },
    onError: (error) => (error.status === 404 ? refresh() : undefined),
  })
}
