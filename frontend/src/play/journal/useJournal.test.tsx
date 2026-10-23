import { QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { gateNote, recordedEntry } from './fixtures'
import { journalKey, useJournal, useRecordNote, type JournalEntry } from './useJournal'

const campaignId = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f0001'
const newNote = recordedEntry({ kind: 'note', text: 'A new note.', attachments: [] })

/**
 * A journal whose first read answers [gateNote] and whose next read the test answers by hand,
 * so a refetch can be in flight while a note is recorded. `record` answers the note request.
 */
function setup(record: (answerRefetch: (entries: JournalEntry[]) => void) => FakeHandler) {
  let reads = 0
  let answerRefetch: (entries: JournalEntry[]) => void = () => undefined
  const { api } = createFakeApiClient({
    [`GET /api/campaigns/${campaignId}/journal`]: () => {
      reads += 1
      if (reads === 1) {
        return Response.json([gateNote])
      }
      return new Promise<Response>((resolve) => {
        answerRefetch = (entries) => {
          resolve(Response.json(entries))
        }
      })
    },
    [`POST /api/campaigns/${campaignId}/journal/notes`]: (request) =>
      record((entries) => {
        answerRefetch(entries)
      })(request),
  })
  const client = createQueryClient()
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <ApiClientProvider client={api}>{children}</ApiClientProvider>
    </QueryClientProvider>
  )
  const hooks = renderHook(
    () => ({ journal: useJournal(campaignId), note: useRecordNote(campaignId) }),
    { wrapper },
  )

  return {
    client,
    ...hooks,
    reads: () => reads,
    answerRefetch: (entries: JournalEntry[]) => {
      answerRefetch(entries)
    },
  }
}

async function startRefetchAndRecord(hooks: ReturnType<typeof setup>) {
  await waitFor(() => {
    expect(hooks.result.current.journal.data).toEqual([gateNote])
  })
  act(() => {
    void hooks.client.invalidateQueries({ queryKey: journalKey(campaignId) })
  })
  await waitFor(() => {
    expect(hooks.reads()).toBe(2)
  })
  await act(async () => {
    await hooks.result.current.note.mutateAsync({ text: 'A new note.' })
  })
}

describe('useRecordEntry', () => {
  it('appends the recorded entry to the cached journal', async () => {
    const { result } = setup(() => () => Response.json(newNote, { status: 201 }))
    await waitFor(() => {
      expect(result.current.journal.data).toEqual([gateNote])
    })

    await act(async () => {
      await result.current.note.mutateAsync({ text: 'A new note.' })
    })

    await waitFor(() => {
      expect(result.current.journal.data).toEqual([gateNote, newNote])
    })
  })

  it('adds no duplicate when a refetch in flight already holds the entry', async () => {
    const hooks = setup((answerRefetch) => async () => {
      // The refetch answers with the entry before the record request does.
      answerRefetch([gateNote, newNote])
      await new Promise((resolve) => setTimeout(resolve, 20))
      return Response.json(newNote, { status: 201 })
    })

    await startRefetchAndRecord(hooks)

    expect(hooks.client.getQueryData(journalKey(campaignId))).toEqual([gateNote, newNote])
  })

  it('keeps the append when a refetch in flight does not hold the entry yet', async () => {
    const hooks = setup(() => () => Response.json(newNote, { status: 201 }))

    await startRefetchAndRecord(hooks)
    // The read started before the note was recorded now answers without it.
    await act(async () => {
      hooks.answerRefetch([gateNote])
      await new Promise((resolve) => setTimeout(resolve, 20))
    })

    expect(hooks.client.getQueryData(journalKey(campaignId))).toEqual([gateNote, newNote])
  })
})
