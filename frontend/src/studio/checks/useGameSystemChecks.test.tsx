import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { checks } from '../fixtures'
import { sheetKey } from '../sheet/useGameSystemSheet'
import { checksKey, useDeleteCheck, useGameSystemChecks } from './useGameSystemChecks'

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

describe('the check writes', () => {
  it('mark the other section reads stale: they carry the version this write replaced', async () => {
    const { client, wrapper } = setup({
      [`DELETE ${base}/checks/hit`]: () => Response.json({ ...checks, version: 8 }),
    })
    client.setQueryData(sheetKey('my-draft'), { version: 7 })
    const { result } = renderHook(() => useDeleteCheck('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, check: 'hit' })

    await waitFor(() => {
      expect(client.getQueryState(sheetKey('my-draft'))?.isInvalidated).toBe(true)
    })
  })
})

describe('a check write while a read is in flight', () => {
  it('keeps the saved data when the older read answers afterwards', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = { ...checks, version: checks.version + 1 }
    const { client, wrapper } = setup({
      [`GET ${base}/checks`]: async () => {
        if (++reads === 2) {
          await gate
        }
        return Response.json(checks)
      },
      [`DELETE ${base}/checks/hit`]: () => Response.json(saved),
    })
    const { result } = renderHook(
      () => ({ read: useGameSystemChecks('my-draft'), write: useDeleteCheck('my-draft') }),
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

    await act(() =>
      result.current.write.mutateAsync({ expectedVersion: checks.version, check: 'hit' }),
    )
    release()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(client.getQueryData(checksKey('my-draft'))).toEqual(saved)
  })
})
