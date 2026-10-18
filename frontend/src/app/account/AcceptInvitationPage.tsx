import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'

import { useLogin, type Role } from '@/shared/auth/currentUser'

import { homePathFor } from '../areas'
import { AccountError, useAcceptInvitation, useInvitationLookup } from './accountApi'
import { accountLinkClass } from './accountLink'
import { PasswordFields } from './PasswordFields'
import { useFragmentToken } from './useFragmentToken'

// Spelled out here: the app shell does not import from a product area (Admin has the same labels).
const roleLabels: Record<Role, string> = {
  SOLO_PLAYER: 'Solo player',
  GAME_MANAGER: 'Game manager',
  OWNER: 'Owner',
}

/**
 * Accepts an Invitation (`/accept-invitation#token=…`): shows who it is for, takes a password,
 * creates the account and signs in as that user, whoever was signed in before.
 */
export function AcceptInvitationPage() {
  const token = useFragmentToken()
  const lookup = useInvitationLookup(token)
  const accept = useAcceptInvitation()
  const login = useLogin()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  function submit(email: string, password: string) {
    if (token === null) {
      return
    }
    accept.mutate(
      { token, password },
      {
        onSuccess: () => {
          login.mutate(
            { email, password },
            {
              onSuccess: (user) => {
                // Data cached for a previous session is the old user's; the link itself is spent.
                void queryClient.invalidateQueries({
                  predicate: (query) => query.queryKey[0] !== 'account',
                })
                void navigate({ href: homePathFor(user), replace: true })
              },
            },
          )
        },
      },
    )
  }

  const invalidLink =
    token === null ||
    (lookup.error instanceof AccountError && lookup.error.invalidLink) ||
    (accept.error instanceof AccountError && accept.error.invalidLink)

  return (
    <section className="mx-auto max-w-sm space-y-6">
      <h1 className="font-heading text-3xl font-bold tracking-tight">Accept your invitation</h1>
      {invalidLink ? (
        <p role="alert">This link is invalid or has expired. Ask the owner for a new invitation.</p>
      ) : lookup.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {lookup.error.message}
        </p>
      ) : lookup.data === undefined ? (
        <p className="text-muted-foreground">Checking your invitation…</p>
      ) : (
        <>
          <p>
            You are invited as <strong>{lookup.data.email}</strong> with the roles{' '}
            {lookup.data.roles.map((role) => roleLabels[role]).join(', ')}. Choose a password to
            create your account.
          </p>
          <PasswordFields
            submitLabel="Create account"
            pending={accept.isPending || login.isPending || login.isSuccess}
            error={
              accept.isError
                ? accept.error.message
                : login.isError
                  ? `Your account was created, but signing in failed: ${login.error.message}`
                  : undefined
            }
            onSubmit={(password) => {
              submit(lookup.data.email, password)
            }}
          />
          {login.isError && (
            <Link to="/login" className={accountLinkClass}>
              Go to sign in
            </Link>
          )}
        </>
      )}
    </section>
  )
}
