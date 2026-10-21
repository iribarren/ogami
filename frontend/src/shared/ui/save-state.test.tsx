import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { SaveState } from './save-state'

describe('SaveState', () => {
  it('says Saving without a role', () => {
    render(<SaveState state={{ kind: 'saving' }} />)

    expect(screen.getByText('Saving…')).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('announces the time of a save as a status', () => {
    render(<SaveState state={{ kind: 'saved', at: new Date(2026, 9, 22, 9, 5) }} />)

    expect(screen.getByRole('status')).toHaveTextContent(/^Saved at .*(09|9).*05/)
  })

  it('announces a failed save as an alert with a count that agrees with the number', () => {
    const { rerender } = render(<SaveState state={{ kind: 'failed', errors: 2 }} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 2 errors')
    expect(screen.queryByRole('button', { name: 'Go to the first' })).not.toBeInTheDocument()

    rerender(<SaveState state={{ kind: 'failed', errors: 1 }} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 1 error')
  })

  it('offers Go to the first when it knows where', async () => {
    const user = userEvent.setup()
    const onGoToFirst = vi.fn()
    render(<SaveState state={{ kind: 'failed', errors: 3, onGoToFirst }} />)

    await user.click(screen.getByRole('button', { name: 'Go to the first' }))
    expect(onGoToFirst).toHaveBeenCalledTimes(1)
  })

  it('shows unsaved changes quietly', () => {
    render(<SaveState state={{ kind: 'unsaved' }} />)

    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
