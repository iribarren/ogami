import { useId } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Checkbox } from '@/shared/ui/checkbox'
import { Label } from '@/shared/ui/label'

import type { Role } from './types'

/** The roles in the order Admin shows them, with their glossary names. */
const ROLES: { role: Role; label: string }[] = [
  { role: 'SOLO_PLAYER', label: 'Solo player' },
  { role: 'GAME_MANAGER', label: 'Game manager' },
  { role: 'OWNER', label: 'Owner' },
]

export function RoleBadges({ roles }: { roles: Role[] }) {
  return (
    <span className="flex flex-wrap gap-1">
      {ROLES.filter(({ role }) => roles.includes(role)).map(({ role, label }) => (
        <Badge key={role} variant="secondary">
          {label}
        </Badge>
      ))}
    </span>
  )
}

/**
 * One checkbox per role. Roles are independent (ADR 0006), so none implies another. `locked`
 * keeps a role checked and disabled: the owner cannot take OWNER from their own account.
 */
export function RoleCheckboxes({
  value,
  onChange,
  locked,
}: {
  value: Role[]
  onChange: (roles: Role[]) => void
  locked?: Role
}) {
  const prefix = useId()

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">Roles</legend>
      {ROLES.map(({ role, label }) => (
        <div key={role} className="flex items-center gap-2">
          <Checkbox
            id={`${prefix}-${role}`}
            checked={value.includes(role)}
            disabled={role === locked}
            onCheckedChange={(checked) => {
              onChange(
                ROLES.map((r) => r.role).filter((r) =>
                  r === role ? checked === true : value.includes(r),
                ),
              )
            }}
          />
          <Label htmlFor={`${prefix}-${role}`}>{label}</Label>
        </div>
      ))}
      {value.length === 0 && (
        <p className="text-sm text-muted-foreground">Choose at least one role.</p>
      )}
      {locked && (
        <p className="text-sm text-muted-foreground">You cannot remove your own Owner role.</p>
      )}
    </fieldset>
  )
}
