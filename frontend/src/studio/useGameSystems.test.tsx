import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
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
import {
  gameSystemKey,
  invalidateOtherDraftReads,
  sectionSegments,
  useEditMetadata,
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
