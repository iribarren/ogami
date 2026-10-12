import { Link, useMatches, useNavigate } from '@tanstack/react-router'
import type { ReactNode } from 'react'

import { ColorModeToggle } from '@/shared/theme/ColorModeToggle'
import { useCurrentUser, useLogout } from '@/shared/auth/currentUser'
import { Button } from '@/shared/ui/button'

import { areasFor } from './areas'

const linkClassName = 'text-muted-foreground transition-colors hover:text-foreground'

/**
 * Page frame shared by every route: brand, navigation to the areas the user
 * may open, the signed-in user with sign-out, and the routed content.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { data: currentUser, isPending } = useCurrentUser()
  const logout = useLogout()
  const navigate = useNavigate()
  const wide = useMatches({
    select: (matches) => matches.some((match) => match.staticData.layout === 'wide'),
  })
  const containerWidth = wide ? 'max-w-7xl' : 'max-w-5xl'

  function signOut() {
    logout.mutate(undefined, {
      onSuccess: () => {
        void navigate({ to: '/login' })
      },
    })
  }

  return (
    <div className="flex min-h-svh flex-col bg-background text-foreground">
      <header className="border-b">
        <div
          className={`mx-auto flex min-h-(--header-height) ${containerWidth} flex-wrap items-center gap-x-6 gap-y-2 px-4 py-2`}
        >
          <Link to="/" className="font-heading text-lg font-semibold tracking-tight">
            Ogami
          </Link>
          <nav aria-label="Main" className="flex flex-1 flex-wrap gap-x-6 gap-y-1 text-sm">
            {areasFor(currentUser).map(({ to, label }) => (
              <Link
                key={to}
                to={to}
                className={linkClassName}
                activeProps={{ className: 'font-medium text-foreground' }}
              >
                {label}
              </Link>
            ))}
            {!isPending && !currentUser && (
              <Link to="/login" className={`${linkClassName} ml-auto`}>
                Sign in
              </Link>
            )}
          </nav>
          <ColorModeToggle />
          {currentUser && (
            <div className="flex min-w-0 items-center gap-3 text-sm">
              <span className="min-w-0 truncate text-muted-foreground">{currentUser.email}</span>
              {logout.error && (
                <span role="alert" className="text-destructive">
                  {logout.error.message}
                </span>
              )}
              <Button variant="outline" size="sm" onClick={signOut} disabled={logout.isPending}>
                Sign out
              </Button>
            </div>
          )}
        </div>
      </header>
      <main className={`mx-auto w-full ${containerWidth} flex-1 px-4 py-10`}>{children}</main>
    </div>
  )
}
