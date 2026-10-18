import { useMutation, useQuery } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type InvitationLookup = components['schemas']['InvitationLookupResponse']

/** The API's one answer for an unknown, expired or used link; pages show their own wording. */
const INVALID_LINK = 'invalid or has expired'

/** A refused or failed call of the public account endpoints; `message` is ready to show. */
export class AccountError extends Error {
  override name = 'AccountError'

  readonly status: number

  /** The link is unknown, expired or already used (422). */
  readonly invalidLink: boolean

  constructor(message: string, status: number, invalidLink: boolean) {
    super(message)
    this.status = status
    this.invalidLink = invalidLink
  }
}

/**
 * 422 (a weak password, a malformed email, an invalid link) and 409 (the email already has an
 * account) carry a message meant for people; anything else, including 400 and 5xx, is generic.
 */
function accountError(response: Response, error: { error: string } | undefined): AccountError {
  const reason = error?.error ?? ''
  const { status } = response
  if ((status === 422 || status === 409) && reason !== '') {
    return new AccountError(reason, status, status === 422 && reason.includes(INVALID_LINK))
  }
  return new AccountError(
    `The request failed with HTTP ${String(status)}. Try again later.`,
    status,
    false,
  )
}

/** `POST /api/auth/password-reset-requests`: always 202 for a valid email, known or not. */
export function useRequestPasswordReset() {
  const api = useApiClient()

  return useMutation<undefined, Error, string>({
    mutationFn: async (email) => {
      const { error, response } = await api.POST('/api/auth/password-reset-requests', {
        body: { email },
      })
      if (response.status !== 202) {
        throw accountError(response, error)
      }
    },
  })
}

interface TokenAndPassword {
  token: string
  password: string
}

/** `POST /api/auth/password-resets`: 204 once the password is changed. */
export function useResetPassword() {
  const api = useApiClient()

  return useMutation<undefined, Error, TokenAndPassword>({
    mutationFn: async (body) => {
      const { error, response } = await api.POST('/api/auth/password-resets', { body })
      if (response.status !== 204) {
        throw accountError(response, error)
      }
    },
  })
}

/** `POST /api/auth/invitations/accept`: 204 once the account exists. */
export function useAcceptInvitation() {
  const api = useApiClient()

  return useMutation<undefined, Error, TokenAndPassword>({
    mutationFn: async (body) => {
      const { error, response } = await api.POST('/api/auth/invitations/accept', { body })
      if (response.status !== 204) {
        throw accountError(response, error)
      }
    },
  })
}

/**
 * `POST /api/auth/invitations/lookup`: what the link is for. An answer that is not an email
 * and roles is an error, never a form for an unknown invitation. The token only keys the
 * cache in memory; nothing is retried, so a dead link answers once.
 */
export function useInvitationLookup(token: string | null) {
  const api = useApiClient()

  return useQuery({
    queryKey: ['account', 'invitation', token],
    enabled: token !== null,
    retry: false,
    staleTime: Infinity,
    queryFn: async ({ signal }): Promise<InvitationLookup> => {
      const { data, error, response } = await api.POST('/api/auth/invitations/lookup', {
        body: { token: token ?? '' },
        signal,
      })
      if (data === undefined) {
        throw accountError(response, error)
      }
      if (typeof data.email !== 'string' || !Array.isArray(data.roles)) {
        throw new AccountError('The invitation could not be read. Try again later.', 200, false)
      }
      return data
    },
  })
}
