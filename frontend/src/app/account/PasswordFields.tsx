import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

/**
 * A new password typed twice. The two must match (checked here); the length rule is the API's,
 * the hint only says it. `error` is the API's refusal of the last submit.
 */
export function PasswordFields({
  submitLabel,
  pending,
  error,
  onSubmit,
}: {
  submitLabel: string
  pending: boolean
  error?: string | undefined
  onSubmit: (password: string) => void
}) {
  const passwordId = useId()
  const confirmId = useId()
  const [mismatch, setMismatch] = useState(false)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const password = form.get('password')
    const confirm = form.get('confirm')
    if (typeof password !== 'string' || password !== confirm) {
      setMismatch(true)
      return
    }
    setMismatch(false)
    onSubmit(password)
  }

  const message = mismatch ? 'The passwords do not match.' : error

  return (
    <form className="space-y-4" onSubmit={handleSubmit}>
      <div className="space-y-2">
        <Label htmlFor={passwordId}>New password</Label>
        <Input
          id={passwordId}
          name="password"
          type="password"
          autoComplete="new-password"
          aria-describedby={`${passwordId}-hint`}
          required
        />
        <p id={`${passwordId}-hint`} className="text-sm text-muted-foreground">
          At least 8 characters.
        </p>
      </div>
      <div className="space-y-2">
        <Label htmlFor={confirmId}>Confirm password</Label>
        <Input id={confirmId} name="confirm" type="password" autoComplete="new-password" required />
      </div>
      {message !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      <Button type="submit" className="w-full" disabled={pending}>
        {submitLabel}
      </Button>
    </form>
  )
}
