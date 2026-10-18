import { Link } from '@tanstack/react-router'

import { AccountError, useResetPassword } from './accountApi'
import { accountLinkClass } from './accountLink'
import { PasswordFields } from './PasswordFields'
import { useFragmentToken } from './useFragmentToken'

/** Chooses a new password with the token of an emailed link (`/reset-password#token=…`). */
export function ResetPasswordPage() {
  const token = useFragmentToken()
  const reset = useResetPassword()
  const invalidLink =
    token === null || (reset.error instanceof AccountError && reset.error.invalidLink)

  return (
    <section className="mx-auto max-w-sm space-y-6">
      <h1 className="font-heading text-3xl font-bold tracking-tight">Reset your password</h1>
      {invalidLink ? (
        <>
          <p role="alert">This link is invalid or has expired.</p>
          <Link to="/forgot-password" className={accountLinkClass}>
            Ask for a new link
          </Link>
        </>
      ) : reset.isSuccess ? (
        <>
          <p role="status">Your password has been changed.</p>
          <Link to="/login" className={accountLinkClass}>
            Go to sign in
          </Link>
        </>
      ) : (
        <PasswordFields
          submitLabel="Change password"
          pending={reset.isPending}
          error={reset.error?.message}
          onSubmit={(password) => {
            reset.mutate({ token, password })
          }}
        />
      )}
    </section>
  )
}
