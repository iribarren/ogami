import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { checksKey } from './checks/useGameSystemChecks'
import { draft } from './fixtures'
import { libraryImportsKey } from './library/useLibraryImports'
import { narrativeKey } from './narrative/useGameSystemNarrative'
import { oraclesKey } from './oracles/useGameSystemOracles'
import { sheetKey } from './sheet/useGameSystemSheet'
import { useRestoreGameSystem } from './useGameSystemReleases'
import {
  gameSystemKey,
  invalidateOtherDraftReads,
  sectionSegments,
  useEditMetadata,
  useStudioGameSystem,
  validationKey,
} from './useGameSystems'

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

describe('invalidateOtherDraftReads', () => {
  it('lists the segment of every section read key builder', () => {
    const builders = [narrativeKey, oraclesKey, sheetKey, checksKey, libraryImportsKey]

    expect(builders.map((build) => build('my-draft').at(-1))).toEqual(sectionSegments)
  })

  it('hands the new version only to the section reads, and only marks the rest stale', () => {
    const { client } = setup({})
    client.setQueryData(narrativeKey('my-draft'), { version: 5 })
    client.setQueryData(validationKey('my-draft'), { version: 5, issues: [] })

    invalidateOtherDraftReads(client, 'my-draft', gameSystemKey('my-draft'), 9)

    expect(client.getQueryData(narrativeKey('my-draft'))).toEqual({ version: 9 })
    expect(client.getQueryState(narrativeKey('my-draft'))?.isInvalidated).toBe(true)
    expect(client.getQueryData(validationKey('my-draft'))).toEqual({ version: 5, issues: [] })
    expect(client.getQueryState(validationKey('my-draft'))?.isInvalidated).toBe(true)
  })
})

describe('a GameSystem write while a read is in flight', () => {
  it.each([
    ['saving the metadata', 'PUT', '/metadata', useEditMetadata, { name: 'Renamed' }],
    ['restoring a release', 'POST', '/restore', useRestoreGameSystem, 2],
  ] as const)(
    'keeps the data %s stored when the older read answers afterwards',
    async (_, method, path, useWrite, input) => {
      let reads = 0
      let release: () => void = () => undefined
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      const saved = { ...draft, name: 'Renamed' }
      const { client, wrapper } = setup({
        [`GET ${base}`]: async () => {
          if (++reads === 2) {
            await gate
          }
          return Response.json(draft)
        },
        [`${method} ${base}${path}`]: () => Response.json(saved),
      })
      const { result } = renderHook(
        () => ({ read: useStudioGameSystem('my-draft'), write: useWrite('my-draft') }),
        { wrapper },
      )
      await waitFor(() => {
        expect(result.current.read.data).toBeDefined()
      })
      // A refetch whose answer is held: it was asked for before the write.
      void result.current.read.refetch()
      await waitFor(() => {
        expect(reads).toBe(2)
      })

      await act(() => result.current.write.mutateAsync(input as never))
      release()
      await new Promise((resolve) => setTimeout(resolve, 50))

      expect(client.getQueryData(gameSystemKey('my-draft'))).toEqual(saved)
    },
  )
})
