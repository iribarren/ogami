import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { Button } from '@/shared/ui/button'
import { renderWithApi } from '@/test/renderWithApi'

import { narrative } from '../fixtures'
import { ItemDialog } from './ItemDialog'
import { tagForm } from './itemForms'
import { tagToDraft } from './narrativeDraft'

const base = '/api/studio/game-systems/my-draft/narrative'

describe('the item dialog', () => {
  it('keeps the old version and the form when the item is gone after a conflict', async () => {
    const user = userEvent.setup()
    const versions: number[] = []
    let reads = 0
    renderWithApi(
      <ItemDialog
        gameSystemKey="my-draft"
        form={tagForm}
        version={5}
        original="informer"
        initial={tagToDraft(narrative.tags[1]?.definition ?? {}) ?? tagForm.blank()}
        trigger={<Button>Edit informer</Button>}
      />,
      {
        [`GET ${base}`]: () =>
          Response.json(
            ++reads === 1 ? narrative : { ...narrative, version: 8, tags: [narrative.tags[0]] },
          ),
        [`PUT ${base}/tags/informer`]: async (request) => {
          versions.push(((await request.json()) as { expectedVersion: number }).expectedVersion)
          return Response.json({ error: 'changed by another request' }, { status: 409 })
        },
      },
    )

    await user.click(screen.getByRole('button', { name: 'Edit informer' }))
    const dialog = within(screen.getByRole('dialog'))
    await user.type(dialog.getByLabelText('Hint'), 'Mine')
    await user.click(dialog.getByRole('button', { name: 'Save tag' }))
    await user.click(await dialog.findByRole('button', { name: 'Reload the latest version' }))

    expect(
      await dialog.findByText('The tag informer no longer exists.', { exact: false }),
    ).toBeInTheDocument()
    expect(dialog.getByLabelText('Hint')).toHaveValue('Mine')
    await user.click(dialog.getByRole('button', { name: 'Save tag' }))
    await waitFor(() => {
      expect(versions).toEqual([5, 5])
    })
  })
})
