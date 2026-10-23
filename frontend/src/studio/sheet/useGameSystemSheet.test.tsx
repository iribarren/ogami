import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { narrative, sheet } from '../fixtures'
import { narrativeKey, useSaveNarrativeItem } from '../narrative/useGameSystemNarrative'
import { gameSystemKey, validationKey } from '../useGameSystems'
import {
  SheetError,
  sheetKey,
  useDeclareSheetLayout,
  useDeleteSheetField,
  useGameSystemSheet,
  useMoveSheetField,
  useRemoveSheetLayout,
  useSaveSheetField,
  useSaveSheetLayout,
} from './useGameSystemSheet'

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

describe('useGameSystemSheet', () => {
  it('does not retry a 404: the GameSystem is gone or not the user’s', async () => {
    const { fakeApi, wrapper } = setup({
      [`GET ${base}/sheet`]: () => Response.json({ error: 'No GameSystem' }, { status: 404 }),
    })

    const { result } = renderHook(() => useGameSystemSheet('my-draft'), { wrapper })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(fakeApi.requests).toEqual([`GET ${base}/sheet`])
  })
})

describe('the sheet writes', () => {
  it('carry the places that read the field in the error', async () => {
    const { wrapper } = setup({
      [`DELETE ${base}/sheet/fields/pow`]: () =>
        Response.json({ error: 'in use', paths: ['sheet.fields[3].formula'] }, { status: 422 }),
    })
    const { result } = renderHook(() => useDeleteSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow' })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(result.current.error).toBeInstanceOf(SheetError)
    expect((result.current.error as SheetError).paths).toEqual(['sheet.fields[3].formula'])
  })

  it('have no paths when the API gives none, and keep the status', async () => {
    const { wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () =>
        Response.json({ error: 'index: out of range' }, { status: 422 }),
    })
    const { result } = renderHook(() => useMoveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow', index: 9 })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(result.current.error).toMatchObject({
      status: 422,
      message: 'index: out of range',
      paths: [],
    })
  })

  it('write the answered version into the other cached section reads, so the next write sends it', async () => {
    const sent: unknown[] = []
    const { client, wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () => Response.json({ ...sheet, version: 8 }),
      [`POST ${base}/narrative/tags`]: async (request) => {
        sent.push(await request.json())
        return Response.json({ ...narrative, version: 9 }, { status: 201 })
      },
    })
    client.setQueryData(narrativeKey('my-draft'), { ...narrative, version: 7 })
    const { result } = renderHook(
      () => ({ move: useMoveSheetField('my-draft'), save: useSaveNarrativeItem('my-draft') }),
      { wrapper },
    )

    result.current.move.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(result.current.move.isSuccess).toBe(true)
    })
    const cached = client.getQueryData<{ version: number }>(narrativeKey('my-draft'))
    expect(cached?.version).toBe(8)
    result.current.save.mutate({
      expectedVersion: cached?.version ?? 0,
      section: 'tags',
      original: null,
      item: { key: 'crew', label: 'Crew' },
    })
    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 8, item: { key: 'crew', label: 'Crew' } }])
    })
  })

  it('leave a cached query that is not a section read alone', async () => {
    const { client, wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () => Response.json({ ...sheet, version: 8 }),
    })
    const releases = [{ version: 3 }]
    const release = { version: 3, notes: null }
    client.setQueryData([...gameSystemKey('my-draft'), 'releases'], releases)
    client.setQueryData([...gameSystemKey('my-draft'), 'releases', 3], release)
    const { result } = renderHook(() => useMoveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })
    expect(client.getQueryData([...gameSystemKey('my-draft'), 'releases'])).toBe(releases)
    expect(client.getQueryData([...gameSystemKey('my-draft'), 'releases', 3])).toBe(release)
  })

  it('use a generic message for a status the API does not explain', async () => {
    const { wrapper } = setup({
      [`POST ${base}/sheet/fields`]: () => new Response('x', { status: 500 }),
    })
    const { result } = renderHook(() => useSaveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, original: null, field: {} })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(result.current.error?.message).toBe('Saving the field failed with HTTP 500.')
  })

  it('put the answered sheet in the cache and refetch the page and its validation', async () => {
    const moved = { ...sheet, version: 8 }
    const { fakeApi, wrapper } = setup({
      [`GET ${base}/sheet`]: () => Response.json(sheet),
      [`POST ${base}/sheet/fields/pow/move`]: () => Response.json(moved),
      [`GET ${base}`]: () => Response.json({}),
      [`GET ${base}/validation`]: () => Response.json({ valid: true, issues: [] }),
    })
    const { result } = renderHook(
      () => ({ read: useGameSystemSheet('my-draft'), move: useMoveSheetField('my-draft') }),
      { wrapper },
    )
    await waitFor(() => {
      expect(result.current.read.data?.version).toBe(7)
    })

    result.current.move.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(result.current.read.data?.version).toBe(8)
    })
    expect(fakeApi.requests.filter((request) => request === `GET ${base}/sheet`)).toHaveLength(1)
  })

  it('mark the other section reads stale: they carry the version this write replaced', async () => {
    const { client, wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () => Response.json({ ...sheet, version: 8 }),
    })
    client.setQueryData(narrativeKey('my-draft'), { version: 7 })
    const { result } = renderHook(() => useMoveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(client.getQueryState(narrativeKey('my-draft'))?.isInvalidated).toBe(true)
    })
  })

  it('mark the GameSystem page and its validation stale after a write', async () => {
    const { client, wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () => Response.json({ ...sheet, version: 8 }),
    })
    client.setQueryData(gameSystemKey('my-draft'), {})
    client.setQueryData(validationKey('my-draft'), { valid: true, issues: [] })
    const { result } = renderHook(() => useMoveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(client.getQueryState(gameSystemKey('my-draft'))?.isInvalidated).toBe(true)
    })
    expect(client.getQueryState(validationKey('my-draft'))?.isInvalidated).toBe(true)
  })

  it('refetch the sheet on a 409, so the next write carries the version that won', async () => {
    let reads = 0
    const { fakeApi, wrapper } = setup({
      [`GET ${base}/sheet`]: () => Response.json({ ...sheet, version: 7 + reads++ }),
      [`POST ${base}/sheet/fields/pow/move`]: () =>
        Response.json({ error: 'changed' }, { status: 409 }),
    })
    const { result } = renderHook(
      () => ({ read: useGameSystemSheet('my-draft'), move: useMoveSheetField('my-draft') }),
      { wrapper },
    )
    await waitFor(() => {
      expect(result.current.read.data?.version).toBe(7)
    })

    result.current.move.mutate({ expectedVersion: 7, field: 'pow', index: 2 })

    await waitFor(() => {
      expect(result.current.read.data?.version).toBe(8)
    })
    expect(fakeApi.requests.filter((request) => request === `GET ${base}/sheet`)).toHaveLength(2)
  })

  it('leave the cached sheet alone on a refusal that is not a conflict', async () => {
    const { client, wrapper } = setup({
      [`POST ${base}/sheet/fields/pow/move`]: () =>
        Response.json({ error: 'index: out of range' }, { status: 422 }),
    })
    client.setQueryData(sheetKey('my-draft'), sheet)
    const { result } = renderHook(() => useMoveSheetField('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7, field: 'pow', index: 9 })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })
    expect(client.getQueryState(sheetKey('my-draft'))?.isInvalidated).toBe(false)
  })
})

describe('the layout writes', () => {
  const layout = { columns: 2, groups: [{ name: 'Stats', columns: 2, span: 1 }] }
  const after = { ...sheet, version: 8 }
  const useWrites = () => ({
    save: useSaveSheetLayout('my-draft'),
    declare: useDeclareSheetLayout('my-draft'),
    remove: useRemoveSheetLayout('my-draft'),
  })
  type Hooks = ReturnType<typeof useWrites>
  const writes: [string, string, (h: Hooks) => void, (h: Hooks) => Error | null][] = [
    [
      'save',
      `PUT ${base}/sheet/layout`,
      (h) => {
        h.save.mutate({ expectedVersion: 7, layout })
      },
      (h) => h.save.error,
    ],
    [
      'declare',
      `POST ${base}/sheet/layout/declare`,
      (h) => {
        h.declare.mutate({ expectedVersion: 7 })
      },
      (h) => h.declare.error,
    ],
    [
      'remove',
      `DELETE ${base}/sheet/layout`,
      (h) => {
        h.remove.mutate({ expectedVersion: 7 })
      },
      (h) => h.remove.error,
    ],
  ]

  it.each(writes)(
    '%s: put the answer in the cache and mark the page and validation stale',
    async (_name, route, run) => {
      const { client, wrapper } = setup({ [route]: () => Response.json(after) })
      client.setQueryData(gameSystemKey('my-draft'), {})
      client.setQueryData(validationKey('my-draft'), { valid: true, issues: [] })
      const { result } = renderHook(useWrites, { wrapper })

      run(result.current)

      await waitFor(() => {
        expect(client.getQueryData(sheetKey('my-draft'))).toEqual(after)
      })
      expect(client.getQueryState(gameSystemKey('my-draft'))?.isInvalidated).toBe(true)
      expect(client.getQueryState(validationKey('my-draft'))?.isInvalidated).toBe(true)
    },
  )

  it.each(writes)(
    '%s: refetch the sheet on a 409 and keep the message',
    async (_name, route, run, error) => {
      const { client, wrapper } = setup({
        [route]: () => Response.json({ error: 'changed' }, { status: 409 }),
      })
      client.setQueryData(sheetKey('my-draft'), sheet)
      const { result } = renderHook(useWrites, { wrapper })

      run(result.current)

      await waitFor(() => {
        expect(error(result.current)).not.toBeNull()
      })
      expect(error(result.current)).toMatchObject({ status: 409, message: 'changed' })
      expect(client.getQueryState(sheetKey('my-draft'))?.isInvalidated).toBe(true)
    },
  )

  it('send the columns and the groups with the version, previousName included', async () => {
    let body: unknown
    const { wrapper } = setup({
      [`PUT ${base}/sheet/layout`]: async (request) => {
        body = await request.json()
        return Response.json(after)
      },
    })
    const { result } = renderHook(() => useSaveSheetLayout('my-draft'), { wrapper })
    const groups = [{ name: 'Attributes', previousName: 'Stats', columns: 2, span: 1 }]

    result.current.mutate({ expectedVersion: 7, layout: { columns: 2, groups } })

    await waitFor(() => {
      expect(body).toEqual({ expectedVersion: 7, columns: 2, groups })
    })
  })

  it('use a generic message for a status the API does not explain', async () => {
    const { wrapper } = setup({
      [`DELETE ${base}/sheet/layout`]: () => new Response('x', { status: 500 }),
    })
    const { result } = renderHook(() => useRemoveSheetLayout('my-draft'), { wrapper })

    result.current.mutate({ expectedVersion: 7 })

    await waitFor(() => {
      expect(result.current.error?.message).toBe('Removing the layout failed with HTTP 500.')
    })
  })
})
