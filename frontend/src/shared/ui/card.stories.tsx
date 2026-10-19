import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
import { Button } from './button'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from './card'

const meta = {
  title: 'Shared/UI/Card',
  component: Card,
  decorators: [
    (Story) => (
      <div className="w-96">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof Card>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: (args) => (
    <Card {...args}>
      <CardHeader>
        <CardTitle>The sunken library</CardTitle>
        <CardDescription>Campaign for Ink &amp; Ashes, session 3</CardDescription>
        <CardAction>
          <Button size="sm" variant="outline">
            Open
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>The lamp gutters as you descend the stairs.</CardContent>
      <CardFooter>
        <span className="text-sm text-muted-foreground">Last played yesterday</span>
      </CardFooter>
    </Card>
  ),
}

export const Small: Story = { ...Default, args: { size: 'sm' } }

export const Parchment: Story = onTheme('parchment', Default)
export const Neon: Story = onTheme('neon', Default)
export const Noir: Story = onTheme('noir', Default)
export const Eldritch: Story = onTheme('eldritch', Default)
export const Starship: Story = onTheme('starship', Default)
