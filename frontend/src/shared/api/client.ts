import createClient, { type ClientOptions } from 'openapi-fetch'

import type { paths } from './schema'

/**
 * Typed client for the Ogami API (ADR 0005). Request and response types come
 * from `schema.d.ts`, generated from the backend OpenAPI spec (`make api`).
 */
export type ApiClient = ReturnType<typeof createApiClient>

export function createApiClient(options: Pick<ClientOptions, 'baseUrl' | 'fetch'> = {}) {
  return createClient<paths>({
    // Same origin as the SPA (ADR 0006): requests carry the session cookie, no CORS.
    baseUrl: window.location.origin,
    ...options,
  })
}
