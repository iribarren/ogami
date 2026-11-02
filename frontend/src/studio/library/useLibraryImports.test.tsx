import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { narrativeKey } from '../narrative/useGameSystemNarrative'
import { gameSystemKey } from '../useGameSystems'
import { importsNone } from './fixtures'
import { libraryImportsKey, useDetachLibraryImport, useLibraryImports } from './useLibraryImports'

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

describe('a library import write while a read is in flight', () => {
  it('keeps the saved data when the older read answers afterwards', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = { ...importsNone, version: 9 }
    const { client, wrapper } = setup({
      [`GET ${base}/library-imports`]: async () => {
        if (++reads === 2) {
          await gate
        }
        return Response.json(importsNone)
      },
      [`DELETE ${base}/library-imports/oracles/names`]: () => Response.json(saved),
    })
    const { result } = renderHook(
      () => ({
        read: useLibraryImports('my-draft', true),
        write: useDetachLibraryImport('my-draft'),
      }),
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
      result.current.write.mutateAsync({ expectedVersion: 4, section: 'oracles', item: 'names' }),
    )
    release()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(client.getQueryData(libraryImportsKey('my-draft'))).toEqual(saved)
  })
})
