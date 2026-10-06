import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  createMemoryHistory,
  createRootRoute,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router'
import { useState, type ReactNode } from 'react'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'

import { createFakeApiClient, type FakeHandler } from './fakeApi'

/**
 * Story wrapper for components that query the API and link or navigate: a fresh query
 * client, a fake API answering with `handlers`, and a memory router whose every path
 * renders `children` (navigating does not leave the story).
 */
export function StoryProviders({
  handlers,
  children,
}: {
  handlers: Record<string, FakeHandler>
  children: ReactNode
}) {
  const [{ api, queryClient, router }] = useState(() => ({
    api: createFakeApiClient(handlers).api,
    queryClient: new QueryClient({ defaultOptions: { queries: { retry: false } } }),
    router: createRouter({
      routeTree: createRootRoute({ component: () => children }),
      history: createMemoryHistory({ initialEntries: ['/'] }),
    }),
  }))

  return (
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={api}>
        <RouterProvider router={router} />
      </ApiClientProvider>
    </QueryClientProvider>
  )
}
