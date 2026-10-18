import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { packSystem } from './fixtures'
import { NewLibraryPackDialog } from './NewLibraryPackDialog'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Studio/Library/NewLibraryPackDialog',
  component: NewLibraryPackDialog,
  parameters: { api: { 'POST /api/studio/library/packs': json(packSystem, 201) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof NewLibraryPackDialog>

export default meta
type Story = StoryObj<typeof meta>

async function openDialog(canvasElement: HTMLElement) {
  await userEvent.click(await within(canvasElement).findByRole('button', { name: 'New pack' }))
  return within(await within(document.body).findByRole('form', { name: 'New library pack' }))
}

export const Blank: Story = {
  play: async ({ canvasElement }) => {
    const form = await openDialog(canvasElement)
    await expect(form.getByRole('button', { name: 'Create pack' })).toBeDisabled()
  },
}

export const KeyTaken: Story = {
  parameters: {
    api: {
      'POST /api/studio/library/packs': json(
        { error: 'There is already a GameSystem with the key "fantasy-basics".' },
        409,
      ),
    },
  },
  play: async ({ canvasElement }) => {
    const form = await openDialog(canvasElement)
    await userEvent.type(form.getByLabelText('Key'), 'fantasy-basics')
    await userEvent.type(form.getByLabelText('Name'), 'Fantasy basics')
    await userEvent.click(form.getByRole('button', { name: 'Create pack' }))
    await expect(await form.findByRole('alert')).toBeInTheDocument()
  },
}
