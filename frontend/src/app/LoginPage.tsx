import { useNavigate } from '@tanstack/react-router'
import { useId, type SubmitEvent } from 'react'

import { useLogin } from '@/shared/auth/currentUser'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { homePathFor } from './areas'

/**
 * Email and password sign-in. On success it goes to `redirectTo` (already
 * validated as a same-origin path) or to the user's first area.
 */
export function LoginPage({ redirectTo }: { redirectTo?: string }) {
  const login = useLogin()
  const navigate = useNavigate()
  const emailId = useId()
  const passwordId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    login.mutate(
      { email: textField(form, 'email'), password: textField(form, 'password') },
      {
        onSuccess: (user) => {
          void navigate({ href: redirectTo ?? homePathFor(user), replace: true })
        },
      },
    )
  }

  return (
    <section className="mx-auto max-w-sm space-y-6">
      <h1 className="font-heading text-3xl font-bold tracking-tight">Sign in</h1>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <div className="space-y-2">
          <Label htmlFor={emailId}>Email</Label>
          <Input id={emailId} name="email" type="email" autoComplete="username" required />
        </div>
        <div className="space-y-2">
          <Label htmlFor={passwordId}>Password</Label>
          <Input
            id={passwordId}
            name="password"
            type="password"
            autoComplete="current-password"
            required
          />
        </div>
        {login.error && (
          <p role="alert" className="text-sm text-destructive">
            {login.error.message}
          </p>
        )}
        <Button type="submit" className="w-full" disabled={login.isPending}>
          Sign in
        </Button>
      </form>
    </section>
  )
}

function textField(form: FormData, name: string): string {
  const value = form.get(name)
  return typeof value === 'string' ? value : ''
}
