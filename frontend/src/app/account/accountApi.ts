import { useMutation, useQuery } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type InvitationLookup = components['schemas']['InvitationLookupResponse']

export type AccountErrorCode = components['schemas']['AccountErrorCode']

const INVALID_ACCOUNT_LINK: AccountErrorCode = 'invalid_account_link'

// The codes the client understands; every value is checked against the generated union.
const KNOWN_CODES: readonly AccountErrorCode[] = [
  'invalid_account_link',
  'weak_password',
  'invalid_email',
  'email_already_in_use',
  'malformed_request',
  'not_json',
]

/** A refused or failed call of the public account endpoints; `message` is ready to show. */
export class AccountError extends Error {
  override name = 'AccountError'

  readonly status: number

  /** Why the API refused, or `null` for a generic failure and for a body without a known code. */
  readonly code: AccountErrorCode | null

  /** The link is unknown, expired or already used (422 `invalid_account_link`). */
  readonly invalidLink: boolean

  constructor(message: string, status: number, code: AccountErrorCode | null) {
    super(message)
    this.status = status
    this.code = code
    this.invalidLink = status === 422 && code === INVALID_ACCOUNT_LINK
  }
}

/**
 * The API's error body is `{ error, code }`. The code is the contract (pinned by the OpenAPI
 * enum and the backend API tests), never the wording of `error`. A missing or unknown code,
 * such as a body from an older API or a proxy, is `null`: never a dead link.
 */
function codeOf(error: { code?: unknown } | undefined): AccountErrorCode | null {
  const known: readonly unknown[] = KNOWN_CODES
  return known.includes(error?.code) ? (error?.code as AccountErrorCode) : null
}

/**
 * 422 (a weak password, a malformed email, an invalid link) and 409 (the email already has an
 * account) carry a message meant for people; anything else, including 400 and 5xx, is generic.
 */
function accountError(
  response: Response,
  error: { error: string; code?: unknown } | undefined,
): AccountError {
  const reason = error?.error ?? ''
  const { status } = response
  if ((status === 422 || status === 409) && reason !== '') {
    return new AccountError(reason, status, codeOf(error))
  }
  return new AccountError(
    `The request failed with HTTP ${String(status)}. Try again later.`,
    status,
    null,
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
        throw new AccountError('The invitation could not be read. Try again later.', 200, null)
      }
      return data
    },
  })
}
