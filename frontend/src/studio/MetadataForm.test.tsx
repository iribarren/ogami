import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { chooseOption } from '@/test/chooseOption'
import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { draft } from './fixtures'
import { MetadataForm } from './MetadataForm'
import type { GameSystem } from './useGameSystems'

const credits = { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null }

function renderForm(gameSystem: GameSystem = draft) {
  const sent: unknown[] = []
  const save: FakeHandler = async (request) => {
    sent.push(await request.json())
    return Response.json(gameSystem)
  }
  renderWithApi(<MetadataForm gameSystem={gameSystem} onReload={() => undefined} />, {
    'PUT /api/studio/game-systems/my-draft/metadata': save,
  })
  return { sent, form: within(screen.getByRole('form', { name: 'Metadata' })) }
}

const withBadge = (badge: string | null) =>
  ({ ...draft, credits: { ...credits, badge } }) as GameSystem

describe('the credits badge', () => {
  it('is none for credits without one, shows no logo and is left out of the request', async () => {
    const user = userEvent.setup()
    const { sent, form } = renderForm()

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

  it('reads credits an older server sends without a badge field', () => {
    const { form } = renderForm({ ...draft, credits } as unknown as GameSystem)

    expect(form.getByLabelText('Badge (optional)')).toHaveTextContent('None')
  })

  it('picks Dark Pack, shows its logo beside the picker and saves it', async () => {
    const user = userEvent.setup()
    const { sent, form } = renderForm()

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
    const { sent, form } = renderForm(withBadge('dark-pack'))

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
    renderForm(withBadge('dark-pack'))
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
    const { sent, form } = renderForm(withBadge('camarilla'))

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
