import { useMutation } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

export type Roll = components['schemas']['RollResponse']
export type DiceGroup = components['schemas']['DiceGroupResponse']
export type RolledDie = components['schemas']['RolledDieResponse']
type RollRequest = components['schemas']['RollRequest']

/** A roll the API refused; `message` is the API's reason when it gave one. */
export class RollError extends Error {
  override name = 'RollError'
}

/** `POST /api/rolls`: rolls a dice expression and returns the total with every die rolled. */
export function useRollDice() {
  const api = useApiClient()

  return useMutation({
    mutationFn: async ({ expression }: RollRequest): Promise<Roll> => {
      const { data, error, response } = await api.POST('/api/rolls', { body: { expression } })
      if (data !== undefined) {
        return data
      }
      // 400 and 422 explain what is wrong with the expression; anything else is unexpected.
      const reason = response.status === 400 || response.status === 422 ? error.error : undefined
      throw new RollError(reason ?? `Rolling the dice failed with HTTP ${String(response.status)}.`)
    },
  })
}
