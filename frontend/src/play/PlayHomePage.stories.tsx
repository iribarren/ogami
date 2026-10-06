import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { freeJournal, lostMine, mythicStyle, newCampaign, sunkenTemple } from './campaigns/fixtures'
import { PlayHomePage } from './PlayHomePage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const playApi = {
  'GET /api/campaigns': json([lostMine, sunkenTemple]),
  'GET /api/play/game-systems': json([freeJournal, mythicStyle]),
  'POST /api/campaigns': json(newCampaign('The haunted keep', freeJournal), 201),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Play/PlayHomePage',
  component: PlayHomePage,
  parameters: { api: playApi },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof PlayHomePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithCampaigns: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('link', { name: 'The lost mine' })).toBeInTheDocument()
  },
}

export const NoCampaigns: Story = {
  parameters: { api: { ...playApi, 'GET /api/campaigns': json([]) } },
}

export const NoGameSystemPublished: Story = {
  parameters: {
    api: { ...playApi, 'GET /api/campaigns': json([]), 'GET /api/play/game-systems': json([]) },
  },
}

export const CampaignsUnavailable: Story = {
  parameters: {
    api: { ...playApi, 'GET /api/campaigns': () => new Response('Oops', { status: 500 }) },
  },
}

export const CreateRefused: Story = {
  parameters: {
    api: {
      ...playApi,
      'POST /api/campaigns': json(
        { error: 'There is no published GameSystem "mythic-style".' },
        404,
      ),
    },
  },
  play: async ({ canvasElement }) => {
    const form = within(await within(canvasElement).findByRole('form', { name: 'New campaign' }))
    await userEvent.type(form.getByLabelText('Campaign name'), 'The haunted keep')
    await userEvent.click(form.getByRole('button', { name: 'Create campaign' }))
    await expect(await form.findByRole('alert')).toBeInTheDocument()
  },
}

export const Creating: Story = {
  parameters: {
    api: { ...playApi, 'POST /api/campaigns': () => new Promise<Response>(() => undefined) },
  },
  play: async ({ canvasElement }) => {
    const form = within(await within(canvasElement).findByRole('form', { name: 'New campaign' }))
    await userEvent.type(form.getByLabelText('Campaign name'), 'The haunted keep')
    await userEvent.click(form.getByRole('button', { name: 'Create campaign' }))
    await expect(form.getByRole('button', { name: 'Create campaign' })).toBeDisabled()
  },
}
