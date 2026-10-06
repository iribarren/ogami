import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  createMemoryHistory,
  createRootRoute,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router'
import { createContext, use, useState, type ReactNode } from 'react'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'

import { createFakeApiClient, type FakeHandler } from './fakeApi'

// The router is created once, so the root route reads the current children from context
// instead of closing over the first ones.
const StoryChildren = createContext<ReactNode>(null)

function RenderStoryChildren() {
  return use(StoryChildren)
}

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
      routeTree: createRootRoute({ component: RenderStoryChildren }),
      history: createMemoryHistory({ initialEntries: ['/'] }),
    }),
  }))

  return (
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={api}>
        <StoryChildren value={children}>
          <RouterProvider router={router} />
        </StoryChildren>
      </ApiClientProvider>
    </QueryClientProvider>
  )
}
