import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { heatTracker, lostMineCampaign } from '../campaigns/fixtures'
import { recordedEntry, stormyWeather, lockedDoorAnswer } from '../journal/fixtures'
import { OraclePanel } from './OraclePanel'

const campaignId = lostMineCampaign.id
const journalPath = `/api/campaigns/${campaignId}/journal`

/** Answers with `body` (201 by default), recording each request body sent. */
function recording(body: unknown, sent: unknown[] = [], status = 201): FakeHandler {
  return async (request) => {
    const text = await request.text()
    sent.push(text === '' ? undefined : JSON.parse(text))
    return Response.json(body, { status })
  }
}

function renderPanel(handlers: Record<string, FakeHandler>, disabled = false) {
  return renderWithApi(
    <OraclePanel
      campaignId={campaignId}
      tables={lostMineCampaign.oracleTables}
      likelihoodOracles={lostMineCampaign.likelihoodOracles}
      disabled={disabled}
    />,
    handlers,
  )
}

function oracleForm(name: string) {
  return screen.getByRole('form', { name })
}

describe('OraclePanel', () => {
  it('rolls on an oracle table of the release by its key', async () => {
    const sent: unknown[] = []
    const { fakeApi } = renderPanel({
      [`POST ${journalPath}/oracle-tables/weather`]: recording(stormyWeather, sent),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll on Weather' }))

    expect(fakeApi.requests).toEqual([`POST ${journalPath}/oracle-tables/weather`])
    expect(sent).toEqual([undefined])
  })

  it('asks a likelihood oracle with the chosen level, question and chaos factor', async () => {
    const sent: unknown[] = []
    renderPanel({
      [`POST ${journalPath}/likelihood-oracles/fate`]: recording(lockedDoorAnswer, sent),
    })
    const user = userEvent.setup()
    const form = within(oracleForm('Fate question'))

    expect(form.getByLabelText('Likelihood')).toHaveValue('even')
    expect(form.getByLabelText('Chaos factor')).toHaveValue(5)
    await user.selectOptions(form.getByLabelText('Likelihood'), 'Likely')
    await user.type(form.getByLabelText('Question (optional)'), '  Is the door locked? ')
    await user.clear(form.getByLabelText('Chaos factor'))
    await user.type(form.getByLabelText('Chaos factor'), '7')
    await user.click(form.getByRole('button', { name: 'Ask' }))

    await vi.waitFor(() => {
      expect(sent).toEqual([
        { likelihood: 'likely', question: 'Is the door locked?', chaosFactor: 7 },
      ])
    })
    await vi.waitFor(() => {
      expect(form.getByLabelText('Question (optional)')).toHaveValue('')
    })
  })

  it('leaves out an empty question and a cleared chaos factor', async () => {
    const sent: unknown[] = []
    renderPanel({
      [`POST ${journalPath}/likelihood-oracles/fate`]: recording(lockedDoorAnswer, sent),
    })
    const user = userEvent.setup()
    const form = within(oracleForm('Fate question'))

    await user.clear(form.getByLabelText('Chaos factor'))
    await user.click(form.getByRole('button', { name: 'Ask' }))

    await vi.waitFor(() => {
      expect(sent).toEqual([{ likelihood: 'even' }])
    })
  })

  it('offers no chaos factor for an oracle without chaos, and sends none', async () => {
    const sent: unknown[] = []
    renderPanel({
      [`POST ${journalPath}/likelihood-oracles/yes-no`]: recording(
        recordedEntry(lockedDoorAnswer.content),
        sent,
      ),
    })
    const user = userEvent.setup()
    const form = within(oracleForm('Yes/no question'))

    expect(form.queryByLabelText('Chaos factor')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Ask' }))

    await vi.waitFor(() => {
      expect(sent).toEqual([{ likelihood: 'even' }])
    })
  })

  it('shows the chaos factor a Tracker holds instead of asking for it, and sends none', async () => {
    const sent: unknown[] = []
    const fate = {
      key: 'fate',
      name: 'Fate question',
      levels: [{ key: 'even', label: 'Even odds' }],
      chaos: { min: 1, max: 9, neutral: 5 },
      chaosTracker: 'chaos',
    }
    const chaos = {
      ...heatTracker,
      key: 'chaos',
      name: 'Chaos factor',
      min: 1,
      max: 9,
      levels: [],
      value: 6,
      levelLabel: null,
    }
    renderWithApi(<OraclePanel campaignId={campaignId} tables={[]} likelihoodOracles={[fate]} />, {
      [`GET /api/campaigns/${campaignId}`]: () =>
        Response.json({ ...lostMineCampaign, trackers: [chaos] }),
      [`POST ${journalPath}/likelihood-oracles/fate`]: recording(lockedDoorAnswer, sent),
    })
    const user = userEvent.setup()
    const form = within(oracleForm('Fate question'))

    expect(await form.findByText('6 (from the Chaos factor tracker)')).toBeInTheDocument()
    expect(form.getByText('Chaos factor')).toBeInTheDocument()
    expect(form.queryByRole('spinbutton')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Ask' }))

    await vi.waitFor(() => {
      expect(sent).toEqual([{ likelihood: 'even' }])
    })
  })

  it("shows the API's reason when an oracle is refused", async () => {
    renderPanel({
      [`POST ${journalPath}/likelihood-oracles/fate`]: recording(
        { error: 'The chaos factor must be between 1 and 9.' },
        [],
        422,
      ),
      [`POST ${journalPath}/oracle-tables/action`]: recording(
        { error: 'The campaign has no current scene.' },
        [],
        409,
      ),
    })
    const user = userEvent.setup()

    await user.click(within(oracleForm('Fate question')).getByRole('button', { name: 'Ask' }))
    await user.click(screen.getByRole('button', { name: 'Roll on Action' }))

    expect(await within(oracleForm('Fate question')).findByRole('alert')).toHaveTextContent(
      'The chaos factor must be between 1 and 9.',
    )
    expect(
      await screen.findByText('The campaign has no current scene.', { selector: '[role=alert]' }),
    ).toBeInTheDocument()
  })

  it('disables every oracle when there is nowhere to record the answer', () => {
    renderPanel({}, true)

    expect(screen.getByRole('button', { name: 'Roll on Weather' })).toBeDisabled()
    const fate = within(oracleForm('Fate question'))
    expect(fate.getByRole('button', { name: 'Ask' })).toBeDisabled()
    expect(fate.getByLabelText('Likelihood')).toBeDisabled()
    expect(fate.getByLabelText('Question (optional)')).toBeDisabled()
    expect(fate.getByLabelText('Chaos factor')).toBeDisabled()
  })

  it('says when the release has no oracles', () => {
    renderWithApi(<OraclePanel campaignId={campaignId} tables={[]} likelihoodOracles={[]} />)

    expect(screen.getByText('This game system release has no oracles.')).toBeInTheDocument()
  })

  describe('ordered by Scene Type and Flow', () => {
    function renderOrdered(overrides: Partial<Parameters<typeof OraclePanel>[0]> = {}) {
      return renderWithApi(
        <OraclePanel
          campaignId={campaignId}
          tables={lostMineCampaign.oracleTables}
          likelihoodOracles={lostMineCampaign.likelihoodOracles}
          sceneOracleKeys={['fate', 'action']}
          flow={{ name: 'Heist', oracleKeys: ['yes-no', 'fate'] }}
          {...overrides}
        />,
      )
    }

    it('shows the Scene shortcuts, then the Flow oracles not shown yet, then the rest', () => {
      renderOrdered()

      const scene = within(screen.getByRole('region', { name: 'Scene' }))
      expect(scene.getByRole('form', { name: 'Fate question' })).toBeInTheDocument()
      expect(scene.getByRole('button', { name: 'Roll on Action' })).toBeInTheDocument()
      // "fate" is listed by the Flow too, but shows once.
      const flow = within(screen.getByRole('region', { name: 'Flow: Heist' }))
      expect(flow.getByRole('form', { name: 'Yes/no question' })).toBeInTheDocument()
      expect(flow.queryByRole('form', { name: 'Fate question' })).not.toBeInTheDocument()
      expect(screen.getAllByRole('form', { name: 'Fate question' })).toHaveLength(1)
      const headings = screen
        .getAllByRole('heading', { level: 3 })
        .map(({ textContent }) => textContent)
      expect(headings.indexOf('Scene')).toBeLessThan(headings.indexOf('Flow: Heist'))
    })

    it('collapses every other oracle under "More oracles" until it is opened', async () => {
      renderOrdered()
      const user = userEvent.setup()

      const weather = screen.getByRole('button', { name: 'Roll on Weather' })
      expect(weather).not.toBeVisible()
      expect(screen.getByText('More oracles').closest('details')).not.toHaveAttribute('open')
      await user.click(screen.getByText('More oracles'))
      expect(weather).toBeVisible()
    })

    it('rolls and asks the oracles of every section by their keys', async () => {
      const sent: unknown[] = []
      const { fakeApi } = renderWithApi(
        <OraclePanel
          campaignId={campaignId}
          tables={lostMineCampaign.oracleTables}
          likelihoodOracles={lostMineCampaign.likelihoodOracles}
          sceneOracleKeys={['action']}
        />,
        { [`POST ${journalPath}/oracle-tables/action`]: recording(stormyWeather, sent) },
      )
      const user = userEvent.setup()

      await user.click(
        within(screen.getByRole('region', { name: 'Scene' })).getByRole('button', {
          name: 'Roll on Action',
        }),
      )

      expect(fakeApi.requests).toEqual([`POST ${journalPath}/oracle-tables/action`])
    })

    it('drops the sections that hold nothing, and "More oracles" when nothing is left', () => {
      renderOrdered({
        sceneOracleKeys: ['fate', 'yes-no', 'weather', 'action'],
        flow: { name: 'Heist', oracleKeys: ['fate', 'unknown'] },
      })

      expect(screen.queryByRole('region', { name: 'Flow: Heist' })).not.toBeInTheDocument()
      expect(screen.queryByText('More oracles')).not.toBeInTheDocument()
      expect(screen.getByRole('region', { name: 'Scene' })).toBeInTheDocument()
    })

    it('shows every oracle with no section when neither the Scene nor the Flow picks one', () => {
      renderOrdered({ sceneOracleKeys: [], flow: { name: 'Heist', oracleKeys: [] } })

      expect(screen.queryByText('More oracles')).not.toBeInTheDocument()
      expect(screen.queryByRole('region', { name: 'Scene' })).not.toBeInTheDocument()
      expect(screen.getByRole('form', { name: 'Yes/no question' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Roll on Weather' })).toBeVisible()
    })
  })
})
