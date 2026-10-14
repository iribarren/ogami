import type { Meta, StoryObj } from '@storybook/react-vite'

import { Button } from './button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from './dialog'

const meta = {
  title: 'Shared/UI/Dialog',
  component: Dialog,
} satisfies Meta<typeof Dialog>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <Dialog {...args}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          Open dialog
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Roll a check</DialogTitle>
          <DialogDescription>Pick the check, then roll it for Vi.</DialogDescription>
        </DialogHeader>
        <DialogClose asChild>
          <Button variant="ghost" size="sm">
            Close
          </Button>
        </DialogClose>
      </DialogContent>
    </Dialog>
  ),
}

export const Open: Story = {
  ...Default,
  args: { defaultOpen: true },
}
