import { Link } from '@tanstack/react-router'
import { useId, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { accountLinkClass } from './accountLink'
import { useRequestPasswordReset } from './accountApi'

/**
 * Asks for a password reset link. The answer is the same for every well-formed email, so the
 * page never says whether an account exists.
 */
export function ForgotPasswordPage() {
  const request = useRequestPasswordReset()
  const emailId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const email = new FormData(event.currentTarget).get('email')
    request.mutate(typeof email === 'string' ? email : '')
  }

  return (
    <section className="mx-auto max-w-sm space-y-6">
      <h1 className="font-heading text-3xl font-bold tracking-tight">Forgot your password?</h1>
      {request.isSuccess ? (
        <p role="status">
          If an account exists for that email, we sent a link to reset your password.
        </p>
      ) : (
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="space-y-2">
            <Label htmlFor={emailId}>Email</Label>
            <Input id={emailId} name="email" type="email" autoComplete="username" required />
          </div>
          {request.error && (
            <p role="alert" className="text-sm text-destructive">
              {request.error.message}
            </p>
          )}
          <Button type="submit" className="w-full" disabled={request.isPending}>
            Send reset link
          </Button>
        </form>
      )}
      <Link to="/login" className={accountLinkClass}>
        Back to sign in
      </Link>
    </section>
  )
}
