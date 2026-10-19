import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
import { fateDouble, fateDoubleEvent, gateNote, lockedDoorAnswer, stormyWeather } from './fixtures'
import { RandomEventOffer } from './RandomEventOffer'
import type { JournalEntry } from './useJournal'

const campaignId = lostMineCampaign.id
const rollPath = `POST /api/campaigns/${campaignId}/journal/oracle-tables/weather`
const notePath = `POST /api/campaigns/${campaignId}/journal/notes`

function renderOffer(
  entry: JournalEntry,
  handlers: Record<string, FakeHandler> = {},
  { entries = [entry], current = true }: { entries?: JournalEntry[]; current?: boolean } = {},
) {
  return renderWithApi(
    <RandomEventOffer
      campaignId={campaignId}
      entry={entry}
      entries={entries}
      tables={lostMineCampaign.oracleTables}
      tags={lostMineCampaign.tags}
      current={current}
    />,
    handlers,
  )
}

describe('RandomEventOffer', () => {
  it('offers to roll the table a random event names, by the table name', () => {
    renderOffer(fateDouble)

    expect(screen.getByRole('button', { name: 'Roll Weather' })).toBeEnabled()
  })

  it('rolls that table through the oracle table request, naming the question', async () => {
    let body: unknown
    const { fakeApi } = renderOffer(fateDouble, {
      [rollPath]: async (request) => {
        body = await request.json()
        return Response.json(fateDoubleEvent, { status: 201 })
      },
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))

    expect(fakeApi.requests).toEqual([rollPath])
    expect(body).toEqual({ forEntry: fateDouble.id })
  })

  it('keeps the button usable when the roll fails', async () => {
    renderOffer(fateDouble, {
      [rollPath]: () => Response.json({ error: 'Start a scene first.' }, { status: 409 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))
    await screen.findByRole('alert')

    expect(screen.getByRole('button', { name: 'Roll Weather' })).toBeEnabled()
  })

  it('shows the refusal when the roll fails', async () => {
    renderOffer(fateDouble, {
      [rollPath]: () =>
        Response.json(
          { error: 'The random event of journal entry "x" was already rolled.' },
          { status: 409 },
        ),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('was already rolled')
  })

  it('offers no roll outside the current scene', () => {
    renderOffer(fateDouble, {}, { current: false })

    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  describe('once an entry references the question', () => {
    const entries = [fateDouble, fateDoubleEvent]

    it('has no button, and shows the event right there', () => {
      renderOffer(fateDouble, {}, { entries })

      expect(screen.queryByRole('button')).not.toBeInTheDocument()
      expect(screen.getByText('Dense fog')).toBeInTheDocument()
    })

    it('shows the event in a scene that is no longer current, without the prompt', () => {
      renderOffer(fateDouble, {}, { entries, current: false })

      expect(screen.getByText('Dense fog')).toBeInTheDocument()
      expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    })

    it('asks what it means while the event is the last entry of the journal', () => {
      renderOffer(fateDouble, {}, { entries })

      expect(screen.getByRole('textbox', { name: 'What does it mean?' })).toHaveAttribute(
        'placeholder',
        'What does it mean?',
      )
    })

    it('stops asking once something was recorded after the event', () => {
      renderOffer(fateDouble, {}, { entries: [...entries, gateNote] })

      expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    })

    it('records the answer as a plain note', async () => {
      let body: unknown
      const { fakeApi } = renderOffer(
        fateDouble,
        {
          [notePath]: async (request) => {
            body = await request.json()
            return Response.json(gateNote, { status: 201 })
          },
        },
        { entries },
      )
      const user = userEvent.setup()

      await user.type(
        screen.getByRole('textbox', { name: 'What does it mean?' }),
        'The guard hears nothing{Enter}',
      )

      expect(fakeApi.requests).toEqual([notePath])
      expect(body).toEqual({ text: 'The guard hears nothing' })
    })

    it('records nothing for an empty answer', async () => {
      const { fakeApi } = renderOffer(fateDouble, {}, { entries })
      const user = userEvent.setup()

      await user.type(screen.getByRole('textbox', { name: 'What does it mean?' }), '  {Enter}')

      expect(fakeApi.requests).toEqual([])
    })
  })

  it.each([
    ['an answer without a random event', lockedDoorAnswer],
    ['a note', gateNote],
    ['an oracle table result', stormyWeather],
  ])('offers nothing for %s', (_name, entry) => {
    renderOffer(entry)

    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
