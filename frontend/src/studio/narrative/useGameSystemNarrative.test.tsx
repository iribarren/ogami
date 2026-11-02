import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { narrative } from '../fixtures'
import { sheetKey } from '../sheet/useGameSystemSheet'
import {
  NarrativeError,
  narrativeKey,
  useDeleteNarrativeItem,
  useGameSystemNarrative,
  useSaveNarrativeItem,
} from './useGameSystemNarrative'

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

const validation = () => Response.json({ valid: true, issues: [] })
const gameSystem = () => Response.json({})

describe('useGameSystemNarrative', () => {
  it('does not retry a 404: the GameSystem is gone or not the user’s', async () => {
    const { fakeApi, wrapper } = setup({
      [`GET ${base}/narrative`]: () => Response.json({ error: 'No GameSystem' }, { status: 404 }),
    })

    const { result } = renderHook(() => useGameSystemNarrative('my-draft'), { wrapper })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(fakeApi.requests).toEqual([`GET ${base}/narrative`])
  })
})

describe('useDeleteNarrativeItem', () => {
  it('carries the places that use the item in the error', async () => {
    const { wrapper } = setup({
      [`DELETE ${base}/narrative/trackers/heat`]: () =>
        Response.json(
          { error: 'The tracker "heat" is in use.', paths: ['flows[0].trackers'] },
          { status: 422 },
        ),
    })
    const { result } = renderHook(() => useDeleteNarrativeItem('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 5, section: 'trackers', item: 'heat' })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(result.current.error).toBeInstanceOf(NarrativeError)
    expect((result.current.error as NarrativeError).paths).toEqual(['flows[0].trackers'])
  })

  it('has no paths when the API gives none', async () => {
    const { wrapper } = setup({
      [`DELETE ${base}/narrative/trackers/heat`]: () =>
        Response.json({ error: 'No tracker "heat".' }, { status: 404 }),
    })
    const { result } = renderHook(() => useDeleteNarrativeItem('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 5, section: 'trackers', item: 'heat' })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect((result.current.error as NarrativeError).paths).toEqual([])
    expect(result.current.error?.message).toBe('No tracker "heat".')
  })
})

describe('useSaveNarrativeItem', () => {
  it('renames through PUT with the original key', async () => {
    const sent: { method: string; path: string; body: unknown }[] = []
    const record: FakeHandler = async (request) => {
      sent.push({
        method: request.method,
        path: new URL(request.url).pathname,
        body: await request.json(),
      })
      return Response.json({ ...narrative, version: 6 })
    }
    const { wrapper } = setup({
      [`PUT ${base}/narrative/tags/gang`]: record,
      [`GET ${base}`]: gameSystem,
      [`GET ${base}/validation`]: validation,
    })
    const { result } = renderHook(() => useSaveNarrativeItem('my-draft'), { wrapper })

    result.current.mutate({
      expectedVersion: 5,
      section: 'tags',
      original: 'gang',
      item: { key: 'crew', label: 'Crew' },
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })
    expect(sent).toEqual([
      {
        method: 'PUT',
        path: `${base}/narrative/tags/gang`,
        body: { expectedVersion: 5, item: { key: 'crew', label: 'Crew' } },
      },
    ])
  })

  it('replaces the cached narrative, so the next write sends the new version', async () => {
    const versions: number[] = []
    const { wrapper } = setup({
      [`GET ${base}/narrative`]: () => Response.json(narrative),
      [`POST ${base}/narrative/tags`]: async (request) => {
        const body = (await request.json()) as { expectedVersion: number }
        versions.push(body.expectedVersion)
        return Response.json({ ...narrative, version: body.expectedVersion + 1 }, { status: 201 })
      },
      [`GET ${base}`]: gameSystem,
      [`GET ${base}/validation`]: validation,
    })
    const { result } = renderHook(
      () => ({
        narrative: useGameSystemNarrative('my-draft'),
        save: useSaveNarrativeItem('my-draft'),
      }),
      { wrapper },
    )
    await waitFor(() => {
      expect(result.current.narrative.data?.version).toBe(5)
    })
    const write = (key: string) => {
      result.current.save.mutate({
        expectedVersion: result.current.narrative.data?.version ?? 0,
        section: 'tags',
        original: null,
        item: { key, label: key },
      })
    }

    write('one')
    await waitFor(() => {
      expect(result.current.narrative.data?.version).toBe(6)
    })
    write('two')
    await waitFor(() => {
      expect(result.current.narrative.data?.version).toBe(7)
    })

    expect(versions).toEqual([5, 6])
  })
})

describe('a save while a read is in flight', () => {
  it('keeps the saved narrative when the older read answers afterwards', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = { ...narrative, version: 6 }
    const { client, wrapper } = setup({
      [`GET ${base}/narrative`]: async () => {
        if (++reads === 2) {
          await gate
        }
        return Response.json(narrative)
      },
      [`POST ${base}/narrative/tags`]: () => Response.json(saved, { status: 201 }),
      [`GET ${base}`]: gameSystem,
      [`GET ${base}/validation`]: validation,
    })
    const { result } = renderHook(
      () => ({
        narrative: useGameSystemNarrative('my-draft'),
        save: useSaveNarrativeItem('my-draft'),
      }),
      { wrapper },
    )
    await waitFor(() => {
      expect(result.current.narrative.data?.version).toBe(5)
    })
    // A refetch whose answer is held: it was asked for before the save and carries version 5.
    void result.current.narrative.refetch()
    await waitFor(() => {
      expect(reads).toBe(2)
    })

    result.current.save.mutate({
      expectedVersion: 5,
      section: 'tags',
      original: null,
      item: { key: 'one', label: 'one' },
    })
    await waitFor(() => {
      expect(result.current.save.isSuccess).toBe(true)
    })
    release()
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(client.getQueryData(narrativeKey('my-draft'))).toEqual(saved)
  })
})

describe('the narrative writes', () => {
  it('mark the other section reads stale: they carry the version this write replaced', async () => {
    const { client, wrapper } = setup({
      [`DELETE ${base}/narrative/trackers/heat`]: () => Response.json({ ...narrative, version: 6 }),
    })
    client.setQueryData(sheetKey('my-draft'), { version: 5 })
    const { result } = renderHook(() => useDeleteNarrativeItem('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 5, section: 'trackers', item: 'heat' })

    await waitFor(() => {
      expect(client.getQueryState(sheetKey('my-draft'))?.isInvalidated).toBe(true)
    })
  })
})
