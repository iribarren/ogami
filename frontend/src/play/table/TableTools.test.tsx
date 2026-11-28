import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { atStep, guided, promptStep } from '../flow/fixtures'
import { gateNote, recordedEntry, stormyWeather } from '../journal/fixtures'
import { renderTableOf } from './testTable'

// The test release plays the flow "tour", whose phases are "road" and "end".
const onRoad = () => {
  const flowRun = atStep(promptStep)
  const campaign = guided({
    ...flowRun,
    progress: flowRun.progress === null ? null : { ...flowRun.progress, phaseKey: 'road' },
  })
  return campaign
}

// The current scene is session 2, scene 1: its note is recorded after an earlier scene's.
const currentNote = recordedEntry({ kind: 'note', text: 'The cellar hums.', attachments: [] })

describe('the notebook', () => {
  it('opens full screen with the journal, this scene first, and the focus goes back on close', async () => {
    const user = userEvent.setup()
    renderTableOf(onRoad(), {}, [gateNote, currentNote])

    const opener = await screen.findByRole('button', { name: 'Open the notebook' })
    await user.click(opener)

    const dialog = within(await screen.findByRole('dialog', { name: 'Notebook' }))
    expect(await dialog.findByText('The cellar hums.')).toBeInTheDocument()
    expect(dialog.getByText(/Nobody guards it/)).toBeInTheDocument()
    expect(dialog.getByRole('region', { name: 'Earlier in the journal' })).toBeInTheDocument()
    const thisScene = within(dialog.getByRole('region', { name: 'This scene' }))
    expect(thisScene.getByText('The cellar hums.')).toBeInTheDocument()
    expect(thisScene.queryByText(/Nobody guards it/)).not.toBeInTheDocument()

    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(opener).toHaveFocus()
  })

  it('has a note box of at least twelve rows that writes in the current scene', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(onRoad(), { 'journal/notes': () => Response.json(currentNote) })

    await user.click(await screen.findByRole('button', { name: 'Open the notebook' }))
    const box = await screen.findByRole('textbox', { name: 'New note' })
    // Twelve lines of the 1.5rem reading size, 18rem, and the box grows with its content.
    expect(box.closest('form')?.parentElement).toHaveClass('[&_textarea]:min-h-80')
    await user.type(box, 'A new clue')
    await user.click(screen.getByRole('button', { name: 'Write note' }))

    await waitFor(() => {
      expect(sent['journal/notes']).toEqual({ text: 'A new clue' })
    })
  })

  it('says so when the journal is empty and no scene is under way', async () => {
    const user = userEvent.setup()
    renderTableOf({ ...onRoad(), currentSceneNumber: null, sessions: [] })

    await user.click(await screen.findByRole('button', { name: 'Open the notebook' }))

    expect(await screen.findByText(/The journal is empty/)).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: 'New note' })).toBeDisabled()
  })

  it('shows a failed read of the journal as an alert', async () => {
    const user = userEvent.setup()
    renderTableOf(onRoad(), {}, () =>
      Response.json({ error: 'No journal today.' }, { status: 500 }),
    )
    await user.click(await screen.findByRole('button', { name: 'Open the notebook' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect((await dialog.findByRole('alert')).textContent).toContain('journal')
  })
})

describe('the oracle book', () => {
  it('opens the oracle panel in a sheet and records a roll as the Play page does', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(onRoad(), {
      'journal/oracle-tables/weather': () => Response.json(stormyWeather),
    })

    const opener = await screen.findByRole('button', { name: 'Open the oracle book' })
    await user.click(opener)
    const dialog = within(await screen.findByRole('dialog', { name: 'Oracle book' }))
    await user.click(dialog.getByRole('button', { name: 'Roll on Weather' }))

    await waitFor(() => {
      expect(sent).toHaveProperty(['journal/oracle-tables/weather'])
    })
    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(opener).toHaveFocus()
  })

  it('says so when the release has no oracles', async () => {
    const user = userEvent.setup()
    renderTableOf({ ...onRoad(), oracleTables: [], likelihoodOracles: [] })

    await user.click(await screen.findByRole('button', { name: 'Open the oracle book' }))

    expect(await screen.findByText(/has no oracles/)).toBeInTheDocument()
  })
})

describe('the flow map', () => {
  const map = async () => within(await screen.findByRole('region', { name: 'Flow map' }))

  it('draws the flow with the current phase marked', async () => {
    renderTableOf(onRoad())

    const road = (await map()).getByRole('img', { name: /Flow diagram: Tour/ })
    expect(road).toBeInTheDocument()
    // Selected is drawn thick in the primary colour: only the current phase's box is.
    const marked = road.querySelectorAll('rect[stroke-width="3"]')
    expect(marked).toHaveLength(1)
    expect(marked[0]?.getAttribute('style')).toContain('var(--primary)')
    expect(marked[0]?.parentElement?.textContent).toContain('Road')
  })

  it('opens the diagram in a sheet from the dock button', async () => {
    const user = userEvent.setup()
    renderTableOf(onRoad())

    const opener = (await map()).getByRole('button', { name: 'Flow map' })
    await user.click(opener)
    const dialog = within(await screen.findByRole('dialog', { name: 'Flow map' }))
    expect(dialog.getByRole('img', { name: /Flow diagram: Tour/ })).toBeInTheDocument()

    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(opener).toHaveFocus()
    })
  })

  it('says why a flow the release does not have cannot be drawn', async () => {
    renderTableOf({ ...onRoad(), flowKey: 'unknown' })

    expect((await (await map()).findByRole('alert')).textContent).toContain('cannot be drawn')
  })

  it('says so for a campaign without a flow', async () => {
    renderTableOf({ ...onRoad(), flowKey: null, flowRun: null })

    expect(await (await map()).findByText(/without a flow/)).toBeInTheDocument()
  })
})
