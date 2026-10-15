import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { draft } from './fixtures'
import { NewGameSystemDialog } from './NewGameSystemDialog'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Studio/NewGameSystemDialog',
  component: NewGameSystemDialog,
  parameters: { api: { 'POST /api/studio/game-systems': json(draft, 201) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof NewGameSystemDialog>

export default meta
type Story = StoryObj<typeof meta>

async function openDialog(canvasElement: HTMLElement) {
  await userEvent.click(
    await within(canvasElement).findByRole('button', { name: 'New GameSystem' }),
  )
  return within(await within(document.body).findByRole('form', { name: 'New GameSystem' }))
}

export const Blank: Story = {
  play: async ({ canvasElement }) => {
    const form = await openDialog(canvasElement)
    await expect(form.getByRole('button', { name: 'Create GameSystem' })).toBeDisabled()
  },
}

export const CopyOfARelease: Story = {
  play: async ({ canvasElement }) => {
    const form = await openDialog(canvasElement)
    await userEvent.click(within(document.body).getByRole('radio', { name: 'Copy a release' }))
    await expect(form.getByLabelText('Source GameSystem key')).toBeInTheDocument()
  },
}

export const KeyTaken: Story = {
  parameters: {
    api: {
      'POST /api/studio/game-systems': json(
        { error: 'There is already a GameSystem with the key "my-draft".' },
        409,
      ),
    },
  },
  play: async ({ canvasElement }) => {
    const form = await openDialog(canvasElement)
    await userEvent.type(form.getByLabelText('Key'), 'my-draft')
    await userEvent.type(form.getByLabelText('Name'), 'My draft')
    await userEvent.click(form.getByRole('button', { name: 'Create GameSystem' }))
    await expect(await form.findByRole('alert')).toBeInTheDocument()
  },
}
