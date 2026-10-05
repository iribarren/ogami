import { useMutation } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type LikelihoodOracleDefinition = components['schemas']['LikelihoodOracleDefinition']
export type LikelihoodAnswer = components['schemas']['LikelihoodAnswerResponse']
export type OracleTableDefinition = components['schemas']['OracleTableDefinition']
export type OracleTableResult = components['schemas']['OracleTableResultResponse']
export type OracleTableStep = components['schemas']['OracleTableStepResponse']
type LikelihoodAnswerRequest = components['schemas']['LikelihoodAnswerRequest']
type OracleTableResultRequest = components['schemas']['OracleTableResultRequest']

/** An oracle question the API refused; `message` is the API's reason when it gave one. */
export class OracleError extends Error {
  override name = 'OracleError'
}

/** 400 and 422 explain what is wrong with the definition or question; anything else is unexpected. */
function oracleError(status: number, error: { error: string } | undefined, failure: string) {
  const reason = status === 400 || status === 422 ? error?.error : undefined
  return new OracleError(reason ?? `${failure} failed with HTTP ${String(status)}.`)
}

/** `POST /api/likelihood-answers`: asks a likelihood oracle a yes/no question. */
export function useAskLikelihoodOracle() {
  const api = useApiClient()

  return useMutation({
    mutationFn: async (body: LikelihoodAnswerRequest): Promise<LikelihoodAnswer> => {
      const { data, error, response } = await api.POST('/api/likelihood-answers', { body })
      if (data !== undefined) {
        return data
      }
      throw oracleError(response.status, error, 'Asking the oracle')
    },
  })
}

/** `POST /api/oracle-table-results`: rolls on an oracle table, following nested tables. */
export function useResolveOracleTable() {
  const api = useApiClient()

  return useMutation({
    mutationFn: async (body: OracleTableResultRequest): Promise<OracleTableResult> => {
      const { data, error, response } = await api.POST('/api/oracle-table-results', { body })
      if (data !== undefined) {
        return data
      }
      throw oracleError(response.status, error, 'Rolling on the oracle table')
    },
  })
}
