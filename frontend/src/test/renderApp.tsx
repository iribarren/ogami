import { QueryClientProvider } from '@tanstack/react-query'
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router'
import { render } from '@testing-library/react'

import { createQueryClient } from '@/app/queryClient'
import { createAppRouter } from '@/app/router'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createApiClient } from '@/shared/api/client'
import type { components } from '@/shared/api/schema'

type Role = components['schemas']['Role']

/** Answers one fake API request; the key is `"<METHOD> <path>"`, e.g. `"GET /api/auth/me"`. */
export type FakeHandler = (request: Request) => Response | Promise<Response>

export interface FakeApi {
  /** Every request the app sent, in order, as `"<METHOD> <path>"`. */
  requests: string[]
}

const healthy: FakeHandler = () => Response.json({ status: 'ok', database: 'ok' })
const anonymous: FakeHandler = () =>
  Response.json({ error: 'Authentication required.' }, { status: 401 })

/** A signed-in user holding the given roles, as `GET /api/auth/me` returns it. */
export function signedInAs(...roles: Role[]): FakeHandler {
  return () =>
    Response.json({ id: '0190a8f0-0000-7000-8000-000000000001', email: 'ada@example.com', roles })
}

/**
 * Renders the whole app (router, query client, API client) at `path`, answering
 * API calls with `handlers` instead of the network. By default the API is healthy
 * and nobody is signed in.
 */
export function renderAppAt(path: string, handlers: Record<string, FakeHandler> = {}) {
  const fakeApi: FakeApi = { requests: [] }
  const routes: Record<string, FakeHandler> = {
    'GET /api/health': healthy,
    'GET /api/auth/me': anonymous,
    ...handlers,
  }

  const api = createApiClient({
    baseUrl: 'http://ogami.test',
    fetch: (input: Request) => {
      const key = `${input.method} ${new URL(input.url).pathname}`
      fakeApi.requests.push(key)
      const handler = routes[key]
      if (!handler) {
        return Promise.reject(new Error(`Unexpected API request: ${key}`))
      }
      return Promise.resolve(handler(input))
    },
  })
  const queryClient = createQueryClient()
  const router = createAppRouter({
    queryClient,
    api,
    history: createMemoryHistory({ initialEntries: [path] }),
  })

  render(
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={api}>
        <RouterProvider router={router} />
      </ApiClientProvider>
    </QueryClientProvider>,
  )

  return { router, queryClient, fakeApi }
}
