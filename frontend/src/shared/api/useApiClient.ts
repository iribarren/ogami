import { use } from 'react'

import { ApiClientContext } from './apiClientContext'
import { createApiClient, type ApiClient } from './client'

let defaultClient: ApiClient | undefined

/** The client from the nearest `ApiClientProvider`, or a same-origin default client. */
export function useApiClient(): ApiClient {
  const client = use(ApiClientContext)
  if (client) {
    return client
  }
  defaultClient ??= createApiClient()
  return defaultClient
}
