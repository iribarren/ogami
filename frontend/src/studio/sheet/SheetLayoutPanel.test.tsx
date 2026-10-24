import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, sheet, sheetUngrouped, validDraft } from '../fixtures'
import { typeText } from '@/test/typeText'

/** Every group card draws one text field, so the fields drawn count the cards that rendered. */
const drawn = vi.hoisted(() => ({ fields: 0 }))

vi.mock('../narrative/TextField', async (importOriginal) => {
  const { TextField: Real } = await importOriginal<typeof import('../narrative/TextField')>()
  return {
    TextField: (props: React.ComponentProps<typeof Real>) => {
      drawn.fields += 1
      return <Real {...props} />
    },
  }
})

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
const group = (position: number) =>
  inDialog().getByRole('group', { name: `Group ${String(position)}` })

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
const reloadFailed = "Couldn't reload the latest version. Try again."
const reload = { name: 'Reload the latest version' }

/** The first read answers `first`, every later one `then` (a sheet, or a function making a response). */
function reads(first: unknown, then: unknown): FakeHandler {
  let count = 0
  return () => {
    if (count++ === 0) {
      return Response.json(first)
    }
    return typeof then === 'function' ? (then as () => Response)() : Response.json(then)
  }
}

const declared = { ...sheetUngrouped, version: 5, groups: [{ name: 'Stats', columns: 1, span: 1 }] }

async function editLayout(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Edit layout' }))
}

async function saveLayout(user: ReturnType<typeof userEvent.setup>) {
  await user.click(inDialog().getByRole('button', { name: 'Save layout' }))
}

describe('a sheet without a layout', () => {
  const legacy = { [`GET ${base}/sheet`]: json(sheetUngrouped) }

  it('explains what declaring does and declares from the fields groups', async () => {
    const { sent, handler } = recorder(Response.json(declared))
    const { user, fakeApi } = await openSheet({
      ...legacy,
      [`POST ${base}/sheet/layout/declare`]: handler,
    })

    expect(await screen.findByText(/This sheet has no layout/)).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Declare layout' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 4 }])
    })
    expect(await screen.findByRole('button', { name: 'Edit layout' })).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Declare layout' })).not.toBeInTheDocument()
    expect(screen.getByText(/Layout: 1 column, 1 group/)).toBeVisible()
    await waitFor(() => {
      expect(fakeApi.requests.filter((request) => request === `GET ${base}`)).toHaveLength(2)
    })
  })

  it('cannot declare without fields', async () => {
    await openSheet({ [`GET ${base}/sheet`]: json({ ...sheetUngrouped, fields: [] }) })

    expect(await screen.findByRole('button', { name: 'Declare layout' })).toBeDisabled()
    expect(screen.getByText('Add a field first.')).toBeVisible()
  })

  it.each([
    [400, 'Send the draft version as JSON.'],
    [404, 'No GameSystem "my-draft".'],
    [422, 'sheet.groups: already declared'],
  ])('shows the message of a %i without offering a reload', async (status, error) => {
    const { user } = await openSheet({
      ...legacy,
      [`POST ${base}/sheet/layout/declare`]: json({ error }, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Declare layout' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(error)
    expect(screen.queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('on a conflict loads the latest sheet, which may already have a layout', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheetUngrouped, declared),
      [`POST ${base}/sheet/layout/declare`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Declare layout' }))

    expect(await screen.findByRole('button', { name: 'Edit layout' })).toBeVisible()
  })

  it('keeps the conflict and says so when the reload fails', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheetUngrouped, () => new Response('Oops', { status: 500 })),
      [`POST ${base}/sheet/layout/declare`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Declare layout' }))
    await user.click(await screen.findByRole('button', reload))

    expect(await screen.findByText(reloadFailed, {}, { timeout: 4000 })).toBeVisible()
    expect(screen.getByText(/was changed by another request/)).toBeVisible()
    expect(screen.getByRole('button', reload)).toBeVisible()
  })
})

describe('editing the layout', () => {
  it('starts from the stored layout and sends it back with a rename as previousName', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 8 }))
    const { user, fakeApi } = await openSheet({ [`PUT ${base}/sheet/layout`]: handler })

    expect(await screen.findByText(/Layout: 2 columns, 3 groups/)).toBeVisible()
    await editLayout(user)
    expect(inDialog().getByLabelText('Sheet columns')).toHaveValue('2')
    expect(within(group(1)).getByLabelText('Name')).toHaveValue('Stats')
    expect(within(group(1)).getByLabelText('Columns')).toHaveValue('2')
    await user.clear(within(group(1)).getByLabelText('Name'))
    await typeText(user, within(group(1)).getByLabelText('Name'), ' Attributes ')
    await saveLayout(user)

    await waitFor(() => {
      expect(sent).toEqual([
        {
          expectedVersion: 7,
          columns: 2,
          groups: [
            { name: 'Attributes', previousName: 'Stats', columns: 2, span: 1 },
            { name: 'Health', columns: 1, span: 1 },
            { name: 'Notes', columns: 1, span: 1 },
          ],
        },
      ])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    await waitFor(() => {
      expect(fakeApi.requests.filter((request) => request === `GET ${base}`)).toHaveLength(2)
    })
  })

  it('renders only the edited group card when one group changes', async () => {
    const { user } = await openSheet()
    await editLayout(user)
    drawn.fields = 0

    fireEvent.change(within(group(2)).getByLabelText('Name'), { target: { value: 'Vitals' } })

    expect(within(group(2)).getByLabelText('Name')).toHaveValue('Vitals')
    expect(drawn.fields).toBe(1)
  })

  it('keeps both of two quick edits on different groups', async () => {
    const { user } = await openSheet()
    await editLayout(user)

    fireEvent.change(within(group(1)).getByLabelText('Name'), { target: { value: 'Core' } })
    fireEvent.change(within(group(3)).getByLabelText('Name'), { target: { value: 'Journal' } })
    fireEvent.change(within(group(1)).getByLabelText('Name'), { target: { value: 'Core stats' } })

    expect(within(group(1)).getByLabelText('Name')).toHaveValue('Core stats')
    expect(within(group(2)).getByLabelText('Name')).toHaveValue('Health')
    expect(within(group(3)).getByLabelText('Name')).toHaveValue('Journal')
  })

  it('adds, reorders and deletes groups and sends the groups in that order', async () => {
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({ [`PUT ${base}/sheet/layout`]: handler })

    await editLayout(user)
    await user.click(inDialog().getByRole('button', { name: 'Add group' }))
    await typeText(user, within(group(4)).getByLabelText('Name'), 'Gear')
    await chooseOption(user, 'Columns', '3', within(group(4)))
    await user.click(within(group(4)).getByRole('button', { name: 'Move up' }))
    await user.click(within(group(4)).getByRole('button', { name: 'Delete group' }))
    await user.click(within(group(1)).getByRole('button', { name: 'Move down' }))
    await saveLayout(user)

    await waitFor(() => {
      expect(sent[0]?.groups).toEqual([
        { name: 'Health', columns: 1, span: 1 },
        { name: 'Stats', columns: 2, span: 1 },
        { name: 'Gear', columns: 3, span: 1 },
      ])
    })
  })

  it('disables the edges of the order, and deleting a group that holds fields', async () => {
    const { user } = await openSheet()

    await editLayout(user)

    expect(within(group(1)).getByRole('button', { name: 'Move up' })).toBeDisabled()
    expect(within(group(3)).getByRole('button', { name: 'Move down' })).toBeDisabled()
    expect(within(group(1)).getByRole('button', { name: 'Delete group' })).toBeDisabled()
    expect(within(group(1)).getByText('Holds 2 fields: move or delete them first.')).toBeVisible()
    expect(within(group(2)).getByText('Holds 2 fields: move or delete them first.')).toBeVisible()
    expect(within(group(3)).getByRole('button', { name: 'Delete group' })).toBeEnabled()
  })

  it('limits the span to the sheet columns and brings a span back when the columns shrink', async () => {
    const wide = {
      ...sheet,
      groups: [{ name: 'Stats', columns: 2, span: 2 }, ...sheet.groups.slice(1)],
    }
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json(wide),
      [`PUT ${base}/sheet/layout`]: handler,
    })

    await editLayout(user)
    expect(
      (await listOptions(user, within(group(1)).getByLabelText('Span'))).map(
        (option) => option.label,
      ),
    ).toEqual(['1', '2'])
    await chooseOption(user, 'Sheet columns', '1', inDialog())
    expect(within(group(1)).getByLabelText('Span')).toHaveValue('1')
    expect(await listOptions(user, within(group(1)).getByLabelText('Span'))).toHaveLength(1)
    await saveLayout(user)

    await waitFor(() => {
      expect(sent[0]).toMatchObject({ columns: 1 })
    })
    expect(sent[0]?.groups).toMatchObject([{ span: 1 }, { span: 1 }, { span: 1 }])
  })

  it('flags a blank or repeated name and sends nothing', async () => {
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({ [`PUT ${base}/sheet/layout`]: handler })

    await editLayout(user)
    await user.clear(within(group(1)).getByLabelText('Name'))
    await user.clear(within(group(3)).getByLabelText('Name'))
    await typeText(user, within(group(3)).getByLabelText('Name'), 'Health')
    await saveLayout(user)

    expect(within(group(1)).getByText('Give the group a name.')).toBeVisible()
    expect(within(group(3)).getByText('Another group has this name.')).toBeVisible()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    expect(sent).toEqual([])
  })

  it('needs at least one group', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json({ ...sheet, fields: [], version: 7 }),
    })

    await editLayout(user)
    for (const position of [3, 2, 1]) {
      await user.click(within(group(position)).getByRole('button', { name: 'Delete group' }))
    }
    await saveLayout(user)

    expect(await inDialog().findByRole('alert')).toHaveTextContent('Declare at least one group.')
  })

  it('does not offer editing a layout the form cannot show', async () => {
    await openSheet({
      [`GET ${base}/sheet`]: json({ ...sheet, groups: [{ name: 'A', columns: 9, span: 1 }] }),
    })

    expect(await screen.findByRole('button', { name: 'Edit layout' })).toBeDisabled()
    expect(screen.getByText(/holds a value this editor cannot show/)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Remove layout' })).toBeEnabled()
  })
})

describe('the preview beside the layout dialog', () => {
  it('shows the unsaved draft while the dialog is open and the saved layout once it closes', async () => {
    const { user } = await openSheet()
    await editLayout(user)

    await typeText(user, within(group(1)).getByLabelText('Name'), ' and more')

    expect(screen.getByText('Stats and more', { selector: 'legend' })).toBeInTheDocument()

    await user.keyboard('{Escape}')

    expect(screen.queryByText('Stats and more', { selector: 'legend' })).not.toBeInTheDocument()
    expect(screen.getByText('Stats', { selector: 'legend' })).toBeInTheDocument()
  })
})

describe('a refused layout save', () => {
  it('puts a message with a path on the input it names and keeps the dialog open', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/layout`]: json(
        { error: 'sheet.groups[1].name: duplicate group name "Stats"' },
        422,
      ),
    })

    await editLayout(user)
    await saveLayout(user)

    expect(await within(group(2)).findByText('duplicate group name "Stats"')).toBeVisible()
    expect(within(group(2)).getByLabelText('Name')).toHaveAttribute('aria-invalid', 'true')
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it('drops a message once the draft changed, so it never lands on another group', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/layout`]: json(
        { error: 'sheet.groups[1].name: duplicate group name "Stats"' },
        422,
      ),
    })

    await editLayout(user)
    await saveLayout(user)
    expect(await within(group(2)).findByText('duplicate group name "Stats"')).toBeVisible()

    await user.click(within(group(2)).getByRole('button', { name: 'Move up' }))

    expect(inDialog().queryByText('duplicate group name "Stats"')).not.toBeInTheDocument()
    expect(within(group(2)).getByLabelText('Name')).not.toHaveAttribute('aria-invalid', 'true')

    await user.clear(within(group(1)).getByLabelText('Name'))
    await saveLayout(user)

    expect(await within(group(1)).findByText('Give the group a name.')).toBeVisible()
    expect(inDialog().queryByText('duplicate group name "Stats"')).not.toBeInTheDocument()
  })

  it('maps the sheet columns and the group columns and span', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/layout`]: json({ error: 'sheet.columns: must be at most 2' }, 422),
    })

    await editLayout(user)
    await saveLayout(user)

    expect(await inDialog().findByText('must be at most 2')).toBeVisible()
    expect(inDialog().getByLabelText('Sheet columns')).toHaveAttribute('aria-invalid', 'true')
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it.each([
    ['sheet.groups[0].span: must be at most the columns of the sheet (1), 2 given', 1, 'Span'],
    ['sheet.groups[0].columns: must be at most 4', 1, 'Columns'],
  ])('maps %s to the input', async (error, position, label) => {
    const { user } = await openSheet({ [`PUT ${base}/sheet/layout`]: json({ error }, 422) })

    await editLayout(user)
    await saveLayout(user)

    await waitFor(() => {
      expect(within(group(position)).getByLabelText(label)).toHaveAttribute('aria-invalid', 'true')
    })
  })

  it.each([
    'groups: cannot drop the group "Stats", it holds 2 fields, move or delete its fields first',
    'sheet.fields[0].group: unknown group "Ghost", declare it in sheet.groups',
    'sceneTypes[0].oracles[0]: unknown oracle "ghost"',
  ])('shows %s with the form, path and all', async (error) => {
    const { user } = await openSheet({ [`PUT ${base}/sheet/layout`]: json({ error }, 422) })

    await editLayout(user)
    await saveLayout(user)

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
  })

  it.each([
    [400, 'Send the layout as JSON.'],
    [404, 'No GameSystem "my-draft".'],
  ])('shows the message of a %i without offering a reload', async (status, error) => {
    const { user } = await openSheet({ [`PUT ${base}/sheet/layout`]: json({ error }, status) })

    await editLayout(user)
    await saveLayout(user)

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
    expect(inDialog().queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('on a conflict reloads the stored layout and saves against the new version', async () => {
    const newer = {
      ...sheet,
      version: 9,
      groups: [{ name: 'Core', columns: 2, span: 1 }, ...sheet.groups.slice(1)],
    }
    const responses = [Response.json(changed, { status: 409 }), Response.json(newer)]
    const { sent, handler } = recorder(() => responses.shift() ?? Response.json(newer))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheet, newer),
      [`PUT ${base}/sheet/layout`]: handler,
    })

    await editLayout(user)
    await saveLayout(user)
    await user.click(await inDialog().findByRole('button', reload))

    await waitFor(() => {
      expect(within(group(1)).getByLabelText('Name')).toHaveValue('Core')
    })
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    await saveLayout(user)
    await waitFor(() => {
      expect(sent.map((request) => request.expectedVersion)).toEqual([7, 9])
    })
  })

  it('keeps the conflict and the dialog when the reload fails', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheet, () => new Response('Oops', { status: 500 })),
      [`PUT ${base}/sheet/layout`]: json(changed, 409),
    })

    await editLayout(user)
    await saveLayout(user)
    await user.click(await inDialog().findByRole('button', reload))

    expect(await inDialog().findByText(reloadFailed, {}, { timeout: 4000 })).toBeVisible()
    expect(inDialog().getByText(/was changed by another request/)).toBeVisible()
    expect(inDialog().getByRole('button', reload)).toBeVisible()
  })

  it('keeps the draft and says why when the reload finds a layout it cannot show', async () => {
    const odd = { ...sheet, version: 9, groups: [{ name: 'A', columns: 9, span: 1 }] }
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheet, odd),
      [`PUT ${base}/sheet/layout`]: json(changed, 409),
    })

    await editLayout(user)
    await saveLayout(user)
    await user.click(await inDialog().findByRole('button', reload))

    expect(await inDialog().findByText(/changed to a value this editor cannot show/)).toBeVisible()
    expect(within(group(1)).getByLabelText('Name')).toHaveValue('Stats')
  })

  it('closes with the panel when the reload finds the layout removed', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: reads(sheet, sheetUngrouped),
      [`PUT ${base}/sheet/layout`]: json(changed, 409),
    })

    await editLayout(user)
    await saveLayout(user)

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(await screen.findByRole('button', { name: 'Declare layout' })).toBeVisible()
  })
})

describe('removing the layout', () => {
  const open = async (handlers: Record<string, FakeHandler>) => {
    const rendered = await openSheet(handlers)
    await rendered.user.click(await screen.findByRole('button', { name: 'Remove layout' }))
    return rendered
  }
  const confirm = (user: ReturnType<typeof userEvent.setup>) =>
    user.click(inDialog().getByRole('button', { name: 'Remove the layout' }))

  it('asks first, says what is dropped, then sends the version', async () => {
    const searches: string[] = []
    const { user, fakeApi } = await open({
      [`DELETE ${base}/sheet/layout`]: (request) => {
        searches.push(new URL(request.url).search)
        return Response.json({ ...sheetUngrouped, version: 8 })
      },
    })

    expect(inDialog().getByText(/drops the sheet's columns, the declared groups/)).toBeVisible()
    expect(inDialog().getByText(/Fields keep their group and order/)).toBeVisible()
    expect(searches).toEqual([])
    await confirm(user)

    await waitFor(() => {
      expect(searches).toEqual(['?expectedVersion=7'])
    })
    expect(await screen.findByRole('button', { name: 'Declare layout' })).toBeVisible()
    await waitFor(() => {
      expect(fakeApi.requests.filter((request) => request === `GET ${base}`)).toHaveLength(2)
    })
  })

  it.each([
    [400, 'Send the draft version you read as an integer "expectedVersion" query parameter.'],
    [404, 'No GameSystem "my-draft".'],
    [422, 'sheet.groups: no declared layout, there is nothing to remove'],
  ])('shows the message of a %i', async (status, error) => {
    const { user } = await open({ [`DELETE ${base}/sheet/layout`]: json({ error }, status) })

    await confirm(user)

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
    expect(inDialog().queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('offers the latest version on a conflict and closes once it is loaded', async () => {
    const { user } = await open({ [`DELETE ${base}/sheet/layout`]: json(changed, 409) })

    await confirm(user)
    await user.click(await inDialog().findByRole('button', reload))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('keeps the conflict and the dialog open when the reload fails', async () => {
    const { user } = await open({
      [`GET ${base}/sheet`]: reads(sheet, () => new Response('Oops', { status: 500 })),
      [`DELETE ${base}/sheet/layout`]: json(changed, 409),
    })

    await confirm(user)
    await user.click(await inDialog().findByRole('button', reload))

    expect(await inDialog().findByText(reloadFailed, {}, { timeout: 4000 })).toBeVisible()
    expect(inDialog().getByText(/was changed by another request/)).toBeVisible()
    expect(inDialog().getByRole('button', reload)).toBeVisible()
  })
})
