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
  const accept = useAcceptInvitation()
  // The link is spent once accepted: stop looking it up (a refetch would answer 422).
  const lookup = useInvitationLookup(accept.isSuccess ? null : token)
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
          // Whatever was cached belongs to a previous session, if any: forget it before the
          // new user signs in. The current user stays, since the shell observes it and sign-in
          // replaces it; clearing it would leave the shell on a removed query. Accepting never
          // runs again: a failed sign-in is only reported.
          queryClient.removeQueries({
            predicate: ({ queryKey }) => queryKey[0] !== 'auth' && queryKey[0] !== 'account',
          })
          login.mutate(
            { email, password },
            {
              onSuccess: (user) => {
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
      {accept.isSuccess ? (
        <>
          <p role="status">Your account was created.</p>
          {login.isError && (
            <>
              <p role="alert" className="text-sm text-destructive">
                {`Your account was created, but signing in failed: ${login.error.message}`}
              </p>
              <Link to="/login" className={accountLinkClass}>
                Go to sign in
              </Link>
            </>
          )}
        </>
      ) : invalidLink ? (
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
            pending={accept.isPending}
            error={accept.error?.message}
            onSubmit={(password) => {
              submit(lookup.data.email, password)
            }}
          />
        </>
      )}
    </section>
  )
}
