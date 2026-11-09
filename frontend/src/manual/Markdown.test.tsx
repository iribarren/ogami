import {
  createMemoryHistory,
  createRootRoute,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router'
import { render, screen, within } from '@testing-library/react'

import { Markdown } from './Markdown'

async function show(source: string) {
  const router = createRouter({
    routeTree: createRootRoute({ component: () => <Markdown>{source}</Markdown> }),
    history: createMemoryHistory({ initialEntries: ['/'] }),
  })
  render(<RouterProvider router={router} />)
  await screen.findAllByText(/./)
}

describe('Markdown', () => {
  it('uses the router for an internal link and a new tab for an external one', async () => {
    await show('[Studio](/studio) and [CC](https://example.com/license)')

    expect(screen.getByRole('link', { name: 'Studio' })).toHaveAttribute('href', '/studio')
    expect(screen.getByRole('link', { name: 'Studio' })).not.toHaveAttribute('target')
    const external = screen.getByRole('link', { name: 'CC' })
    expect(external).toHaveAttribute('target', '_blank')
    expect(external).toHaveAttribute('rel', 'noreferrer')
  })

  it('renders raw HTML as inert text, not as elements', async () => {
    await show('Before <script>window.hacked = true</script> <img src=x onerror="alert(1)"> after')

    expect(document.querySelector('script')).toBeNull()
    expect(document.querySelector('img')).toBeNull()
  })

  it('does not render a javascript: link as a link', async () => {
    await show('[click](javascript:alert(1)) and [protocol-relative](//evil.example)')

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    expect(screen.getByText(/click/)).toBeInTheDocument()
  })

  it('wraps a table so it scrolls on a narrow screen', async () => {
    await show('| a | b |\n| - | - |\n| 1 | 2 |')

    const table = screen.getByRole('table')
    expect(table.parentElement).toHaveClass('overflow-x-auto')
    expect(within(table).getByRole('cell', { name: '1' })).toBeInTheDocument()
  })
})
