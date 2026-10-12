import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { SegmentedControl, SegmentedControlItem } from './segmented-control'

function Example({ onValueChange }: { onValueChange?: (value: string) => void }) {
  return (
    <SegmentedControl aria-label="View" defaultValue="journal" onValueChange={onValueChange}>
      <SegmentedControlItem value="journal">Journal</SegmentedControlItem>
      <SegmentedControlItem value="focus">Focus</SegmentedControlItem>
    </SegmentedControl>
  )
}

describe('SegmentedControl', () => {
  it('exposes a labelled radio group with the current choice checked', () => {
    render(<Example />)

    expect(screen.getByRole('radiogroup', { name: 'View' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Journal' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'Focus' })).not.toBeChecked()
  })

  it('changes the choice on click and moves focus with the arrow keys', async () => {
    const user = userEvent.setup()
    const onValueChange = vi.fn()
    render(<Example onValueChange={onValueChange} />)

    await user.click(screen.getByRole('radio', { name: 'Focus' }))
    expect(onValueChange).toHaveBeenLastCalledWith('focus')
    expect(screen.getByRole('radio', { name: 'Focus' })).toBeChecked()

    expect(screen.getByRole('radio', { name: 'Focus' })).toHaveFocus()
    await user.keyboard('{ArrowLeft}')
    expect(screen.getByRole('radio', { name: 'Journal' })).toHaveFocus()
  })
})
