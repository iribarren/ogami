import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, sheet, sheetUngrouped, sheetV1, validDraft } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openSheet(handlers: Record<string, FakeHandler> = {}) {
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
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))

/** What a write sent: its JSON body. */
function recorder(response: Response | (() => Response)) {
  const sent: Record<string, unknown>[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as Record<string, unknown>)
    return typeof response === 'function' ? response() : response
  }
  return { sent, handler }
}

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

const labelsIn = (region: HTMLElement) =>
  within(region)
    .getAllByRole('listitem')
    .map((item) => item.querySelector('.list-row-title')?.textContent)

describe('the sheet tab', () => {
  it('lists the fields under the declared groups in order, an empty group included', async () => {
    await openSheet()

    const stats = within(await screen.findByRole('region', { name: 'Stats' }))
    expect(labelsIn(stats.getByRole('list'))).toEqual(['Power', 'Dexterity'])
    const [power] = stats.getAllByRole('listitem')
    expect(power).toHaveTextContent('pow')
    expect(power).toHaveTextContent('number')
    const health = within(screen.getByRole('region', { name: 'Health' }))
    expect(labelsIn(health.getByRole('list'))).toEqual(['Hit points', 'Bonus'])
    expect(health.getAllByRole('listitem')[1]).toHaveTextContent('derived')
    expect(
      within(screen.getByRole('region', { name: 'Notes' })).getByText('No fields'),
    ).toBeVisible()
    expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual([
      'Stats',
      'Health',
      'Notes',
    ])
  })

  it('shows the range of a bounded field and nothing for a field without bounds', async () => {
    await openSheet()

    const stats = within(await screen.findByRole('region', { name: 'Stats' }))
    const [power, dexterity] = stats.getAllByRole('listitem')
    expect(power).toHaveTextContent('0 to 18')
    expect(dexterity).not.toHaveTextContent(' to ')
  })

  it('words a one-sided range and writes negative bounds with a minus sign', async () => {
    const field = { type: 'number' as const }
    await openSheet({
      [`GET ${base}/sheet`]: json({
        ...sheetUngrouped,
        fields: [
          { ...field, key: 'low', label: 'Low', min: 2 },
          { ...field, key: 'high', label: 'High', max: 5 },
          { ...field, key: 'swing', label: 'Swing', min: -1, max: 3 },
        ],
      }),
    })

    const rows = await screen.findAllByRole('listitem')
    const row = (label: string) => rows.find((item) => item.textContent.includes(label))
    expect(row('Low')).toHaveTextContent('2 or more')
    expect(row('High')).toHaveTextContent('up to 5')
    expect(row('Swing')).toHaveTextContent('\u22121 to 3')
  })

  it('without declared groups lists in order, with a heading each time the group changes', async () => {
    await openSheet({ [`GET ${base}/sheet`]: json(sheetUngrouped) })

    await screen.findByRole('button', { name: 'Edit pow' })
    expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual([
      'Stats',
      'Health',
    ])
    expect(
      within(screen.getByRole('region', { name: 'Fields without a group' })).getByText('Notes'),
    ).toBeVisible()
  })

  it('lists a field of a shape the editor cannot read, without editing it', async () => {
    await openSheet({
      [`GET ${base}/sheet`]: json({
        ...sheetUngrouped,
        fields: [{ key: 'odd', label: 'Odd', type: 'number', min: 'low' }],
      }),
    })

    expect(await screen.findByText("Can't be edited here")).toBeVisible()
    expect(screen.getByRole('button', { name: 'Edit odd' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Delete odd' })).toBeEnabled()
  })

  it('says so when the draft has no fields', async () => {
    await openSheet({ [`GET ${base}/sheet`]: json({ ...sheetUngrouped, fields: [] }) })

    expect(await screen.findByText('This draft has no sheet fields yet.')).toBeVisible()
  })

  it('explains a schema version 1 draft', async () => {
    await openSheet({ [`GET ${base}/sheet`]: json(sheetV1) })

    expect(await screen.findByText(/Schema version 1 drafts have no sheet fields/)).toBeVisible()
  })

  it('says the GameSystem is not found on a 404 and shows any other failure', async () => {
    await openSheet({ [`GET ${base}/sheet`]: json({ error: 'No GameSystem' }, 404) })
    expect(
      await screen.findByText('This GameSystem does not exist, or it is not one of yours.'),
    ).toBeVisible()
  })

  it('shows a failure to load', async () => {
    await openSheet({ [`GET ${base}/sheet`]: () => new Response('Oops', { status: 500 }) })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the sheet failed with HTTP 500.',
    )
  })
})

describe('moving a field', () => {
  it('disables Move up on the first field of a group and Move down on the last', async () => {
    await openSheet()

    expect(await screen.findByRole('button', { name: 'Move up pow' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move down pow' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Move up dex' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Move down dex' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move up hp' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move down bonus' })).toBeDisabled()
  })

  it('sends the whole-list index of the neighbour in the same group and shows the answer', async () => {
    const moved = {
      ...sheet,
      version: 8,
      fields: [sheet.fields[2], sheet.fields[1], sheet.fields[0], sheet.fields[3]],
    }
    const { sent, handler } = recorder(Response.json(moved))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields/pow/move`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Move down pow' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 7, index: 2 }])
    })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move up pow' })).toBeEnabled()
    })
  })

  it('moves by list order when no groups are declared', async () => {
    const { sent, handler } = recorder(Response.json(sheetUngrouped))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json(sheetUngrouped),
      [`POST ${base}/sheet/fields/notes/move`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Move up notes' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 4, index: 1 }])
    })
  })

  it('shows a refusal, and on a conflict reloads the latest version', async () => {
    let reads = 0
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () => Response.json({ ...sheet, version: 7 + reads++ }),
      [`POST ${base}/sheet/fields/pow/move`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Move down pow' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })
    // The conflict itself refetched the sheet; the button reloads once more and clears the error.
    expect(reads).toBe(3)
  })

  it('keeps the conflict and says so when the reload fails', async () => {
    let reads = 0
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () =>
        reads++ === 0 ? Response.json(sheet) : new Response('Oops', { status: 500 }),
      [`POST ${base}/sheet/fields/pow/move`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Move down pow' }))
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(
      await screen.findByText(
        "Couldn't reload the latest version. Try again.",
        {},
        { timeout: 4000 },
      ),
    ).toBeVisible()
    expect(screen.getByText(/was changed by another request/)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeVisible()
  })

  it.each([
    [400, 'Send a JSON object with an integer "expectedVersion"'],
    [404, 'The sheet has no field "pow".'],
    [422, 'index: must be between 0 and 3'],
  ])('shows the message of a %i without offering a reload', async (status, error) => {
    const { user } = await openSheet({
      [`POST ${base}/sheet/fields/pow/move`]: json({ error }, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Move down pow' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(error)
    expect(
      screen.queryByRole('button', { name: 'Reload the latest version' }),
    ).not.toBeInTheDocument()
  })
})

describe('deleting a field', () => {
  it('asks first, then sends the version and drops the field', async () => {
    const deleted = { ...sheet, version: 8, fields: sheet.fields.filter((f) => f.key !== 'dex') }
    const sent: string[] = []
    const { user } = await openSheet({
      [`DELETE ${base}/sheet/fields/dex`]: (request) => {
        sent.push(new URL(request.url).search)
        return Response.json(deleted)
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete dex' }))
    expect(sent).toEqual([])
    await user.click(inDialog().getByRole('button', { name: 'Delete the field' }))

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=7'])
    })
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Edit dex' })).not.toBeInTheDocument()
    })
  })

  it('lists the places that read the field when the delete is refused', async () => {
    const { user } = await openSheet({
      [`DELETE ${base}/sheet/fields/pow`]: json(
        {
          error: 'The sheet field "pow" is used at sheet.fields[3].formula, checks[0].',
          paths: ['sheet.fields[3].formula', 'checks[0]'],
        },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      /^The sheet field "pow" is used at sheet\.fields\[3\]\.formula, checks\[0\]\.$/,
    )
  })

  it('offers the latest version on a conflict and closes once it is loaded', async () => {
    const { user } = await openSheet({ [`DELETE ${base}/sheet/fields/dex`]: json(changed, 409) })

    await user.click(await screen.findByRole('button', { name: 'Delete dex' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('keeps the conflict and the dialog open when the reload fails', async () => {
    let reads = 0
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () =>
        reads++ === 0 ? Response.json(sheet) : new Response('Oops', { status: 500 }),
      [`DELETE ${base}/sheet/fields/dex`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete dex' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    expect(
      await inDialog().findByText(
        "Couldn't reload the latest version. Try again.",
        {},
        { timeout: 4000 },
      ),
    ).toBeVisible()
    expect(inDialog().getByText(/was changed by another request/)).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Reload the latest version' })).toBeVisible()
  })

  it.each([
    [400, 'Send the draft version you read as an integer "expectedVersion" query parameter.'],
    [404, 'The sheet has no field "dex".'],
  ])('shows the message of a %i', async (status, error) => {
    const { user } = await openSheet({
      [`DELETE ${base}/sheet/fields/dex`]: json({ error }, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete dex' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
  })
})
