import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'

const areas = [
  { to: '/play', label: 'Play' },
  { to: '/studio', label: 'Studio' },
  { to: '/admin', label: 'Admin' },
] as const

/** Page frame shared by every route: brand, main navigation and the routed content. */
export function AppShell({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-svh flex-col bg-background text-foreground">
      <header className="border-b">
        <div className="mx-auto flex h-14 max-w-5xl items-center gap-8 px-4">
          <Link to="/" className="font-heading text-lg font-semibold tracking-tight">
            Ogami
          </Link>
          <nav aria-label="Main" className="flex gap-6 text-sm">
            {areas.map(({ to, label }) => (
              <Link
                key={to}
                to={to}
                className="text-muted-foreground transition-colors hover:text-foreground"
                activeProps={{ className: 'font-medium text-foreground' }}
              >
                {label}
              </Link>
            ))}
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">{children}</main>
    </div>
  )
}
