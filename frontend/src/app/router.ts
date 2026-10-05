import type { QueryClient } from '@tanstack/react-query'
import { createRouter, type RouterHistory } from '@tanstack/react-router'

import { routeTree } from '@/routeTree.gen'
import type { ApiClient } from '@/shared/api/client'

import { RouteErrorPage } from './RouteErrorPage'

/** Values every route can read from its loader or `beforeLoad` context. */
export interface RouterContext {
  queryClient: QueryClient
  /** The same client the `ApiClientProvider` hands to components, for guards and loaders. */
  api: ApiClient
}

interface CreateAppRouterOptions {
  queryClient: QueryClient
  api: ApiClient
  /** Defaults to browser history; tests pass a memory history. */
  history?: RouterHistory
}

export function createAppRouter({ queryClient, api, history }: CreateAppRouterOptions) {
  return createRouter({
    routeTree,
    context: { queryClient, api } satisfies RouterContext,
    ...(history ? { history } : {}),
    defaultPreload: 'intent',
    // Let TanStack Query own data freshness; the router just triggers loads.
    defaultPreloadStaleTime: 0,
    scrollRestoration: true,
    // Shows a forbidden page when a role guard rejects the user.
    defaultErrorComponent: RouteErrorPage,
  })
}

declare module '@tanstack/react-router' {
  interface Register {
    router: ReturnType<typeof createAppRouter>
  }
}
