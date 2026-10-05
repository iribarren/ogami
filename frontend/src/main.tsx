import { QueryClientProvider } from '@tanstack/react-query'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
import { RouterProvider } from '@tanstack/react-router'
import { TanStackRouterDevtools } from '@tanstack/react-router-devtools'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { createQueryClient } from '@/app/queryClient'
import { createAppRouter } from '@/app/router'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createApiClient } from '@/shared/api/client'

import './index.css'

const queryClient = createQueryClient()
// One API client for components (via the provider) and route guards (via router context).
const api = createApiClient()
const router = createAppRouter({ queryClient, api })

const rootElement = document.getElementById('root')
if (!rootElement) {
  throw new Error('Missing #root element in index.html')
}

// Both devtools render nothing in production builds.
createRoot(rootElement).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={api}>
        <RouterProvider router={router} />
      </ApiClientProvider>
      <TanStackRouterDevtools router={router} position="bottom-right" />
      <ReactQueryDevtools buttonPosition="bottom-left" />
    </QueryClientProvider>
  </StrictMode>,
)
