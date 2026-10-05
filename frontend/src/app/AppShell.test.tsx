import { QueryClientProvider } from '@tanstack/react-query'
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createApiClient } from '@/shared/api/client'

import { createQueryClient } from './queryClient'
import { createAppRouter } from './router'

function renderAppAt(path: string) {
  const queryClient = createQueryClient()
  const router = createAppRouter({
    queryClient,
    history: createMemoryHistory({ initialEntries: [path] }),
  })

  // The landing page polls the health endpoint; answer it without a network.
  const apiClient = createApiClient({
    baseUrl: 'http://ogami.test',
    fetch: () => Promise.resolve(Response.json({ status: 'ok', database: 'ok' })),
  })

  render(
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={apiClient}>
        <RouterProvider router={router} />
      </ApiClientProvider>
    </QueryClientProvider>,
  )
}

describe('AppShell', () => {
  it('links every product area from the main navigation', async () => {
    renderAppAt('/')

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(within(nav).getByRole('link', { name: 'Play' })).toHaveAttribute('href', '/play')
    expect(within(nav).getByRole('link', { name: 'Studio' })).toHaveAttribute('href', '/studio')
    expect(within(nav).getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin')
  })

  it('opens an area and marks its link as the current page', async () => {
    const user = userEvent.setup()
    renderAppAt('/')

    await user.click(await screen.findByRole('link', { name: 'Studio' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Studio' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Studio' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Play' })).not.toHaveAttribute('aria-current')
  })
})
