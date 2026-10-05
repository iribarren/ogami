import type { QueryClient } from '@tanstack/react-query'
import { redirect, type ParsedLocation } from '@tanstack/react-router'

import type { ApiClient } from '@/shared/api/client'

import { loadCurrentUser, type CurrentUser, type Role } from './currentUser'

/** Thrown by a guard when the signed-in user lacks the role an area needs. */
export class ForbiddenError extends Error {
  override name = 'ForbiddenError'

  readonly role: Role

  constructor(role: Role) {
    super(`This page needs the ${role} role.`)
    this.role = role
  }
}

interface GuardOptions {
  context: { queryClient: QueryClient; api: ApiClient }
  location: ParsedLocation
}

/**
 * `beforeLoad` guard. Anonymous visitors are redirected to `/login` (and back
 * afterwards); signed-in users without `role` get a `ForbiddenError`, which the
 * route's error component shows as a forbidden page. Roles are independent.
 */
export async function requireRole(
  { context, location }: GuardOptions,
  role: Role,
): Promise<{ currentUser: CurrentUser }> {
  const currentUser = await loadCurrentUser(context.queryClient, context.api)
  if (currentUser === null) {
    // eslint-disable-next-line @typescript-eslint/only-throw-error -- TanStack Router redirects are thrown
    throw redirect({ to: '/login', search: { redirect: location.href } })
  }
  if (!currentUser.roles.includes(role)) {
    throw new ForbiddenError(role)
  }
  return { currentUser }
}
