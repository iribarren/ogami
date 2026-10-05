import { createApiClient } from '@/shared/api/client'

/** Answers one fake API request; the key is `"<METHOD> <path>"`, e.g. `"GET /api/auth/me"`. */
export type FakeHandler = (request: Request) => Response | Promise<Response>

export interface FakeApi {
  /** Every request the app sent, in order, as `"<METHOD> <path>"`. */
  requests: string[]
}

/**
 * An API client whose `fetch` answers with `routes` instead of the network.
 * Tests and stories use it; any request without a handler fails loudly.
 */
export function createFakeApiClient(routes: Record<string, FakeHandler>) {
  const fakeApi: FakeApi = { requests: [] }
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

  return { api, fakeApi }
}
