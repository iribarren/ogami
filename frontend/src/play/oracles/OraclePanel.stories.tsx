import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import { lockedDoorAnswer, stormyWeather } from '../journal/fixtures'
import { OraclePanel } from './OraclePanel'

const journalPath = `/api/campaigns/${lostMineCampaign.id}/journal`

const oracleApi = {
  [`POST ${journalPath}/oracle-tables/weather`]: () =>
    Response.json(stormyWeather, { status: 201 }),
  [`POST ${journalPath}/oracle-tables/action`]: () =>
    Response.json({ error: 'The campaign has no current scene.' }, { status: 409 }),
  [`POST ${journalPath}/likelihood-oracles/fate`]: () =>
    Response.json(lockedDoorAnswer, { status: 201 }),
  [`POST ${journalPath}/likelihood-oracles/yes-no`]: () =>
    Response.json({ error: 'The chaos factor must be omitted.' }, { status: 422 }),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Play/OraclePanel',
  component: OraclePanel,
  args: {
    campaignId: lostMineCampaign.id,
    tables: lostMineCampaign.oracleTables,
    likelihoodOracles: lostMineCampaign.likelihoodOracles,
  },
  decorators: [
    (Story) => (
      <StoryProviders handlers={oracleApi}>
        <div className="max-w-sm">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof OraclePanel>

export default meta
type Story = StoryObj<typeof meta>

export const Oracles: Story = {}

/** No current scene: there is nowhere to record an answer. */
export const Disabled: Story = { args: { disabled: true } }

export const NoOracles: Story = { args: { tables: [], likelihoodOracles: [] } }

export const TableRefused: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Roll on Action' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent(
      'The campaign has no current scene.',
    )
  },
}

export const QuestionRefused: Story = {
  play: async ({ canvasElement }) => {
    const form = within(await within(canvasElement).findByRole('form', { name: 'Yes/no question' }))
    await userEvent.click(form.getByRole('button', { name: 'Ask' }))
    await expect(await form.findByRole('alert')).toBeInTheDocument()
  },
}
