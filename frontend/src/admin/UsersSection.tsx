import { useState } from 'react'

import { useCurrentUser } from '@/shared/auth/currentUser'
import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'

import { ConfirmDialog } from './ConfirmDialog'
import { EditRolesDialog } from './EditRolesDialog'
import { Notice, type NoticeState } from './Notice'
import { RoleBadges } from './roles'
import { adminDate } from './adminDate'
import type { AdminUser } from './types'
import { useAdminUsers, useDisableUser, useEnableUser, useSendResetLink } from './useAdminUsers'

type Dialog = { kind: 'roles' | 'disable' | 'enable'; user: AdminUser } | null

/**
 * The users table with its row actions. Your own row has no Disable and a locked OWNER role
 * (the API refuses both too). A disabled user can be enabled but gets no reset link.
 */
export function UsersSection() {
  const users = useAdminUsers()
  const currentUser = useCurrentUser()
  const sendResetLink = useSendResetLink()
  const [dialog, setDialog] = useState<Dialog>(null)
  const [notice, setNotice] = useState<NoticeState>(null)
  const close = () => {
    setDialog(null)
  }

  function sendLink(user: AdminUser) {
    setNotice(null)
    sendResetLink.mutate(user.id, {
      onSuccess: () => {
        setNotice({ kind: 'success', text: `Reset link sent to ${user.email}` })
      },
      onError: (error) => {
        setNotice({ kind: 'error', text: error.message })
      },
    })
  }

  return (
    <section aria-labelledby="admin-users" className="space-y-3">
      <h2 id="admin-users" className="font-heading text-xl font-semibold">
        Users
      </h2>
      <Notice notice={notice} />
      {users.isPending && <p className="text-muted-foreground">Loading the users…</p>}
      {users.isError && (
        <p role="alert" className="text-sm text-destructive">
          {users.error.message}
        </p>
      )}
      {users.data && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b text-muted-foreground">
              <tr>
                <th className="py-2 pr-4 font-medium">Email</th>
                <th className="py-2 pr-4 font-medium">Roles</th>
                <th className="py-2 pr-4 font-medium">Status</th>
                <th className="py-2 pr-4 font-medium">Created</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.data.map((user) => {
                const isSelf = user.id === currentUser.data?.id
                return (
                  <tr key={user.id} className="border-b align-middle">
                    <td className="py-2 pr-4 break-all">{user.email}</td>
                    <td className="py-2 pr-4">
                      <RoleBadges roles={user.roles} />
                    </td>
                    <td className="py-2 pr-4">
                      <Badge variant={user.status === 'active' ? 'success' : 'warning'}>
                        {user.status === 'active' ? 'Active' : 'Disabled'}
                      </Badge>
                    </td>
                    <td className="py-2 pr-4 whitespace-nowrap">{adminDate(user.createdAt)}</td>
                    <td className="py-2">
                      <div className="flex flex-wrap gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            setDialog({ kind: 'roles', user })
                          }}
                        >
                          Edit roles
                        </Button>
                        {user.status === 'disabled' ? (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              setDialog({ kind: 'enable', user })
                            }}
                          >
                            Enable
                          </Button>
                        ) : (
                          <>
                            {!isSelf && (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                  setDialog({ kind: 'disable', user })
                                }}
                              >
                                Disable
                              </Button>
                            )}
                            <Button
                              size="sm"
                              variant="outline"
                              disabled={sendResetLink.isPending}
                              onClick={() => {
                                sendLink(user)
                              }}
                            >
                              Send reset link
                            </Button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      {dialog?.kind === 'roles' && (
        <EditRolesDialog
          user={dialog.user}
          isSelf={dialog.user.id === currentUser.data?.id}
          onClose={close}
        />
      )}
      {(dialog?.kind === 'disable' || dialog?.kind === 'enable') && (
        <UserStatusDialog user={dialog.user} enable={dialog.kind === 'enable'} onClose={close} />
      )}
    </section>
  )
}

function UserStatusDialog({
  user,
  enable,
  onClose,
}: {
  user: AdminUser
  enable: boolean
  onClose: () => void
}) {
  const disableUser = useDisableUser()
  const enableUser = useEnableUser()
  const change = enable ? enableUser : disableUser

  return (
    <ConfirmDialog
      title={enable ? 'Enable user' : 'Disable user'}
      description={
        enable
          ? `${user.email} will be able to sign in again.`
          : `${user.email} will be signed out and will not be able to sign in until you enable the account.`
      }
      confirmLabel={enable ? 'Enable' : 'Disable'}
      pending={change.isPending}
      error={change.error?.message}
      onConfirm={() => {
        change.mutate(user.id, { onSuccess: onClose })
      }}
      onClose={onClose}
    />
  )
}
