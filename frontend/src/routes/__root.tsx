import { createRootRouteWithContext, Outlet } from '@tanstack/react-router'

import { AppShell } from '@/app/AppShell'
import type { RouterContext } from '@/app/router'
import { PagePlaceholder } from '@/shared/ui/PagePlaceholder'

export const Route = createRootRouteWithContext<RouterContext>()({
  component: () => (
    <AppShell>
      <Outlet />
    </AppShell>
  ),
  notFoundComponent: () => (
    <PagePlaceholder title="Page not found" summary="This page does not exist." />
  ),
})
