import { QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { sheet } from '../fixtures'
import {
  SheetError,
  useDeleteSheetField,
  useGameSystemSheet,
  useMoveSheetField,
  useSaveSheetField,
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
  return { fakeApi, wrapper }
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
})
