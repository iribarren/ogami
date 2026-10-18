import { InvitationsSection } from './InvitationsSection'
import { UsersSection } from './UsersSection'

/** The Admin home: the owner manages users and invitations (ADR 0034). */
export function AdminUsersPage() {
  return (
    <div className="space-y-8">
      <h1 className="font-heading text-page-title">Admin</h1>
      <UsersSection />
      <InvitationsSection />
    </div>
  )
}
