import {
  createMemoryHistory,
  createRootRoute,
  createRoute,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { UnsavedChangesGuard } from './UnsavedChangesGuard'

/** A page that renders the guard, at `/`, and one to leave for, at `/other`. */
async function renderGuard(props: { dirty: boolean; blockTabChange?: boolean }, initial = '/') {
  const root = createRootRoute()
  const page = createRoute({
    getParentRoute: () => root,
    path: '/',
    component: () => (
      <>
        <p>The page</p>
        <UnsavedChangesGuard {...props} />
      </>
    ),
  })
  const other = createRoute({
    getParentRoute: () => root,
    path: '/other',
    component: () => <p>The other page</p>,
  })
  const router = createRouter({
    routeTree: root.addChildren([page, other]),
    history: createMemoryHistory({ initialEntries: [initial] }),
  })
  await router.load()
  render(<RouterProvider router={router} />)
  // The guard blocks only once it is mounted.
  await screen.findByText('The page')
  // The router types the search of a route with no validation as empty.
  // Not awaited: a blocked navigation stays pending until the person answers.
  const go = (to: '/' | '/other', search: Record<string, unknown> = {}) =>
    act(async () => {
      void router.navigate({ to, search } as never)
      await Promise.resolve()
    })
  return { router, go }
}

const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })

describe('the unsaved changes guard', () => {
  it('lets a clean page go anywhere', async () => {
    const { go } = await renderGuard({ dirty: false, blockTabChange: true })

    await go('/other')

    expect(await screen.findByText('The other page')).toBeInTheDocument()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks before a dirty page goes to another path, and Stay keeps it', async () => {
    const user = userEvent.setup()
    const { go, router } = await renderGuard({ dirty: true })

    await go('/other')
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Stay' }))

    expect(dialog()).not.toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/')
  })

  it('goes on when the person chooses to leave', async () => {
    const user = userEvent.setup()
    const { go } = await renderGuard({ dirty: true })

    await go('/other')
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))

    expect(await screen.findByText('The other page')).toBeInTheDocument()
  })

  it('never asks about a search change within the same tab', async () => {
    const { go, router } = await renderGuard({ dirty: true, blockTabChange: true }, '/?tab=sheet')

    await go('/', { tab: 'sheet', node: 'a' })

    await waitFor(() => {
      expect(router.state.location.search).toEqual({ tab: 'sheet', node: 'a' })
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks about a change of tab only when it is told to', async () => {
    const free = await renderGuard({ dirty: true }, '/?tab=sheet')
    await free.go('/', { tab: 'checks' })
    await waitFor(() => {
      expect(free.router.state.location.search).toEqual({ tab: 'checks' })
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks about a change of tab when it is told to', async () => {
    const { go, router } = await renderGuard({ dirty: true, blockTabChange: true }, '/?tab=sheet')

    await go('/', { tab: 'checks' })

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    expect(router.state.location.search).toEqual({ tab: 'sheet' })
  })

  it('reads no tab and the Overview as the same section', async () => {
    const { go, router } = await renderGuard({ dirty: true, blockTabChange: true }, '/')

    await go('/', { tab: 'overview' })

    await waitFor(() => {
      expect(router.state.location.search).toEqual({ tab: 'overview' })
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('reads a tab it does not know as the Overview', async () => {
    const { go, router } = await renderGuard({ dirty: true, blockTabChange: true }, '/?tab=sheet')

    await go('/', { tab: 'nonsense' })
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    expect(router.state.location.search).toEqual({ tab: 'sheet' })
  })

  it('does not ask to go from an unknown tab to the Overview', async () => {
    const { go, router } = await renderGuard(
      { dirty: true, blockTabChange: true },
      '/?tab=nonsense',
    )

    await go('/', {})

    await waitFor(() => {
      expect(router.state.location.search).toEqual({})
    })
    expect(dialog()).not.toBeInTheDocument()
  })
})
