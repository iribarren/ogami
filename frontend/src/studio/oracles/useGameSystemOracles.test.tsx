import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { oracles } from '../fixtures'
import { sheetKey } from '../sheet/useGameSystemSheet'
import { useDeleteOracleTable } from './useGameSystemOracles'

const base = '/api/studio/game-systems/my-draft'

function setup(handlers: Record<string, FakeHandler>) {
  const { api, fakeApi } = createFakeApiClient(handlers)
  const client = createQueryClient()
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <ApiClientProvider client={api}>{children}</ApiClientProvider>
    </QueryClientProvider>
  )
  return { client, fakeApi, wrapper }
}

describe('the oracle writes', () => {
  it('mark the other section reads stale: they carry the version this write replaced', async () => {
    const { client, wrapper } = setup({
      [`DELETE ${base}/oracle-tables/hits`]: () => Response.json({ ...oracles, version: 8 }),
    })
    client.setQueryData(sheetKey('my-draft'), { version: 7 })
    const { result } = renderHook(() => useDeleteOracleTable('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, table: 'hits' })

    await waitFor(() => {
      expect(client.getQueryState(sheetKey('my-draft'))?.isInvalidated).toBe(true)
    })
  })
})
