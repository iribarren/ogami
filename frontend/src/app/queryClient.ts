import { QueryClient } from '@tanstack/react-query'

export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // API data is shared and changes rarely during a session; refetch on focus is enough.
        staleTime: 30_000,
        retry: 1,
      },
    },
  })
}
