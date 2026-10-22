import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'

import { chooseOption } from '@/test/chooseOption'

import { Label } from './label'
import { Select } from './select'

function Harness({ initial = '' }: { initial?: string }) {
  const [value, setValue] = useState(initial)
  return (
    <>
      <Label htmlFor="tracker">Tracker</Label>
      <Select
        id="tracker"
        value={value}
        onChange={(event) => {
          setValue(event.target.value)
        }}
      >
        <option value="">Choose a tracker…</option>
        <option value="heat">Heat</option>
        <option value="luck">Luck</option>
      </Select>
      <output>{`value:${value}`}</output>
    </>
  )
}

describe('Select', () => {
  it('is a combobox named by its label, showing the selected option', () => {
    render(<Harness initial="heat" />)

    const trigger = screen.getByRole('combobox', { name: 'Tracker' })
    expect(trigger).toHaveTextContent('Heat')
    expect(trigger).toHaveValue('heat')
  })

  it('keeps the empty value a real, selectable option', async () => {
    const user = userEvent.setup()
    render(<Harness initial="heat" />)

    await chooseOption(user, 'Tracker', 'Choose a tracker…')

    expect(screen.getByText('value:')).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Tracker' })).toHaveTextContent('Choose a tracker…')
    expect(screen.getByRole('combobox', { name: 'Tracker' })).toHaveValue('')
  })

  it('chooses with the keyboard: arrows, then Enter', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    screen.getByRole('combobox', { name: 'Tracker' }).focus()
    await user.keyboard('{Enter}')
    const list = within(await screen.findByRole('listbox'))
    expect(list.getAllByRole('option').map((option) => option.textContent)).toEqual([
      'Choose a tracker…',
      'Heat',
      'Luck',
    ])
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}')

    expect(screen.getByText('value:luck')).toBeInTheDocument()
  })

  it('fails closed on a value that is not an option', () => {
    render(<Harness initial="gone" />)

    const trigger = screen.getByRole('combobox', { name: 'Tracker' })
    expect(trigger).toHaveTextContent('gone (not an option)')
    expect(trigger).toBeInvalid()
  })
})
