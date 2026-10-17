import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, sheet, sheetUngrouped, validDraft } from '../fixtures'

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
  await user.click(await screen.findByRole('radio', { name: 'Sheet' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))

function recorder(response: Response | (() => Response)) {
  const sent: { expectedVersion: number; field: Record<string, unknown> }[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as (typeof sent)[number])
    return typeof response === 'function' ? response() : response
  }
  return { sent, handler }
}

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

async function addField(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Add field' }))
}

describe('adding a field', () => {
  it('sends only what was filled, with the first declared group, and closes on success', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 8 }, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    expect(inDialog().getByRole('combobox', { name: 'Group' })).toHaveValue('Stats')
    await user.type(inDialog().getByLabelText('Key'), 'luck')
    await user.type(inDialog().getByLabelText('Label'), 'Luck')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          expectedVersion: 7,
          field: { key: 'luck', label: 'Luck', group: 'Stats', type: 'number' },
        },
      ])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('has the inputs of the chosen type and starts them again when the type changes', async () => {
    const { user } = await openSheet()
    await addField(user)

    expect(inDialog().getByLabelText('Min')).toBeVisible()
    await user.type(inDialog().getByLabelText('Max'), '9')
    await user.selectOptions(inDialog().getByLabelText('Type'), 'text')
    expect(inDialog().queryByLabelText('Min')).not.toBeInTheDocument()
    expect(inDialog().getByLabelText('Multiline')).not.toBeChecked()
    expect(inDialog().getByLabelText('Max length')).toBeVisible()
    await user.selectOptions(inDialog().getByLabelText('Type'), 'list')
    expect(inDialog().getByLabelText('Item label')).toBeVisible()
    expect(inDialog().getByLabelText('Max items')).toBeVisible()
    await user.selectOptions(inDialog().getByLabelText('Type'), 'track')
    expect(inDialog().getByLabelText('Max')).toHaveValue('')
    await user.selectOptions(inDialog().getByLabelText('Type'), 'derived')
    expect(inDialog().getByLabelText('Formula')).toBeVisible()
  })

  it('sends the properties of a derived field and a multiline text field', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    await user.type(inDialog().getByLabelText('Key'), 'story')
    await user.type(inDialog().getByLabelText('Label'), 'Story')
    await user.type(inDialog().getByLabelText('Hint'), 'Background')
    await user.selectOptions(inDialog().getByLabelText('Type'), 'text')
    await user.click(inDialog().getByLabelText('Multiline'))
    await user.type(inDialog().getByLabelText('Max length'), '300')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toEqual({
        key: 'story',
        label: 'Story',
        hint: 'Background',
        group: 'Stats',
        type: 'text',
        multiline: true,
        maxLength: 300,
      })
    })
  })

  it('offers a span up to the columns of the chosen group, and drops one the new group cannot hold', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    const span = inDialog().getByLabelText('Span')
    expect(
      within(span)
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Not set', '1', '2'])
    await user.selectOptions(span, '2')
    await user.selectOptions(inDialog().getByLabelText('Group'), 'Health')
    expect(
      within(inDialog().getByLabelText('Span'))
        .getAllByRole('option')
        .map((o) => o.textContent),
    ).toEqual(['Not set', '1'])
    expect(inDialog().getByLabelText('Span')).toHaveValue('')
    await user.selectOptions(inDialog().getByLabelText('Group'), 'Stats')
    await user.selectOptions(inDialog().getByLabelText('Span'), '2')
    await user.type(inDialog().getByLabelText('Key'), 'luck')
    await user.type(inDialog().getByLabelText('Label'), 'Luck')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toMatchObject({ group: 'Stats', span: 2 })
    })
  })

  it('takes a free-text optional group and no span when the sheet declares no groups', async () => {
    const { sent, handler } = recorder(Response.json(sheetUngrouped, { status: 201 }))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json(sheetUngrouped),
      [`POST ${base}/sheet/fields`]: handler,
    })

    await addField(user)
    expect(inDialog().queryByLabelText('Span')).not.toBeInTheDocument()
    expect(inDialog().getByLabelText('Group')).toHaveValue('')
    await user.type(inDialog().getByLabelText('Key'), 'luck')
    await user.type(inDialog().getByLabelText('Label'), 'Luck')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { expectedVersion: 4, field: { key: 'luck', label: 'Luck', type: 'number' } },
      ])
    })
  })

  it('flags blank inputs and sends nothing', async () => {
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    await user.type(inDialog().getByLabelText('Min'), 'low')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    expect(inDialog().getByText('Give the field a key.')).toBeVisible()
    expect(inDialog().getByText('Give the field a label.')).toBeVisible()
    expect(inDialog().getByText('Must be a whole number.')).toBeVisible()
    expect(sent).toEqual([])
  })
})

describe('editing a field', () => {
  it('starts from the stored field and replaces it in place', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 8 }))
    const { user } = await openSheet({ [`PUT ${base}/sheet/fields/pow`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    expect(inDialog().getByLabelText('Label')).toHaveValue('Power')
    expect(inDialog().getByLabelText('Max')).toHaveValue('18')
    expect(inDialog().getByLabelText('Span')).toHaveValue('2')
    await user.clear(inDialog().getByLabelText('Max'))
    await user.type(inDialog().getByLabelText('Max'), '20')
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          expectedVersion: 7,
          field: {
            key: 'pow',
            label: 'Power',
            group: 'Stats',
            span: 2,
            type: 'number',
            min: 0,
            max: 20,
          },
        },
      ])
    })
  })

  it('renames through the old key and warns when the key was published', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 8 }))
    const { user } = await openSheet({ [`PUT ${base}/sheet/fields/hp`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit hp' }))
    const hint = 'Characters on campaigns that upgrade later lose that value.'
    expect(inDialog().queryByText(hint)).not.toBeInTheDocument()
    await user.clear(inDialog().getByLabelText('Key'))
    await user.type(inDialog().getByLabelText('Key'), 'health')
    expect(inDialog().getByText(hint)).toBeVisible()
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toMatchObject({
        key: 'health',
        label: 'Hit points',
        hint: 'Zero is out',
      })
    })
  })

  it('does not warn about renaming a field that was never published', async () => {
    const { user } = await openSheet()

    await user.click(await screen.findByRole('button', { name: 'Edit dex' }))
    await user.clear(inDialog().getByLabelText('Key'))
    await user.type(inDialog().getByLabelText('Key'), 'agility')

    expect(inDialog().queryByText(/lose that value/)).not.toBeInTheDocument()
  })
})

describe('a refused save', () => {
  it('puts a message with a path on the input it names and keeps the dialog open', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/fields/pow`]: json(
        { error: 'sheet.fields[0].max: must be at least min (0), -1 given' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    expect(await inDialog().findByText('must be at least min (0), -1 given')).toBeVisible()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    expect(inDialog().getByLabelText('Max')).toHaveAttribute('aria-invalid', 'true')
  })

  it('maps a new field to the position after the last one', async () => {
    const { user } = await openSheet({
      [`POST ${base}/sheet/fields`]: json(
        { error: 'sheet.fields[4].key: duplicate field key "luck"' },
        422,
      ),
    })

    await addField(user)
    await user.type(inDialog().getByLabelText('Key'), 'luck')
    await user.type(inDialog().getByLabelText('Label'), 'Luck')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    expect(await inDialog().findByText('duplicate field key "luck"')).toBeVisible()
  })

  it('shows what belongs to no input with the form, path and all', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/fields/pow`]: json(
        { error: 'sceneTypes[0].oracles[0]: unknown oracle "ghost"' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'sceneTypes[0].oracles[0]: unknown oracle "ghost"',
    )
  })

  it('lists the places that read a field being renamed', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/fields/pow`]: json(
        { error: 'The field "pow" is in use.', paths: ['sheet.fields[3].formula'] },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'The field "pow" is in use. Used by sheet.fields[3].formula.',
    )
  })

  it.each([
    [400, 'Send a JSON object with an integer "expectedVersion" and a "field" object.'],
    [404, 'The sheet has no field "pow".'],
  ])('shows the message of a %i without offering a reload', async (status, error) => {
    const { user } = await openSheet({ [`PUT ${base}/sheet/fields/pow`]: json({ error }, status) })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
    expect(
      inDialog().queryByRole('button', { name: 'Reload the latest version' }),
    ).not.toBeInTheDocument()
  })

  it('on a conflict reloads the stored field and saves against the new version', async () => {
    let reads = 0
    const newer = {
      ...sheet,
      version: 9,
      fields: sheet.fields.map((f) => (f.key === 'pow' ? { ...f, label: 'Strength' } : f)),
    }
    const responses = [Response.json(changed, { status: 409 }), Response.json(newer)]
    const { sent, handler } = recorder(() => responses.shift() ?? Response.json(newer))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () => (reads++ === 0 ? Response.json(sheet) : Response.json(newer)),
      [`PUT ${base}/sheet/fields/pow`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(inDialog().getByLabelText('Label')).toHaveValue('Strength')
    })
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))
    await waitFor(() => {
      expect(sent.map((request) => request.expectedVersion)).toEqual([7, 9])
    })
  })

  it('keeps what was typed when the conflict is another field taking the key', async () => {
    const { user } = await openSheet({
      [`PUT ${base}/sheet/fields/dex`]: json(
        { error: 'The sheet already has a field "pow".' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit dex' }))
    await user.clear(inDialog().getByLabelText('Key'))
    await user.type(inDialog().getByLabelText('Key'), 'pow')
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    })
    expect(inDialog().getByLabelText('Key')).toHaveValue('pow')
  })

  it('closes with the list when the conflict refetch finds the field gone', async () => {
    let reads = 0
    const gone = { ...sheet, version: 9, fields: sheet.fields.filter((f) => f.key !== 'dex') }
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () => (reads++ === 0 ? Response.json(sheet) : Response.json(gone)),
      [`PUT ${base}/sheet/fields/dex`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit dex' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(screen.queryByRole('button', { name: 'Edit dex' })).not.toBeInTheDocument()
  })

  it('keeps the draft and says why when the reload finds a shape it cannot read', async () => {
    let reads = 0
    const odd = {
      ...sheet,
      version: 9,
      fields: sheet.fields.map((f) => (f.key === 'dex' ? { ...f, min: 'low' } : f)),
    }
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () => (reads++ === 0 ? Response.json(sheet) : Response.json(odd)),
      [`PUT ${base}/sheet/fields/dex`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit dex' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    expect(await inDialog().findByText(/changed to a shape this editor cannot read/)).toBeVisible()
    expect(inDialog().getByLabelText('Label')).toHaveValue('Dexterity')
  })

  it('keeps the conflict state when the reload of the sheet fails', async () => {
    let reads = 0
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: () =>
        reads++ === 0 ? Response.json(sheet) : new Response('Oops', { status: 500 }),
      [`PUT ${base}/sheet/fields/pow`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    expect(
      await inDialog().findByText(
        "Couldn't reload the latest version. Try again.",
        {},
        { timeout: 4000 },
      ),
    ).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Reload the latest version' })).toBeVisible()
    expect(inDialog().getByText(/was changed by another request/)).toBeVisible()
  })
})

describe('the formula of a derived field', () => {
  async function editBonus(handlers: Record<string, FakeHandler> = {}) {
    const opened = await openSheet(handlers)
    await opened.user.click(await screen.findByRole('button', { name: 'Edit bonus' }))
    return opened
  }

  async function retype(user: ReturnType<typeof userEvent.setup>, text: string) {
    const input = inDialog().getByLabelText('Formula')
    await user.clear(input)
    await user.click(input)
    await user.paste(text)
  }

  it('shows the value over the sample values', async () => {
    await editBonus()

    expect(inDialog().getByText('Value with the sample values: 1')).toBeVisible()
  })

  it('computes over the sample values typed in the preview', async () => {
    const { user } = await openSheet()
    const preview = within(await screen.findByRole('region', { name: 'Preview' }))
    await user.clear(preview.getByLabelText('Power'))
    await user.type(preview.getByLabelText('Power'), '7')
    await user.click(screen.getByRole('button', { name: 'Edit bonus' }))

    expect(inDialog().getByText('Value with the sample values: 8')).toBeVisible()
  })

  it('marks a syntax error where it is, after leading whitespace', async () => {
    const { user } = await editBonus()

    await retype(user, '  1 + * 2')

    expect(inDialog().getByText('*', { selector: 'mark' })).toBeVisible()
    expect(inDialog().queryByText(/Value with the sample values/)).not.toBeInTheDocument()
  })

  it('refuses an unknown reference and a cycle, and marks the reference', async () => {
    const { user } = await editBonus()

    await retype(user, '{pow} + {ghost}')
    expect(inDialog().getByText('{ghost}: unknown field "ghost"')).toBeVisible()
    expect(inDialog().getByText('{', { selector: 'mark' })).toBeVisible()

    await retype(user, '{bonus} + 1')
    expect(inDialog().getByText(/depend on each other in a cycle: bonus -> bonus/)).toBeVisible()
  })

  it('inserts a reference to a field or a Tracker, not to the field itself or a text field', async () => {
    const { user } = await editBonus()
    const options = within(inDialog().getByLabelText('Insert reference'))
      .getAllByRole('option')
      .map((option) => option.getAttribute('value'))

    expect(options).toEqual(['', 'pow', 'hp', 'hp.max', 'dex', 'tracker:heat'])
    await retype(user, '1 + ')
    await user.selectOptions(inDialog().getByLabelText('Insert reference'), 'tracker:heat')
    expect(inDialog().getByLabelText('Formula')).toHaveValue('1 + {tracker:heat}')
  })

  it('does not block saving: the server answers, and its refusal shows on the input', async () => {
    const { sent, handler } = recorder(
      Response.json({ error: 'sheet.fields[3].formula: unknown field "ghost"' }, { status: 422 }),
    )
    const { user } = await editBonus({ [`PUT ${base}/sheet/fields/bonus`]: handler })

    await retype(user, '{pow} + {ghost}')
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toMatchObject({ key: 'bonus', formula: '{pow} + {ghost}' })
    })
    expect(await inDialog().findByText('unknown field "ghost"')).toBeVisible()
    expect(inDialog().getByLabelText('Formula')).toHaveAttribute('aria-invalid', 'true')
  })
})
