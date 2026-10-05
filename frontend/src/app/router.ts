import type { QueryClient } from '@tanstack/react-query'
import { createRouter, type RouterHistory } from '@tanstack/react-router'

import { routeTree } from '@/routeTree.gen'

/** Values every route can read from its loader or `beforeLoad` context. */
export interface RouterContext {
  queryClient: QueryClient
}

interface CreateAppRouterOptions {
  queryClient: QueryClient
  /** Defaults to browser history; tests pass a memory history. */
  history?: RouterHistory
}

export function createAppRouter({ queryClient, history }: CreateAppRouterOptions) {
  return createRouter({
    routeTree,
    context: { queryClient } satisfies RouterContext,
    ...(history ? { history } : {}),
    defaultPreload: 'intent',
    // Let TanStack Query own data freshness; the router just triggers loads.
    defaultPreloadStaleTime: 0,
    scrollRestoration: true,
  })
}

declare module '@tanstack/react-router' {
  interface Register {
    router: ReturnType<typeof createAppRouter>
  }
}
