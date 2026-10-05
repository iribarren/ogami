import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'

import { createFakeApiClient, type FakeHandler } from './fakeApi'

/**
 * Renders one component with a query client and an API client that answers with
 * `handlers` instead of the network; no router, so it suits components that do
 * not navigate.
 */
export function renderWithApi(ui: ReactElement, handlers: Record<string, FakeHandler> = {}) {
  const { api, fakeApi } = createFakeApiClient(handlers)

  render(
    <QueryClientProvider client={createQueryClient()}>
      <ApiClientProvider client={api}>{ui}</ApiClientProvider>
    </QueryClientProvider>,
  )

  return { fakeApi }
}
