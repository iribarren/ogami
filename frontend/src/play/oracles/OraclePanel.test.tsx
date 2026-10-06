import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
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

    expect(screen.getByText('This GameSystem release has no oracles.')).toBeInTheDocument()
  })
})
