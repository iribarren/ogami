import { useQuery } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'

/** Polls `GET /api/health`: whether the API answers and whether its database is up. */
export function useHealth() {
  const api = useApiClient()

  return useQuery({
    queryKey: ['health'],
    queryFn: async ({ signal }) => {
      const { data, response } = await api.GET('/api/health', { signal })
      // `data` is set only for the documented 2xx response.
      if (data === undefined) {
        throw new Error(`Health check failed with HTTP ${String(response.status)}`)
      }
      return data
    },
    refetchInterval: 30_000,
  })
}
