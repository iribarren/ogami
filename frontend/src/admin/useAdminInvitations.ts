import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

import { adminError, AdminError } from './adminError'
import type { AdminInvitation, Role } from './types'

export const adminInvitationsKey = ['admin', 'invitations'] as const

/** `GET /api/admin/invitations`: the pending invitations, ordered by email. */
export function useAdminInvitations() {
  const api = useApiClient()

  return useQuery({
    queryKey: adminInvitationsKey,
    queryFn: async ({ signal }): Promise<AdminInvitation[]> => {
      const { data, error, response } = await api.GET('/api/admin/invitations', { signal })
      if (data !== undefined) {
        return data
      }
      throw adminError(response, error, {
        subject: 'invitation',
        failure: 'Loading the invitations',
      })
    },
  })
}

/** Refreshes the invitations after any attempt: a 404 or a 503 (saved, not mailed) leaves the list stale. */
function useRefreshInvitations() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: adminInvitationsKey, exact: true })
}

/** `POST /api/admin/invitations`: a 503 means the invitation is saved but the mail failed. */
export function useInviteUser() {
  const api = useApiClient()
  const refresh = useRefreshInvitations()

  return useMutation<AdminInvitation, AdminError, { email: string; roles: Role[] }>({
    mutationFn: async (body) => {
      const { data, error, response } = await api.POST('/api/admin/invitations', { body })
      if (data !== undefined) {
        return data
      }
      throw adminError(response, error, {
        subject: 'invitation',
        failure: 'Sending the invitation',
        mailFailure: 'The invitation was saved, but the email could not be sent. Use Resend.',
      })
    },
    onSettled: refresh,
  })
}

/** `POST /api/admin/invitations/{id}/resend`: a new link, valid again for seven days. */
export function useResendInvitation() {
  const api = useApiClient()
  const refresh = useRefreshInvitations()

  return useMutation<undefined, AdminError, string>({
    mutationFn: async (id) => {
      const { error, response } = await api.POST('/api/admin/invitations/{id}/resend', {
        params: { path: { id } },
      })
      if (!response.ok) {
        throw adminError(response, error, {
          subject: 'invitation',
          failure: 'Resending the invitation',
          mailFailure: 'The email could not be sent. Try again later.',
        })
      }
    },
    onSettled: refresh,
  })
}

/** `DELETE /api/admin/invitations/{id}`. */
export function useRevokeInvitation() {
  const api = useApiClient()
  const refresh = useRefreshInvitations()

  return useMutation<undefined, AdminError, string>({
    mutationFn: async (id) => {
      const { error, response } = await api.DELETE('/api/admin/invitations/{id}', {
        params: { path: { id } },
      })
      if (!response.ok) {
        throw adminError(response, error, {
          subject: 'invitation',
          failure: 'Revoking the invitation',
        })
      }
    },
    onSettled: refresh,
  })
}
