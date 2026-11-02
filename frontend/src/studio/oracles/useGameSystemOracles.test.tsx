import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { oracles } from '../fixtures'
import { sheetKey } from '../sheet/useGameSystemSheet'
import { oraclesKey, useDeleteOracleTable, useGameSystemOracles } from './useGameSystemOracles'

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

describe('an oracle write while a read is in flight', () => {
  it('keeps the saved data when the older read answers afterwards', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = { ...oracles, version: 8 }
    const { client, wrapper } = setup({
      [`GET ${base}/oracles`]: async () => {
        if (++reads === 2) {
          await gate
        }
        return Response.json(oracles)
      },
      [`DELETE ${base}/oracle-tables/hits`]: () => Response.json(saved),
    })
    const { result } = renderHook(
      () => ({ read: useGameSystemOracles('my-draft'), write: useDeleteOracleTable('my-draft') }),
      { wrapper },
    )
    await waitFor(() => {
      expect(result.current.read.data).toBeDefined()
    })
    // A refetch whose answer is held: it was asked for before the save.
    void result.current.read.refetch()
    await waitFor(() => {
      expect(reads).toBe(2)
    })

    await act(() => result.current.write.mutateAsync({ expectedVersion: 7, table: 'hits' }))
    release()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(client.getQueryData(oraclesKey('my-draft'))).toEqual(saved)
  })
})
