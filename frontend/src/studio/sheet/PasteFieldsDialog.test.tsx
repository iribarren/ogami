import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { typeText } from '@/test/typeText'

import { draft, sheet, sheetUngrouped, validDraft } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

async function openPaste(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/sheet`]: json(sheet),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Sheet' }))
  await user.click(await screen.findByRole('button', { name: 'Paste fields' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))
const names = () => inDialog().getByLabelText('Field names to paste')

/** A POST handler that answers each request in turn and records its body. */
function answering(...responses: (() => Response)[]) {
  const sent: { expectedVersion: number; field: Record<string, unknown> }[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as (typeof sent)[number])
    const respond = responses[sent.length - 1]
    return respond ? respond() : Response.json({ error: 'Unexpected request' }, { status: 500 })
  }
  return { sent, handler }
}

const saved = (version: number) => () => Response.json({ ...sheet, version }, { status: 201 })
const refused =
  (error: string, status = 422, code?: string) =>
  () =>
    Response.json({ error, code }, { status })

describe('pasting sheet fields', () => {
  it('previews the derived keys and sends nothing until the button is pressed', async () => {
    const { sent, handler } = answering()
    const { user } = await openPaste({ [`POST ${base}/sheet/fields`]: handler })

    await typeText(user, names(), 'Hit Points\n\nLuck')

    const preview = within(inDialog().getByRole('list', { name: 'Fields to add' }))
    expect(preview.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Line 1: Hit Points hit-points',
      'Line 3: Luck luck',
    ])
    expect(inDialog().getByRole('button', { name: 'Add 2 fields' })).toBeEnabled()
    expect(sent).toEqual([])
  })

  it('adds the fields in order, each with the version the last answer returned', async () => {
    const { sent, handler } = answering(saved(8), saved(9))
    const { user } = await openPaste({ [`POST ${base}/sheet/fields`]: handler })

    await typeText(user, names(), 'Grit\nLuck')
    await typeText(user, inDialog().getByLabelText('Max'), '5')
    await user.click(inDialog().getByRole('button', { name: 'Add 2 fields' }))

    expect(await inDialog().findByText('Added 2 of 2 fields.')).toBeVisible()
    expect(sent).toEqual([
      {
        expectedVersion: 7,
        field: { key: 'grit', label: 'Grit', group: 'Stats', type: 'number', max: 5 },
      },
      {
        expectedVersion: 8,
        field: { key: 'luck', label: 'Luck', group: 'Stats', type: 'number', max: 5 },
      },
    ])
    expect(names()).toHaveValue('')
  })

  it('sends the type and group chosen, and a free-text group when none are declared', async () => {
    const { sent, handler } = answering(saved(8))
    const { user } = await openPaste({
      [`GET ${base}/sheet`]: json(sheetUngrouped),
      [`POST ${base}/sheet/fields`]: handler,
    })

    await typeText(user, names(), 'Backstory')
    await chooseOption(user, 'Type', 'Text', inDialog())
    await typeText(user, inDialog().getByLabelText('Group'), 'Notes')
    await user.click(inDialog().getByRole('button', { name: 'Add 1 field' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          expectedVersion: 4,
          field: { key: 'backstory', label: 'Backstory', group: 'Notes', type: 'text' },
        },
      ])
    })
  })

  it('refuses a key of the sheet, a repeated key and a nameless line, per line, and sends nothing', async () => {
    const { sent, handler } = answering()
    const { user } = await openPaste({ [`POST ${base}/sheet/fields`]: handler })

    await typeText(user, names(), 'Pow\nLuck\nLUCK\n!!!')

    const items = within(inDialog().getByRole('list', { name: 'Fields to add' })).getAllByRole(
      'listitem',
    )
    expect(items[0]).toHaveTextContent('The key "pow" is already a field')
    expect(items[1]).not.toHaveTextContent('already')
    expect(items[2]).toHaveTextContent('The key "luck" is already used by line 2.')
    expect(items[3]).toHaveTextContent('needs a letter or a digit')
    expect(inDialog().getByRole('button', { name: /^Add \d+ fields?$/ })).toBeDisabled()
    expect(sent).toEqual([])
  })

  it('refuses a bound that is not a whole number before sending', async () => {
    const { user } = await openPaste()

    await typeText(user, names(), 'Grit')
    await typeText(user, inDialog().getByLabelText('Min'), 'low')

    expect(inDialog().getByText('Must be a whole number.')).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Add 1 field' })).toBeDisabled()
  })

  it('stops at a refused field, says how many were added and keeps the rest for a retry', async () => {
    const { sent, handler } = answering(
      saved(8),
      refused('Field "luck": the label is too long.'),
      saved(9),
      saved(10),
    )
    const { user } = await openPaste({ [`POST ${base}/sheet/fields`]: handler })

    await typeText(user, names(), 'Grit\nLuck\nNerve')
    await user.click(inDialog().getByRole('button', { name: 'Add 3 fields' }))

    const alert = await inDialog().findByRole('alert')
    expect(alert).toHaveTextContent('Added 1 of 3 fields.')
    expect(alert).toHaveTextContent('Stopped at “Luck”: Field "luck": the label is too long.')
    expect(alert).toHaveTextContent('The 2 lines not added stay above.')
    expect(sent).toHaveLength(2)
    expect(names()).toHaveValue('Luck\nNerve')

    // The retry sends only the lines left, so Grit is not added twice.
    await user.click(inDialog().getByRole('button', { name: 'Add 2 fields' }))
    expect(await inDialog().findByText('Added 2 of 2 fields.')).toBeVisible()
    expect(sent.map((request) => request.field.key)).toEqual(['grit', 'luck', 'luck', 'nerve'])
    // The refused Luck did not move the version, so its retry sends the one Grit returned.
    expect(sent.map((request) => request.expectedVersion)).toEqual([7, 8, 8, 9])
  })

  it('stops on a conflict midway and offers the reload, then retries with the latest version', async () => {
    const { sent, handler } = answering(
      saved(8),
      refused(
        'GameSystem "my-draft" was changed by another request. Reload it and try again.',
        409,
        'modified_concurrently',
      ),
      saved(13),
    )
    let reads = 0
    const { user } = await openPaste({
      [`POST ${base}/sheet/fields`]: handler,
      [`GET ${base}/sheet`]: () => {
        reads += 1
        return Response.json({ ...sheet, version: reads === 1 ? 7 : 12 })
      },
    })

    await typeText(user, names(), 'Grit\nLuck')
    await user.click(inDialog().getByRole('button', { name: 'Add 2 fields' }))

    const alert = await inDialog().findByRole('alert')
    expect(alert).toHaveTextContent('Added 1 of 2 fields.')
    expect(alert).toHaveTextContent('was changed by another request')
    expect(names()).toHaveValue('Luck')

    await user.click(inDialog().getByRole('button', { name: 'Reload the latest version' }))
    await waitFor(() => {
      expect(inDialog().queryByRole('button', { name: 'Reload the latest version' })).toBeNull()
    })
    await user.click(inDialog().getByRole('button', { name: 'Add 1 field' }))

    expect(await inDialog().findByText('Added 1 of 1 field.')).toBeVisible()
    expect(sent.map((request) => request.expectedVersion)).toEqual([7, 8, 12])
  })

  it("starts from the sheet's current groups each time it opens", async () => {
    const { user, queryClient } = await openPaste()
    await user.click(inDialog().getByRole('button', { name: 'Close' }))

    // The groups change while the dialog is closed, as after a rename in the sheet tab.
    queryClient.setQueryData(['studio', 'game-systems', 'my-draft', 'sheet'], {
      ...sheet,
      groups: [{ name: 'Core', columns: 1, span: 1 }],
    })
    await user.click(await screen.findByRole('button', { name: 'Paste fields' }))
    await typeText(user, names(), 'Grit')

    expect(inDialog().queryByText('Pick a group.')).toBeNull()
    expect(inDialog().getByRole('button', { name: 'Add 1 field' })).toBeEnabled()
  })

  it('reads the sheet again when a request got no answer, and does not send a line that landed twice', async () => {
    const sent: { field: Record<string, unknown> }[] = []
    let reads = 0
    const { user } = await openPaste({
      [`POST ${base}/sheet/fields`]: async (request) => {
        sent.push((await request.json()) as (typeof sent)[number])
        return Promise.reject(new TypeError('Failed to fetch'))
      },
      [`GET ${base}/sheet`]: () => {
        reads += 1
        // The first read opens the tab; the next one shows that Grit landed.
        return Response.json(
          reads === 1
            ? sheet
            : {
                ...sheet,
                version: 8,
                fields: [
                  ...sheet.fields,
                  { key: 'grit', label: 'Grit', group: 'Stats', type: 'number' },
                ],
              },
        )
      },
    })

    await typeText(user, names(), 'Grit')
    await user.click(inDialog().getByRole('button', { name: 'Add 1 field' }))

    expect(await inDialog().findByText(/Stopped at “Grit”/)).toBeVisible()
    await waitFor(() => {
      expect(reads).toBe(2)
    })
    expect(await inDialog().findByText(/The key "grit" is already a field/)).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Add 1 field' })).toBeDisabled()
    expect(sent).toHaveLength(1)
  })
})
