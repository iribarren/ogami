import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { draft } from './fixtures'
import { narrativeKey } from './narrative/useGameSystemNarrative'
import { gameSystemKey, useEditMetadata } from './useGameSystems'

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

describe('useEditMetadata', () => {
  it('marks the other section reads stale but not the GameSystem it just set from the answer', async () => {
    const { client, wrapper } = setup({
      [`PUT ${base}/metadata`]: () => Response.json({ ...draft, name: 'Renamed' }),
    })
    client.setQueryData(gameSystemKey('my-draft'), draft)
    client.setQueryData(narrativeKey('my-draft'), { version: 5 })
    const { result } = renderHook(() => useEditMetadata('my-draft'), { wrapper })

    result.current.mutate({ name: 'Renamed' })

    await waitFor(() => {
      expect(client.getQueryState(narrativeKey('my-draft'))?.isInvalidated).toBe(true)
    })
    expect(client.getQueryData(gameSystemKey('my-draft'))).toEqual({ ...draft, name: 'Renamed' })
    expect(client.getQueryState(gameSystemKey('my-draft'))?.isInvalidated).toBe(false)
  })
})
