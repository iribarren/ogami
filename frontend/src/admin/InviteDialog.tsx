import { useId, useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { RoleCheckboxes } from './roles'
import type { Role } from './types'
import { useInviteUser } from './useAdminInvitations'

/**
 * The "Invite user" button and its dialog: an email and at least one role. A refusal shows
 * inside the dialog; so does the 503 that says the invitation was saved but not mailed.
 */
export function InviteDialog({ onInvited }: { onInvited: (email: string) => void }) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>Invite user</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Invite user</DialogTitle>
          <DialogDescription>
            The user gets an email with a link to choose a password. It works for seven days.
          </DialogDescription>
        </DialogHeader>
        <InviteForm
          onInvited={(email) => {
            setOpen(false)
            onInvited(email)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function InviteForm({ onInvited }: { onInvited: (email: string) => void }) {
  const [email, setEmail] = useState('')
  const [roles, setRoles] = useState<Role[]>([])
  const invite = useInviteUser()
  const emailId = useId()

  return (
    <form
      aria-label="Invite user"
      noValidate
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault()
        invite.mutate(
          { email: email.trim(), roles },
          {
            onSuccess: () => {
              onInvited(email.trim())
            },
          },
        )
      }}
    >
      <div className="space-y-2">
        <Label htmlFor={emailId}>Email</Label>
        <Input
          id={emailId}
          type="email"
          value={email}
          autoComplete="off"
          onChange={(event) => {
            setEmail(event.target.value)
          }}
        />
      </div>
      <RoleCheckboxes value={roles} onChange={setRoles} />
      {invite.error && (
        <p role="alert" className="text-sm text-destructive">
          {invite.error.message}
        </p>
      )}
      <Button
        type="submit"
        disabled={email.trim() === '' || roles.length === 0 || invite.isPending}
      >
        Send invitation
      </Button>
    </form>
  )
}
