import type { CurrentUser, Role } from '@/shared/auth/currentUser'

/** The product areas and the role each one needs (roles are independent, ADR 0006). */
export const areas = [
  { to: '/play', label: 'Play', role: 'SOLO_PLAYER' },
  { to: '/studio', label: 'Studio', role: 'GAME_MANAGER' },
  { to: '/admin', label: 'Admin', role: 'OWNER' },
] as const satisfies readonly { to: string; label: string; role: Role }[]

export function areasFor(user: CurrentUser | null | undefined) {
  return areas.filter(({ role }) => user?.roles.includes(role) === true)
}

/** Where a user lands after signing in without a redirect: their first area, else the landing page. */
export function homePathFor(user: CurrentUser): string {
  return areasFor(user)[0]?.to ?? '/'
}
