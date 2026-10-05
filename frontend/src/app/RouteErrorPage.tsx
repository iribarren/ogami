import { ErrorComponent, type ErrorComponentProps } from '@tanstack/react-router'

import { ForbiddenError } from '@/shared/auth/requireRole'

import { areas } from './areas'
import { ForbiddenPage } from './ForbiddenPage'

/** Default route error view: a forbidden page for missing roles, the router's error view otherwise. */
export function RouteErrorPage(props: ErrorComponentProps) {
  const { error } = props
  if (error instanceof ForbiddenError) {
    const area = areas.find(({ role }) => role === error.role)?.label ?? 'this page'
    return <ForbiddenPage area={area} />
  }
  return <ErrorComponent {...props} />
}
