import type { Meta, StoryObj } from '@storybook/react-vite'

import { Button } from './button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from './collapsible'

const meta = {
  title: 'Shared/UI/Collapsible',
  component: Collapsible,
} satisfies Meta<typeof Collapsible>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <Collapsible {...args} className="w-72 space-y-2" defaultOpen>
      <CollapsibleTrigger asChild>
        <Button variant="outline" size="sm">
          Toggle facts
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="rounded-lg border p-3 text-sm">
        The harbour master owes the party a favour.
      </CollapsibleContent>
    </Collapsible>
  ),
}

export const Closed: Story = {
  ...Default,
  render: (args) => (
    <Collapsible {...args} className="w-72 space-y-2">
      <CollapsibleTrigger asChild>
        <Button variant="outline" size="sm">
          Toggle facts
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="rounded-lg border p-3 text-sm">
        Hidden until opened.
      </CollapsibleContent>
    </Collapsible>
  ),
}
