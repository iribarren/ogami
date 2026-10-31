import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  fateDouble,
  fateDoubleEvent,
  gateNote,
  lockedDoorAnswer,
  lostMineJournal,
  noteAttaching,
  stormyWeather,
} from '../journal/fixtures'
import { atStep, atTheScenePick, guided, inOpenPlay, promptStep, rollStep } from './fixtures'

const path = `/api/campaigns/${lostMineCampaign.id}`
const storageKey = `ogami.play.flowView.${lostMineCampaign.id}`

/** The Play screen of `campaign`; `commands` answer the FlowRun requests by their last path segment. */
function renderPlay(
  campaign: Campaign,
  commands: Record<string, FakeHandler> = {},
  journal: unknown[] = lostMineJournal,
) {
  const sent: Record<string, unknown> = {}
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json(journal),
  }
  for (const [name, handler] of Object.entries(commands)) {
    handlers[`POST ${path}/${name}`] = async (request) => {
      const text = await request.clone().text()
      sent[name] = text === '' ? undefined : JSON.parse(text)
      return handler(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${campaign.id}`, handlers), sent }
}

const answering =
  (next: Campaign): FakeHandler =>
  () =>
    Response.json(next)

const focusRegion = () => screen.findByRole('region', { name: 'Focus mode' })

beforeEach(() => {
  window.localStorage.clear()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('the list links in focus mode', () => {
  it.each([
    ['Threads', 'threads'],
    ['Places', 'places'],
    ['NPCs', 'npcs'],
  ])('open the collapsed %s panel', async (name, id) => {
    const user = userEvent.setup()
    window.localStorage.setItem(`ogami.playPanel.${id}`, 'closed')
    renderPlay(guided(atStep(promptStep), 'focus'))
    const focus = within(await focusRegion())
    const trigger = within(screen.getByRole('region', { name })).getByRole('button', { name })
    expect(trigger).toHaveAttribute('aria-expanded', 'false')

    await user.click(focus.getByRole('button', { name }))

    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(trigger).toHaveFocus()
  })
})

describe('the view toggle', () => {
  it('is absent for a campaign played freely', async () => {
    renderPlay(lostMineCampaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(screen.queryByRole('radiogroup', { name: 'Guided view' })).not.toBeInTheDocument()
  })

  it.each([
    ['journal', 'Journal view', 'Guided play'],
    ['focus', 'Focus view', 'Focus mode'],
  ] as const)('opens in the Flow default view, %s', async (defaultView, selected, region) => {
    renderPlay(guided(atStep(promptStep), defaultView))

    expect(await screen.findByRole('region', { name: region })).toBeInTheDocument()
    const toggle = within(screen.getByRole('radiogroup', { name: 'Guided view' }))
    expect(toggle.getByRole('radio', { name: selected })).toBeChecked()
  })

  it('switches views, hiding the full journal in focus mode, and remembers the choice per campaign', async () => {
    const user = userEvent.setup()
    renderPlay(guided(atStep(promptStep), 'journal'))

    await user.click(await screen.findByRole('radio', { name: 'Focus view' }))

    expect(await focusRegion()).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Journal' })).not.toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Guided play' })).not.toBeInTheDocument()
    expect(window.localStorage.getItem(storageKey)).toBe('focus')

    cleanup()
    renderPlay(guided(atStep(promptStep), 'journal'))
    expect(await focusRegion()).toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Journal view' }))
    expect(await screen.findByRole('region', { name: 'Journal' })).toBeInTheDocument()
    expect(window.localStorage.getItem(storageKey)).toBe('journal')
  })

  it('reads the journal at a prose measure and centres the Step card in focus mode', async () => {
    const user = userEvent.setup()
    renderPlay(guided(atStep(promptStep), 'journal'))

    const column = (await screen.findByRole('radiogroup', { name: 'Guided view' })).parentElement
    expect(column).toHaveClass('max-w-prose')

    await user.click(screen.getByRole('radio', { name: 'Focus view' }))

    await focusRegion()
    expect(screen.getByRole('radiogroup', { name: 'Guided view' }).parentElement).toHaveClass(
      'mx-auto',
      'max-w-2xl',
    )
  })

  it('ignores a remembered value it does not know', async () => {
    window.localStorage.setItem(storageKey, 'wizard')
    renderPlay(guided(atStep(promptStep), 'focus'))

    expect(await focusRegion()).toBeInTheDocument()
  })

  it('works when the browser storage throws', async () => {
    const user = userEvent.setup()
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    renderPlay(guided(atStep(promptStep), 'focus'))

    expect(await focusRegion()).toBeInTheDocument()
    await user.click(screen.getByRole('radio', { name: 'Journal view' }))
    expect(await screen.findByRole('region', { name: 'Guided play' })).toBeInTheDocument()
  })
})

describe('focus mode', () => {
  it('shows the progress, the current step, the next step and the scene so far, and nothing else', async () => {
    renderPlay(guided(atStep(promptStep), 'focus'))

    const focus = within(await focusRegion())
    expect(focus.getByLabelText('Progress')).toHaveTextContent(
      'Act 1 › The start › Tour › Setup · step 1 of 3',
    )
    expect(focus.getByRole('progressbar', { name: 'Step 1 of 3' })).toHaveAttribute(
      'aria-valuenow',
      '0',
    )
    expect(focus.getByRole('region', { name: 'Current step: Who walks in?' })).toBeInTheDocument()
    expect(focus.getByText('Setup: Who walks in?')).toBeInTheDocument()
    // Only the current scene's entries: session 2, scene 1.
    const soFar = within(await focus.findByRole('region', { name: 'So far' }))
    expect(soFar.getByText(/Is the door locked/)).toBeInTheDocument()
    expect(soFar.getByText('A storm')).toBeInTheDocument()
    expect(soFar.queryByText(/The gate is open/)).not.toBeInTheDocument()
  })

  it('offers the random event table on a flagged fate answer in the scene so far', async () => {
    renderPlay(guided(atStep(promptStep), 'focus'), {}, [fateDouble])

    const focus = within(await focusRegion())
    const soFar = within(await focus.findByRole('region', { name: 'So far' }))
    expect(soFar.getByText('Random event')).toBeInTheDocument()
    expect(soFar.getByRole('button', { name: 'Roll Weather' })).toBeEnabled()
  })

  it('shows the random event under its question in the scene so far, with no roll offer', async () => {
    renderPlay(guided(atStep(promptStep), 'focus'), {}, [fateDouble, fateDoubleEvent])

    const focus = within(await focusRegion())
    const soFar = within(await focus.findByRole('region', { name: 'So far' }))
    expect(soFar.getAllByText('Dense fog')).toHaveLength(1)
    expect(soFar.queryByRole('button', { name: 'Roll Weather' })).not.toBeInTheDocument()
    expect(soFar.getByRole('textbox', { name: 'What does it mean?' })).toBeInTheDocument()
  })

  it('shows the attached results inside their note in the scene so far, not at their own place', async () => {
    renderPlay(guided(atStep(promptStep), 'focus'), {}, [
      lockedDoorAnswer,
      stormyWeather,
      noteAttaching([stormyWeather.id]),
    ])

    const focus = within(await focusRegion())
    const soFar = within(await focus.findByRole('region', { name: 'So far' }))
    const note = within(soFar.getByRole('article', { name: 'Note' }))
    expect(note.getByRole('list', { name: 'Attached results' })).toHaveTextContent('Hail')
    expect(soFar.getAllByText('Hail')).toHaveLength(1)
    expect(soFar.getByText(/Is the door locked/)).toBeInTheDocument()
  })

  it('shows no summary at the scene pick, where the last scene has ended', async () => {
    renderPlay(guided(atTheScenePick(), 'focus'))

    await focusRegion()
    expect(screen.queryByRole('region', { name: 'So far' })).not.toBeInTheDocument()
  })

  it('shows no summary while the scene has no entries', async () => {
    renderPlay(guided(atStep(promptStep), 'focus'), {}, [gateNote])

    await focusRegion()
    expect(screen.queryByRole('region', { name: 'So far' })).not.toBeInTheDocument()
  })

  it('answers the step through the same commands as the journal', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlay(guided(atStep(rollStep), 'focus'), {
      'flow-run/answer': answering(guided(atStep(promptStep), 'focus')),
    })

    await user.click(await within(await focusRegion()).findByRole('button', { name: 'Roll 1d6' }))

    expect(
      await screen.findByRole('region', { name: 'Current step: Who walks in?' }),
    ).toBeInTheDocument()
    expect(sent['flow-run/answer']).toEqual({ stepKey: 'dice' })
  })

  it('offers the scene pick', async () => {
    renderPlay(guided(atTheScenePick(), 'focus'))

    expect(await screen.findByRole('region', { name: 'Scene pick' })).toBeInTheDocument()
    expect(screen.getByText('Scene: choose a Scene Type')).toBeInTheDocument()
  })

  it('ends the scene like the journal view', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlay(guided(inOpenPlay, 'focus'), {
      'flow-run/end-scene': answering(guided(atTheScenePick(), 'focus')),
    })

    await user.click(await screen.findByRole('button', { name: 'End scene' }))
    expect(await screen.findByRole('region', { name: 'Scene pick' })).toBeInTheDocument()
    expect(sent['flow-run/end-scene']).toEqual({ sceneNumber: 1 })
    expect(screen.getByRole('button', { name: 'End session' })).toBeInTheDocument()
  })

  it('pauses and resumes guidance', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlay(guided(atStep(promptStep), 'focus'), {
      'flow-run/pause': answering(guided({ ...atStep(promptStep), status: 'paused' }, 'focus')),
      'flow-run/resume': answering(guided(atStep(promptStep), 'focus')),
    })

    await user.click(await screen.findByRole('button', { name: 'Pause guidance' }))
    expect(await screen.findByText(/Guidance is paused/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Resume guidance' }))

    expect(
      await screen.findByRole('region', { name: 'Current step: Who walks in?' }),
    ).toBeInTheDocument()
    expect(Object.keys(sent)).toEqual(['flow-run/pause', 'flow-run/resume'])
  })

  it('offers to end the phase only when the phase can move on', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlay(guided(atStep(promptStep, { canMoveOn: true }), 'focus'), {
      'flow-run/move-on': () =>
        Response.json({ error: 'Phase "start" plays once.' }, { status: 409 }),
    })

    await user.click(await screen.findByRole('button', { name: 'End the start phase' }))
    await user.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'End the phase' }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('Phase "start" plays once.')
    expect(sent['flow-run/move-on']).toEqual({ phase: 'start' })
  })

  it('shows the controls once, below the note composer', async () => {
    renderPlay(guided(inOpenPlay, 'focus'))

    const bar = await screen.findByRole('region', { name: 'Guided controls' })
    const focus = await focusRegion()
    expect(within(focus).queryByRole('button', { name: 'End scene' })).not.toBeInTheDocument()
    expect(
      screen.getByLabelText('New note').compareDocumentPosition(bar) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'End scene' })).toHaveLength(1)
  })

  it('shows why a command was refused', async () => {
    const user = userEvent.setup()
    renderPlay(guided(atStep(rollStep), 'focus'), {
      'flow-run/answer': () =>
        Response.json({ error: 'The FlowRun is at another step.' }, { status: 409 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Roll 1d6' }))

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('The FlowRun is at another step.')
    })
  })
})
