import { createFileRoute, redirect } from '@tanstack/react-router'

import { homePathFor } from '@/app/areas'
import { LoginPage } from '@/app/LoginPage'
import { loadCurrentUser } from '@/shared/auth/currentUser'
import { safeRedirect } from '@/shared/auth/safeRedirect'

interface LoginSearch {
  /** Same-origin path to return to after signing in; anything else is dropped. */
  redirect?: string | undefined
}

export const Route = createFileRoute('/login')({
  // Returns the key even when unsafe: the router merges validated search over the
  // raw one, so leaving `redirect` out would keep the raw value.
  validateSearch: (search: Record<string, unknown>): LoginSearch => ({
    redirect: safeRedirect(search.redirect),
  }),
  beforeLoad: async ({ context, search }) => {
    // If the API cannot say who is signed in, still offer the form: treat as anonymous.
    const currentUser = await loadCurrentUser(context.queryClient, context.api).catch(() => null)
    if (currentUser !== null) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error -- TanStack Router redirects are thrown
      throw redirect({ href: search.redirect ?? homePathFor(currentUser), replace: true })
    }
  },
  component: LoginRoute,
})

function LoginRoute() {
  const { redirect: redirectTo } = Route.useSearch()
  return <LoginPage redirectTo={redirectTo} />
}
