import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

import { RoleCheckboxes } from './roles'
import type { AdminUser } from './types'
import { useChangeRoles } from './useAdminUsers'

/**
 * Replaces a user's roles. Save stays disabled with none chosen; on the owner's own row OWNER
 * is locked. The API signs the user out when their roles change, which the text says.
 */
export function EditRolesDialog({
  user,
  isSelf,
  onClose,
}: {
  user: AdminUser
  isSelf: boolean
  onClose: () => void
}) {
  const [roles, setRoles] = useState(user.roles)
  const changeRoles = useChangeRoles()

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit roles</DialogTitle>
          <DialogDescription>
            Roles of {user.email}. Changing them signs the user out.
          </DialogDescription>
        </DialogHeader>
        <form
          aria-label="Edit roles"
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault()
            changeRoles.mutate({ id: user.id, roles }, { onSuccess: onClose })
          }}
        >
          <RoleCheckboxes value={roles} onChange={setRoles} locked={isSelf ? 'OWNER' : undefined} />
          {changeRoles.error && (
            <p role="alert" className="text-sm text-destructive">
              {changeRoles.error.message}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={roles.length === 0 || changeRoles.isPending}>
              Save
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
