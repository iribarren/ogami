import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'

import { onTheme } from '@/shared/theme/themeStories'

import { Label } from './label'
import { Select, type SelectProps } from './select'

/** Controlled, with the empty option ("Choose a tracker…") as a real choice. */
function Controlled(args: Omit<SelectProps, 'value' | 'onChange' | 'children'>) {
  const [value, setValue] = useState('')
  return (
    <div className="w-72 space-y-2">
      <Label htmlFor={args.id}>Tracker</Label>
      <Select
        {...args}
        value={value}
        onChange={(event) => {
          setValue(event.target.value)
        }}
      >
        <option value="">Choose a tracker…</option>
        <option value="heat">Heat</option>
        <option value="luck">Luck</option>
        <option value="hp">Hit points</option>
      </Select>
    </div>
  )
}

const meta = {
  title: 'Shared/UI/Select',
  component: Select,
  args: { id: 'tracker', value: '', children: null },
  render: (args) => <Controlled id={args.id} size={args.size} disabled={args.disabled} />,
} satisfies Meta<typeof Select>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

/** The 32px height for dense rows. */
export const Dense: Story = { args: { size: 'sm' } }

export const Disabled: Story = { args: { disabled: true } }

export const Parchment: Story = onTheme('parchment', Default)
export const Neon: Story = onTheme('neon', Default)
export const Noir: Story = onTheme('noir', Default)
export const Eldritch: Story = onTheme('eldritch', Default)
export const Starship: Story = onTheme('starship', Default)
