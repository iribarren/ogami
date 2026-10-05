import type { ReactNode } from 'react'

import { ApiClientContext } from './apiClientContext'
import type { ApiClient } from './client'

/** Makes an API client available to the hooks below it; tests pass a client with a fake `fetch`. */
export function ApiClientProvider({
  client,
  children,
}: {
  client: ApiClient
  children: ReactNode
}) {
  return <ApiClientContext value={client}>{children}</ApiClientContext>
}
