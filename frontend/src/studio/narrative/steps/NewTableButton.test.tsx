import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { NewTableButton } from './NewTableButton'

/** A create the test settles by hand, so the dialog can be poked while it is pending. */
function pendingCreate() {
  let fail: (error: Error) => void = () => undefined
  const create = vi.fn(
    () =>
      new Promise<void>((_resolve, reject) => {
        fail = reject
      }),
  )
  return {
    create,
    fail: (error: Error) => {
      fail(error)
    },
  }
}

async function startCreate(create: () => Promise<void>) {
  const user = userEvent.setup()
  render(<NewTableButton capability={{ gameSystemKey: 'my-draft', create }} onCreated={vi.fn()} />)
  await user.click(screen.getByRole('button', { name: 'New table' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByLabelText('Key'), 'omens')
  await user.type(within(dialog).getByLabelText('Name'), 'Omens')
  await user.click(within(dialog).getByRole('button', { name: 'Create table' }))
  await waitFor(() => {
    expect(create).toHaveBeenCalledTimes(1)
  })
  return { user, dialog }
}

describe('NewTableButton while a create is pending', () => {
  it('stays open on Escape', async () => {
    const { create } = pendingCreate()
    const { user } = await startCreate(create)

    await user.keyboard('{Escape}')

    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('stays open on a click outside', async () => {
    const { create } = pendingCreate()
    await startCreate(create)

    fireEvent.pointerDown(document.body)
    fireEvent.pointerUp(document.body)
    fireEvent.click(document.body)

    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('shows the failure in the open dialog, which can then be dismissed', async () => {
    const { create, fail } = pendingCreate()
    const { user } = await startCreate(create)
    await user.keyboard('{Escape}')

    fail(new Error('The key "omens" is already used by a table.'))

    expect(await screen.findByRole('alert')).toHaveTextContent('already used by a table')
    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})
