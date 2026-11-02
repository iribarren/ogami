import { QueryClientProvider } from '@tanstack/react-query'
import {
  createMemoryHistory,
  createRootRoute,
  createRoute,
  createRouter,
  Link,
  Outlet,
  RouterProvider,
} from '@tanstack/react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { chooseOption } from '@/test/chooseOption'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'

import { draft } from './fixtures'
import { MetadataForm } from './MetadataForm'
import type { GameSystem } from './useGameSystems'

const credits = { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null }

/** The form at `/`, with a link to leave it for `/elsewhere`. */
async function renderForm(gameSystem: GameSystem = draft, answer?: FakeHandler) {
  const sent: unknown[] = []
  const save: FakeHandler =
    answer ??
    (async (request) => {
      sent.push(await request.json())
      return Response.json(gameSystem)
    })
  const { api } = createFakeApiClient({
    'PUT /api/studio/game-systems/my-draft/metadata': save,
  })
  const root = createRootRoute({ component: Outlet })
  const index = createRoute({
    getParentRoute: () => root,
    path: '/',
    component: () => (
      <>
        <MetadataForm gameSystem={gameSystem} onReload={() => undefined} />
        {/* The app's route tree does not know this test route. */}
        <Link to={'/elsewhere' as never}>Elsewhere</Link>
      </>
    ),
  })
  const elsewhere = createRoute({
    getParentRoute: () => root,
    path: '/elsewhere',
    component: () => <p>The other page</p>,
  })
  const router = createRouter({
    routeTree: root.addChildren([index, elsewhere]),
    history: createMemoryHistory({ initialEntries: ['/'] }),
  })
  render(
    <QueryClientProvider client={createQueryClient()}>
      <ApiClientProvider client={api}>
        <RouterProvider router={router} />
      </ApiClientProvider>
    </QueryClientProvider>,
  )
  await screen.findByRole('form', { name: 'Metadata' })
  return { sent, form: within(screen.getByRole('form', { name: 'Metadata' })) }
}

const withBadge = (badge: string | null) =>
  ({ ...draft, credits: { ...credits, badge } }) as GameSystem

describe('the credits badge', () => {
  it('is none for credits without one, shows no logo and is left out of the request', async () => {
    const user = userEvent.setup()
    const { sent, form } = await renderForm()

    expect(form.getByLabelText('Badge (optional)')).toHaveTextContent('None')
    expect(form.queryByRole('img')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'My draft',
          description: 'A game of my own.',
          credits: { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null },
          theme: 'noir',
        },
      ])
    })
  })

  it('reads credits an older server sends without a badge field', async () => {
    const { form } = await renderForm({ ...draft, credits } as unknown as GameSystem)

    expect(form.getByLabelText('Badge (optional)')).toHaveTextContent('None')
  })

  it('picks Dark Pack, shows its logo beside the picker and saves it', async () => {
    const user = userEvent.setup()
    const { sent, form } = await renderForm()

    await chooseOption(user, 'Badge (optional)', 'Dark Pack', form)

    expect(form.getByRole('img', { name: 'Dark Pack logo' })).toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(sent).toEqual([
        expect.objectContaining({ credits: { ...credits, badge: 'dark-pack' } }),
      ])
    })
  })

  it('shows the stored badge, and picking none removes the logo and the field', async () => {
    const user = userEvent.setup()
    const { sent, form } = await renderForm(withBadge('dark-pack'))

    expect(form.getByLabelText('Badge (optional)')).toHaveTextContent('Dark Pack')
    expect(form.getByRole('img', { name: 'Dark Pack logo' })).toBeInTheDocument()

    await chooseOption(user, 'Badge (optional)', 'None', form)

    expect(form.queryByRole('img')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(sent).toEqual([expect.objectContaining({ credits })])
    })
  })

  it('needs a notice, like the license and the link', async () => {
    const user = userEvent.setup()
    await renderForm(withBadge('dark-pack'))
    const form = within(screen.getByRole('form', { name: 'Metadata' }))

    await user.clear(form.getByLabelText('License (optional)'))
    await user.clear(form.getByLabelText('Notice'))

    expect(form.getByText('A badge needs a notice. Clear it, or write the notice.')).toBeVisible()
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()

    await chooseOption(user, 'Badge (optional)', 'None', form)

    expect(form.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('keeps a badge this build does not know selectable, without a logo, and sends it back', async () => {
    const user = userEvent.setup()
    const { sent, form } = await renderForm(withBadge('camarilla'))

    expect(form.getByLabelText('Badge (optional)')).toHaveTextContent('camarilla')
    expect(form.queryByRole('img')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        expect.objectContaining({ credits: { ...credits, badge: 'camarilla' } }),
      ])
    })
  })
})

describe('leaving with unsaved changes', () => {
  const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })

  it('lets an untouched form go', async () => {
    const user = userEvent.setup()
    await renderForm()

    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByText('The other page')).toBeInTheDocument()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks when a field was edited, and Stay keeps the text', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm()

    await user.type(form.getByLabelText('Name'), ' Two')
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Stay' }))

    expect(dialog()).not.toBeInTheDocument()
    expect(form.getByLabelText('Name')).toHaveValue('My draft Two')
  })

  it('goes when the person chooses to leave', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm()

    await user.type(form.getByLabelText('Name'), ' Two')
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))

    expect(await screen.findByText('The other page')).toBeInTheDocument()
  })

  it('asks for a field that is not text, like the badge', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm()

    await chooseOption(user, 'Badge (optional)', 'Dark Pack', form)
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
  })

  it('reads an edit that was undone as untouched', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm()

    await user.type(form.getByLabelText('Name'), 'x')
    await user.type(form.getByLabelText('Name'), '{Backspace}')
    expect(form.queryByText('Unsaved changes')).not.toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByText('The other page')).toBeInTheDocument()
  })

  it('lets a saved form go', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm()

    await user.type(form.getByLabelText('Name'), ' Two')
    await user.click(form.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(form.queryByText('Unsaved changes')).not.toBeInTheDocument()
    })
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByText('The other page')).toBeInTheDocument()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('keeps asking after a save that failed', async () => {
    const user = userEvent.setup()
    const { form } = await renderForm(draft, () =>
      Response.json({ error: 'Nope.' }, { status: 500 }),
    )

    await user.type(form.getByLabelText('Name'), ' Two')
    await user.click(form.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    expect(form.getByLabelText('Name')).toHaveValue('My draft Two')
  })

  it('keeps asking for an edit made while the save was in flight', async () => {
    const user = userEvent.setup()
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const { form } = await renderForm(draft, async () => {
      await gate
      return Response.json(draft)
    })

    await user.type(form.getByLabelText('Name'), ' Two')
    await user.click(form.getByRole('button', { name: 'Save' }))
    await user.type(form.getByLabelText('Description'), '!')
    release()
    await waitFor(() => {
      expect(form.queryByText('Saving…')).not.toBeInTheDocument()
    })
    await user.click(screen.getByRole('link', { name: 'Elsewhere' }))

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
  })
})
