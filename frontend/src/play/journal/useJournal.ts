import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { CampaignError, campaignError, explainedRefusals } from '../campaigns/useCampaigns'

export type JournalEntry = components['schemas']['JournalEntryResponse']
export type NoteContent = components['schemas']['NoteContentResponse']
export type RollContent = components['schemas']['RollContentResponse']
export type OracleTableContent = components['schemas']['OracleTableContentResponse']
export type LikelihoodContent = components['schemas']['LikelihoodContentResponse']
export type CheckContent = components['schemas']['CheckContentResponse']
export type PickContent = components['schemas']['PickContentResponse']
export type SheetChangeContent = components['schemas']['SheetChangeContentResponse']
export type FlowOutcomeContent = components['schemas']['FlowOutcomeContentResponse']
type RecordNoteRequest = components['schemas']['RecordNoteRequest']
type RecordRollRequest = components['schemas']['RecordRollRequest']
type RecordLikelihoodAnswerRequest = components['schemas']['RecordLikelihoodAnswerRequest']

export const journalKey = (campaignId: string) => ['play', 'journal', campaignId] as const
/** The mutation key every record hook of a campaign shares, so the journal can find what was just recorded. */
export const recordEntryKey = (campaignId: string) => [...journalKey(campaignId), 'record'] as const

/** `GET /api/campaigns/{campaignId}/journal`: every entry, in recorded order; a 404 is not retried. */
export function useJournal(campaignId: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: journalKey(campaignId),
    queryFn: async ({ signal }): Promise<JournalEntry[]> => {
      const { data, error, response } = await api.GET('/api/campaigns/{campaignId}/journal', {
        params: { path: { campaignId } },
        signal,
      })
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, [404], 'Loading the journal')
    },
    retry: (failureCount, error) =>
      !(error instanceof CampaignError && error.status === 404) && failureCount < 1,
  })
}

/**
 * Shared by the record hooks (and the check hook): posts with `record` and appends the
 * recorded entry to the cached journal, so it appears without reloading the journal. With
 * `refetchOnConflict`, a refusal of 409 reads the journal again: the entry the refusal is about
 * was recorded by someone else.
 */
export function useRecordEntry<Variables>(
  campaignId: string,
  failure: string,
  record: (variables: Variables) => Promise<{
    data?: JournalEntry
    error?: { error: string }
    response: Response
  }>,
  { refetchOnConflict = false }: { refetchOnConflict?: boolean } = {},
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationKey: recordEntryKey(campaignId),
    mutationFn: async (variables: Variables): Promise<JournalEntry> => {
      const { data, error, response } = await record(variables)
      if (data !== undefined) {
        return data
      }
      throw campaignError(response, error, explainedRefusals, failure)
    },
    onSuccess: async (entry) => {
      // A read already in flight started before this entry existed: its answer would overwrite
      // the append, so drop it first. One that finished with the entry leaves nothing to append.
      const key = journalKey(campaignId)
      const reading = queryClient.isFetching({ queryKey: key }) > 0
      await queryClient.cancelQueries({ queryKey: key })
      const cached = queryClient.getQueryData<JournalEntry[]>(key)
      if (cached === undefined) {
        void queryClient.invalidateQueries({ queryKey: key })
        return
      }
      if (!cached.some(({ id }) => id === entry.id)) {
        queryClient.setQueryData(key, [...cached, entry])
      }
      // The dropped read may have carried entries from elsewhere (another tab, a refocus): ask again.
      if (reading) {
        void queryClient.invalidateQueries({ queryKey: key })
      }
    },
    onError: (error) => {
      if (refetchOnConflict && error instanceof CampaignError && error.status === 409) {
        void queryClient.invalidateQueries({ queryKey: journalKey(campaignId) })
      }
    },
  })
}

/** `POST /api/campaigns/{campaignId}/journal/notes`: writes a note in the current scene. */
export function useRecordNote(campaignId: string) {
  const api = useApiClient()
  return useRecordEntry(campaignId, 'Writing the note', (body: RecordNoteRequest) =>
    api.POST('/api/campaigns/{campaignId}/journal/notes', {
      params: { path: { campaignId } },
      body,
    }),
  )
}

/** `POST /api/campaigns/{campaignId}/journal/rolls`: rolls on the server and records the roll. */
export function useRecordRoll(campaignId: string) {
  const api = useApiClient()
  return useRecordEntry(campaignId, 'Rolling the dice', (body: RecordRollRequest) =>
    api.POST('/api/campaigns/{campaignId}/journal/rolls', {
      params: { path: { campaignId } },
      body,
    }),
  )
}

/**
 * `POST /api/campaigns/{campaignId}/journal/oracle-tables/{oracleKey}`: rolls on an oracle
 * table of the pinned release, following nested tables, and records the result. A roll for a
 * random event names the id of the fate question (`forEntry`) whose event it answers.
 */
export function useRecordOracleTableResult(campaignId: string) {
  const api = useApiClient()
  return useRecordEntry(
    campaignId,
    'Rolling on the oracle table',
    ({ oracleKey, forEntry }: { oracleKey: string; forEntry?: string }) =>
      api.POST('/api/campaigns/{campaignId}/journal/oracle-tables/{oracleKey}', {
        params: { path: { campaignId, oracleKey } },
        body: forEntry === undefined ? undefined : { forEntry },
      }),
    // The random event may have been rolled elsewhere: the journal shows it then.
    { refetchOnConflict: true },
  )
}

/**
 * `POST /api/campaigns/{campaignId}/journal/likelihood-oracles/{oracleKey}`: asks a
 * likelihood oracle of the pinned release and records the answer.
 */
export function useRecordLikelihoodAnswer(campaignId: string) {
  const api = useApiClient()
  return useRecordEntry(
    campaignId,
    'Asking the oracle',
    ({ oracleKey, ...body }: RecordLikelihoodAnswerRequest & { oracleKey: string }) =>
      api.POST('/api/campaigns/{campaignId}/journal/likelihood-oracles/{oracleKey}', {
        params: { path: { campaignId, oracleKey } },
        body,
      }),
  )
}
