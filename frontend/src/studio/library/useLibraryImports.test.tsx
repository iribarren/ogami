import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { narrativeKey } from '../narrative/useGameSystemNarrative'
import { gameSystemKey } from '../useGameSystems'
import { importsNone } from './fixtures'
import { libraryImportsKey, useDetachLibraryImport } from './useLibraryImports'

const base = '/api/studio/game-systems/my-draft'

function setup(handlers: Record<string, FakeHandler>) {
  const { api } = createFakeApiClient(handlers)
  const client = createQueryClient()
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <ApiClientProvider client={api}>{children}</ApiClientProvider>
    </QueryClientProvider>
  )
  return { client, wrapper }
}

describe('the library import writes', () => {
  it('set the imports read from the answer and give the other section reads the new version, stale', async () => {
    const answer = { ...importsNone, version: 9 }
    const { client, wrapper } = setup({
      [`DELETE ${base}/library-imports/oracles/names`]: () => Response.json(answer),
    })
    client.setQueryData(libraryImportsKey('my-draft'), importsNone)
    client.setQueryData(narrativeKey('my-draft'), { version: 4 })
    client.setQueryData(gameSystemKey('my-draft'), {})
    const { result } = renderHook(() => useDetachLibraryImport('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 4, section: 'oracles', item: 'names' })

    await waitFor(() => {
      expect(client.getQueryState(narrativeKey('my-draft'))?.isInvalidated).toBe(true)
    })
    expect(client.getQueryData(libraryImportsKey('my-draft'))).toEqual(answer)
    expect(client.getQueryState(libraryImportsKey('my-draft'))?.isInvalidated).toBe(false)
    expect(client.getQueryData(narrativeKey('my-draft'))).toEqual({ version: 9 })
    expect(client.getQueryState(gameSystemKey('my-draft'))?.isInvalidated).toBe(true)
  })
})
