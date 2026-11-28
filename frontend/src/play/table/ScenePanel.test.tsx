import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { atStep, guided, inOpenPlay, promptStep } from '../flow/fixtures'
import { renderTableOf } from './testTable'

describe('the scene panel', () => {
  it('opens from the current scene card with the guided step and controls, and Escape closes it', async () => {
    const user = userEvent.setup()
    renderTableOf(guided(atStep(promptStep)))

    const card = await screen.findByRole('button', { name: 'Open scene Into the dark' })
    await user.click(card)
    const dialog = within(await screen.findByRole('dialog', { name: 'Scene: Into the dark' }))
    expect(dialog.getByRole('region', { name: 'Focus mode' })).toBeInTheDocument()
    expect(dialog.getByText('Who walks in?')).toBeInTheDocument()
    expect(dialog.getByRole('region', { name: 'Guided controls' })).toBeInTheDocument()

    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(card).toHaveFocus()
  })

  it('stays open while a command refreshes the campaign', async () => {
    const user = userEvent.setup()
    renderTableOf(guided(atStep(promptStep)), {
      'flow-run/pause': () => Response.json(guided({ ...inOpenPlay, status: 'paused' })),
    })

    await user.click(await screen.findByRole('button', { name: 'Open scene Into the dark' }))
    const dialog = within(await screen.findByRole('dialog'))
    await user.click(await dialog.findByRole('button', { name: 'Pause guidance' }))

    expect(await dialog.findByText(/Guidance is paused/)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('has a Play card in the place of a missing current scene, to reach the phase steps', async () => {
    const user = userEvent.setup()
    renderTableOf({
      ...guided({ ...atStep(promptStep), waitsForSession: true, step: null }),
      currentSceneNumber: null,
    })

    expect(screen.queryByRole('button', { name: /^Open scene/ })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Play' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Play' }))
    expect(dialog.getByRole('button', { name: 'Start session' })).toBeInTheDocument()
  })
})
