import { useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'

import { adminDate } from './adminDate'
import { ConfirmDialog } from './ConfirmDialog'
import { InviteDialog } from './InviteDialog'
import { Notice, type NoticeState } from './Notice'
import { RoleBadges } from './roles'
import type { AdminInvitation } from './types'
import {
  useAdminInvitations,
  useResendInvitation,
  useRevokeInvitation,
} from './useAdminInvitations'

/** The pending invitations: invite, resend (renews an expired link) and revoke after a confirmation. */
export function InvitationsSection() {
  const invitations = useAdminInvitations()
  const resend = useResendInvitation()
  const [revoking, setRevoking] = useState<AdminInvitation | null>(null)
  const [notice, setNotice] = useState<NoticeState>(null)

  function resendTo(invitation: AdminInvitation) {
    setNotice(null)
    resend.mutate(invitation.id, {
      onSuccess: () => {
        setNotice({ kind: 'success', text: `Invitation sent to ${invitation.email}` })
      },
      onError: (error) => {
        setNotice({ kind: 'error', text: error.message })
      },
    })
  }

  return (
    <section aria-labelledby="admin-invitations" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="admin-invitations" className="font-heading text-xl font-semibold">
          Invitations
        </h2>
        <InviteDialog
          onInvited={(email) => {
            setNotice({ kind: 'success', text: `Invitation sent to ${email}` })
          }}
        />
      </div>
      <Notice notice={notice} />
      {invitations.isPending && <p className="text-muted-foreground">Loading the invitations…</p>}
      {invitations.isError && (
        <p role="alert" className="text-sm text-destructive">
          {invitations.error.message}
        </p>
      )}
      {invitations.data?.length === 0 && (
        <p className="text-muted-foreground">No pending invitations.</p>
      )}
      {invitations.data && invitations.data.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b text-muted-foreground">
              <tr>
                <th className="py-2 pr-4 font-medium">Email</th>
                <th className="py-2 pr-4 font-medium">Roles</th>
                <th className="py-2 pr-4 font-medium">Expires</th>
                <th className="py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {invitations.data.map((invitation) => (
                <tr key={invitation.id} className="border-b align-middle">
                  <td className="py-2 pr-4 break-all">{invitation.email}</td>
                  <td className="py-2 pr-4">
                    <RoleBadges roles={invitation.roles} />
                  </td>
                  <td className="py-2 pr-4 whitespace-nowrap">
                    {adminDate(invitation.expiresAt)}{' '}
                    {invitation.expired && <Badge variant="warning">Expired</Badge>}
                  </td>
                  <td className="py-2">
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={resend.isPending}
                        onClick={() => {
                          resendTo(invitation)
                        }}
                      >
                        Resend
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setRevoking(invitation)
                        }}
                      >
                        Revoke
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {revoking && (
        <RevokeDialog
          invitation={revoking}
          onClose={() => {
            setRevoking(null)
          }}
        />
      )}
    </section>
  )
}

function RevokeDialog({
  invitation,
  onClose,
}: {
  invitation: AdminInvitation
  onClose: () => void
}) {
  const revoke = useRevokeInvitation()

  return (
    <ConfirmDialog
      title="Revoke invitation"
      description={`The link sent to ${invitation.email} will stop working.`}
      confirmLabel="Revoke"
      pending={revoke.isPending}
      error={revoke.error?.message}
      onConfirm={() => {
        revoke.mutate(invitation.id, { onSuccess: onClose })
      }}
      onClose={onClose}
    />
  )
}
