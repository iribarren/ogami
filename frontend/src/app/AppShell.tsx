import { Link, useNavigate } from '@tanstack/react-router'
import type { ReactNode } from 'react'

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
        <div className="mx-auto flex h-14 max-w-5xl items-center gap-8 px-4">
          <Link to="/" className="font-heading text-lg font-semibold tracking-tight">
            Ogami
          </Link>
          <nav aria-label="Main" className="flex flex-1 gap-6 text-sm">
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
          {currentUser && (
            <div className="flex items-center gap-3 text-sm">
              <span className="text-muted-foreground">{currentUser.email}</span>
              <Button variant="outline" size="sm" onClick={signOut} disabled={logout.isPending}>
                Sign out
              </Button>
            </div>
          )}
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">{children}</main>
    </div>
  )
}
