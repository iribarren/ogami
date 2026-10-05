import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createApiClient } from '@/shared/api/client'

import { HealthStatus } from './HealthStatus'

/** Renders the widget against a fake API that answers `GET /api/health` with `respond()`. */
function renderWithApi(respond: () => Response | Promise<Response>) {
  const requests: string[] = []
  const apiClient = createApiClient({
    baseUrl: 'http://ogami.test',
    fetch: (input) => {
      const request = input instanceof Request ? input : new Request(input)
      requests.push(`${request.method} ${new URL(request.url).pathname}`)
      return Promise.resolve(respond())
    },
  })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  render(
    <QueryClientProvider client={queryClient}>
      <ApiClientProvider client={apiClient}>
        <HealthStatus />
      </ApiClientProvider>
    </QueryClientProvider>,
  )

  return { requests }
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

function statusOf(label: string) {
  const widget = screen.getByRole('region', { name: 'System status' })
  return within(widget).getByText(label).nextElementSibling?.textContent
}

describe('HealthStatus', () => {
  it('shows the API and the database as ok when both are up', async () => {
    const { requests } = renderWithApi(() => json({ status: 'ok', database: 'ok' }))

    expect(await screen.findByText('Database')).toBeInTheDocument()
    expect(statusOf('API')).toBe('ok')
    expect(statusOf('Database')).toBe('ok')
    expect(requests).toEqual(['GET /api/health'])
  })

  it('shows the database as down when the API reports it', async () => {
    renderWithApi(() => json({ status: 'ok', database: 'down' }))

    expect(await screen.findByText('Database')).toBeInTheDocument()
    expect(statusOf('API')).toBe('ok')
    expect(statusOf('Database')).toBe('down')
  })

  it('tells the user when the API cannot be reached', async () => {
    renderWithApi(() => json({ error: 'Bad Gateway' }, 502))

    expect(await screen.findByText('The API is unreachable.')).toBeInTheDocument()
  })
})
