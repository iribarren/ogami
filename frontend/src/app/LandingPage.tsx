import { Link } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import { Card, CardDescription, CardFooter, CardHeader, CardTitle } from '@/shared/ui/card'

const areas = [
  {
    to: '/play',
    title: 'Play',
    description: 'Run solo campaigns with a guided narrative flow, sheets, checks and oracles.',
  },
  {
    to: '/studio',
    title: 'Studio',
    description: 'Author game systems: sheet templates, checks, narrative flows and oracles.',
  },
  {
    to: '/admin',
    title: 'Admin',
    description: 'Manage users, roles and app settings.',
  },
] as const

export function LandingPage() {
  return (
    <div className="space-y-10">
      <section className="space-y-3">
        <h1 className="font-heading text-4xl font-bold tracking-tight">Ogami</h1>
        <p className="max-w-2xl text-lg text-muted-foreground">
          A companion for solo tabletop RPG play.
        </p>
      </section>
      <section className="grid gap-4 sm:grid-cols-3">
        {areas.map(({ to, title, description }) => (
          <Card key={to}>
            <CardHeader>
              <CardTitle>{title}</CardTitle>
              <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardFooter>
              <Button asChild variant="outline">
                <Link to={to}>Go to {title}</Link>
              </Button>
            </CardFooter>
          </Card>
        ))}
      </section>
    </div>
  )
}
