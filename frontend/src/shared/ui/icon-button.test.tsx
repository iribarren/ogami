import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Trash2 } from 'lucide-react'

import { IconButton } from './icon-button'

describe('IconButton', () => {
  it('is named by its label and hides the icon from assistive technology', () => {
    render(
      <IconButton label="Delete">
        <Trash2 data-testid="icon" />
      </IconButton>,
    )

    expect(screen.getByRole('button', { name: 'Delete' })).toHaveAttribute('type', 'button')
    expect(screen.getByTestId('icon')).toHaveAttribute('aria-hidden', 'true')
  })

  it('shows the label as a tooltip on hover and on keyboard focus', async () => {
    const user = userEvent.setup()
    render(
      <>
        <IconButton label="Move up">
          <Trash2 />
        </IconButton>
        <IconButton label="Move down">
          <Trash2 />
        </IconButton>
      </>,
    )

    await user.hover(screen.getByRole('button', { name: 'Move up' }))
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Move up')

    await user.unhover(screen.getByRole('button', { name: 'Move up' }))
    await user.tab()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Move down' })).toHaveFocus()
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Move down')
  })

  it('calls onClick and ignores it when disabled', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()
    const { rerender } = render(
      <IconButton label="Edit" onClick={onClick}>
        <Trash2 />
      </IconButton>,
    )

    await user.click(screen.getByRole('button', { name: 'Edit' }))
    expect(onClick).toHaveBeenCalledTimes(1)

    rerender(
      <IconButton label="Edit" onClick={onClick} disabled>
        <Trash2 />
      </IconButton>,
    )
    await user.click(screen.getByRole('button', { name: 'Edit' }))
    expect(onClick).toHaveBeenCalledTimes(1)
  })
})
