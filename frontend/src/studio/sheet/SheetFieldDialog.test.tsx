import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, sheet, sheetUngrouped, sheetWithConditions, validDraft } from '../fixtures'
import { replaceText, typeText } from '@/test/typeText'

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
    await typeText(user, inDialog().getByLabelText('Key'), 'luck')
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
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
    await chooseOption(user, 'Type', 'text', inDialog())
    expect(inDialog().queryByLabelText('Min')).not.toBeInTheDocument()
    expect(inDialog().getByLabelText('Multiline')).not.toBeChecked()
    expect(inDialog().getByLabelText('Max length')).toBeVisible()
    await chooseOption(user, 'Type', 'list', inDialog())
    expect(inDialog().getByLabelText('Item label')).toBeVisible()
    expect(inDialog().getByLabelText('Max items')).toBeVisible()
    await chooseOption(user, 'Type', 'track', inDialog())
    expect(inDialog().getByLabelText('Max')).toHaveValue('')
    await chooseOption(user, 'Type', 'derived', inDialog())
    expect(inDialog().getByLabelText('Formula')).toBeVisible()
  })

  it('sends a dice default of a number field as {dice}', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    await typeText(user, inDialog().getByLabelText('Key'), 'str')
    await typeText(user, inDialog().getByLabelText('Label'), 'Strength')
    await typeText(user, inDialog().getByLabelText('Dice default'), '3d6*5')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toMatchObject({ type: 'number', roll: { dice: '3d6*5' } })
    })
  })

  it('sets the max of a track fixed, by each Character or by a formula at creation', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    await typeText(user, inDialog().getByLabelText('Key'), 'hp')
    await typeText(user, inDialog().getByLabelText('Label'), 'Hit points')
    await chooseOption(user, 'Type', 'track', inDialog())
    expect(inDialog().getByLabelText('Max')).toBeVisible()
    await chooseOption(user, 'Max is', 'Each Character sets it', inDialog())
    expect(inDialog().queryByLabelText('Max')).not.toBeInTheDocument()
    expect(inDialog().queryByLabelText('Start max formula')).not.toBeInTheDocument()
    await chooseOption(user, 'Max is', 'Formula at creation', inDialog())
    await typeText(user, inDialog().getByLabelText('Start max formula'), '{pow} / 2')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toEqual({
        key: 'hp',
        label: 'Hit points',
        group: 'Stats',
        type: 'track',
        startMax: { formula: '{pow} / 2' },
      })
    })
  })

  it('offers number and derived fields only to a start max formula', async () => {
    const { user } = await openSheet()

    await addField(user)
    await chooseOption(user, 'Type', 'track', inDialog())
    await chooseOption(user, 'Max is', 'Formula at creation', inDialog())
    await user.click(inDialog().getByRole('combobox', { name: 'Insert reference' }))

    const options = (await screen.findAllByRole('option')).map((option) => option.textContent)
    expect(options).toEqual([
      'Insert reference…',
      'Power {pow}',
      'Dexterity {dex}',
      'Bonus {bonus}',
    ])
  })

  it('sends the properties of a derived field and a multiline text field', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await openSheet({ [`POST ${base}/sheet/fields`]: handler })

    await addField(user)
    await typeText(user, inDialog().getByLabelText('Key'), 'story')
    await typeText(user, inDialog().getByLabelText('Label'), 'Story')
    await typeText(user, inDialog().getByLabelText('Hint'), 'Background')
    await chooseOption(user, 'Type', 'text', inDialog())
    await user.click(inDialog().getByLabelText('Multiline'))
    await typeText(user, inDialog().getByLabelText('Max length'), '300')
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
    expect((await listOptions(user, span)).map((o) => o.label)).toEqual(['Not set', '1', '2'])
    await chooseOption(user, 'Span', '2', inDialog())
    await chooseOption(user, 'Group', 'Health', inDialog())
    expect(
      (await listOptions(user, inDialog().getByLabelText('Span'))).map((o) => o.label),
    ).toEqual(['Not set', '1'])
    expect(inDialog().getByLabelText('Span')).toHaveValue('')
    await chooseOption(user, 'Group', 'Stats', inDialog())
    await chooseOption(user, 'Span', '2', inDialog())
    await typeText(user, inDialog().getByLabelText('Key'), 'luck')
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
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
    await typeText(user, inDialog().getByLabelText('Key'), 'luck')
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
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
    await typeText(user, inDialog().getByLabelText('Min'), 'low')
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
    await replaceText(user, inDialog().getByLabelText('Max'), '20')
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
    await replaceText(user, inDialog().getByLabelText('Key'), 'health')
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
    await replaceText(user, inDialog().getByLabelText('Key'), 'agility')

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

  it('puts the refusal of a dice default and of a start max on their inputs', async () => {
    const { user } = await openSheet({
      [`POST ${base}/sheet/fields`]: json(
        { error: 'sheet.fields[4].roll.dice: invalid dice notation: unexpected "x"' },
        422,
      ),
    })

    await addField(user)
    await typeText(user, inDialog().getByLabelText('Key'), 'str')
    await typeText(user, inDialog().getByLabelText('Label'), 'Strength')
    await typeText(user, inDialog().getByLabelText('Dice default'), '3d6x5')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    expect(await inDialog().findByText('invalid dice notation: unexpected "x"')).toBeVisible()
    expect(inDialog().getByLabelText('Dice default')).toHaveAttribute('aria-invalid', 'true')
  })

  it('maps a new field to the position after the last one', async () => {
    const { user } = await openSheet({
      [`POST ${base}/sheet/fields`]: json(
        { error: 'sheet.fields[4].key: duplicate field key "luck"' },
        422,
      ),
    })

    await addField(user)
    await typeText(user, inDialog().getByLabelText('Key'), 'luck')
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
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
        {
          error: 'The sheet field "pow" is used at sheet.fields[3].formula.',
          paths: ['sheet.fields[3].formula'],
        },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit pow' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    // The message already says where: the paths are not listed a second time.
    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      /^The sheet field "pow" is used at sheet\.fields\[3\]\.formula\.$/,
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
    await replaceText(user, inDialog().getByLabelText('Key'), 'pow')
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
    await replaceText(user, preview.getByLabelText('Power'), '7')
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
    const options = (await listOptions(user, inDialog().getByLabelText('Insert reference'))).map(
      (option) => option.value,
    )

    expect(options).toEqual(['', 'pow', 'hp', 'hp.max', 'dex', 'tracker:heat'])
    await retype(user, '1 + ')
    await chooseOption(user, 'Insert reference', 'tracker:heat', inDialog())
    expect(inDialog().getByLabelText('Formula')).toHaveValue('1 + {tracker:heat}')
  })

  it('reads a field being renamed by its typed key, in the menu and the diagnostics alike', async () => {
    const { user } = await editBonus()
    const key = inDialog().getByLabelText('Key', { exact: true })
    const options = async () =>
      (await listOptions(user, inDialog().getByLabelText('Insert reference'))).map(
        (option) => option.value,
      )

    await replaceText(user, key, 'bonus2')
    expect(await options()).toEqual(['', 'pow', 'hp', 'hp.max', 'dex', 'tracker:heat'])
    await retype(user, '{bonus} + 1')
    expect(inDialog().getByText('{bonus}: unknown field "bonus"')).toBeVisible()
    await retype(user, '{bonus2} + 1')
    expect(inDialog().getByText(/depend on each other in a cycle: bonus2 -> bonus2/)).toBeVisible()

    // Taking another field's key: neither offers it as a field this one may read.
    await replaceText(user, key, 'pow')
    expect(await options()).toEqual(['', 'hp', 'hp.max', 'dex', 'tracker:heat'])
    await retype(user, '{pow} + 1')
    expect(inDialog().getByText(/depend on each other in a cycle: pow -> pow/)).toBeVisible()
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

describe('a conditions field', () => {
  async function newConditionsField(handlers: Record<string, FakeHandler> = {}) {
    const opened = await openSheet(handlers)
    await addField(opened.user)
    await typeText(opened.user, inDialog().getByLabelText('Key'), 'physical')
    await typeText(opened.user, inDialog().getByLabelText('Label'), 'Physical')
    await chooseOption(opened.user, 'Type', 'conditions', inDialog())
    return opened
  }

  it('starts with one option to fill in, and drops the inputs of the other types', async () => {
    const { user } = await newConditionsField()

    expect(inDialog().getByLabelText('Option 1 key')).toHaveValue('')
    expect(inDialog().getByLabelText('Option 1 label')).toHaveValue('')
    expect(inDialog().queryByLabelText('Min')).not.toBeInTheDocument()
    expect(inDialog().getByRole('button', { name: 'Remove option 1' })).toBeDisabled()
    await chooseOption(user, 'Type', 'number', inDialog())
    expect(inDialog().queryByLabelText('Option 1 key')).not.toBeInTheDocument()
  })

  it('sends the options in order, each key following its label until it is typed', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await newConditionsField({ [`POST ${base}/sheet/fields`]: handler })

    await typeText(user, inDialog().getByLabelText('Option 1 label'), 'Badly Hurt')
    expect(inDialog().getByLabelText('Option 1 key')).toHaveValue('badly-hurt')
    await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    await typeText(user, inDialog().getByLabelText('Option 2 label'), 'Tired')
    await replaceText(user, inDialog().getByLabelText('Option 2 key'), 'weary')
    await replaceText(user, inDialog().getByLabelText('Option 2 label'), 'Very tired')
    expect(inDialog().getByLabelText('Option 2 key')).toHaveValue('weary')
    await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    await typeText(user, inDialog().getByLabelText('Option 3 label'), 'Shaken')
    await user.click(inDialog().getByRole('button', { name: 'Move up option 3' }))
    await user.click(inDialog().getByRole('button', { name: 'Remove option 1' }))
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toEqual({
        key: 'physical',
        label: 'Physical',
        group: 'Stats',
        type: 'conditions',
        options: [
          { key: 'shaken', label: 'Shaken' },
          { key: 'weary', label: 'Very tired' },
        ],
      })
    })
  })

  it('flags a blank, repeated or too long option and sends nothing', async () => {
    const { sent, handler } = recorder(Response.json(sheet, { status: 201 }))
    const { user } = await newConditionsField({ [`POST ${base}/sheet/fields`]: handler })

    await user.click(inDialog().getByRole('button', { name: 'Create field' }))
    expect(await inDialog().findByText('Give the option a key.')).toBeVisible()
    expect(inDialog().getByText('Give the option a label.')).toBeVisible()

    await typeText(user, inDialog().getByLabelText('Option 1 label'), 'Hurt')
    await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    await typeText(user, inDialog().getByLabelText('Option 2 label'), 'Hurt')
    await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    await typeText(user, inDialog().getByLabelText('Option 3 label'), 'x'.repeat(61))
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    expect(
      await inDialog().findByText('The key "hurt" is used by an earlier option.'),
    ).toBeVisible()
    expect(inDialog().getByText('The label is at most 60 characters.')).toBeVisible()
    expect(sent).toEqual([])
  })

  it('stops adding at twenty options', async () => {
    const { user } = await newConditionsField()

    for (let at = 0; at < 19; at += 1) {
      await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    }

    expect(inDialog().getByLabelText('Option 20 key')).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Add option' })).toBeDisabled()
  })

  it('edits a stored conditions field in place and shows its options on the list', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheetWithConditions, version: 10 }))
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json(sheetWithConditions),
      [`PUT ${base}/sheet/fields/physical`]: handler,
    })

    expect(await screen.findByText('Options: Hurt, Tired')).toBeVisible()
    await user.click(await screen.findByRole('button', { name: 'Edit physical' }))
    expect(inDialog().getByLabelText('Option 1 key')).toHaveValue('hurt')
    expect(inDialog().getByLabelText('Option 2 label')).toHaveValue('Tired')
    await replaceText(user, inDialog().getByLabelText('Option 2 label'), 'Exhausted')
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    await waitFor(() => {
      expect(sent[0]?.field).toMatchObject({
        type: 'conditions',
        options: [
          { key: 'hurt', label: 'Hurt' },
          { key: 'tired', label: 'Exhausted' },
        ],
      })
    })
  })

  it('shows the publisher refusal of an option on its input', async () => {
    const { user } = await newConditionsField({
      [`POST ${base}/sheet/fields`]: json(
        { error: 'sheet.fields[4].options[1].key: duplicate option key "hurt"' },
        422,
      ),
    })
    await typeText(user, inDialog().getByLabelText('Option 1 label'), 'Hurt')
    await user.click(inDialog().getByRole('button', { name: 'Add option' }))
    await typeText(user, inDialog().getByLabelText('Option 2 label'), 'Harm')
    await user.click(inDialog().getByRole('button', { name: 'Create field' }))

    expect(await inDialog().findByText('duplicate option key "hurt"')).toBeVisible()
    expect(inDialog().getByLabelText('Option 2 key')).toHaveAttribute('aria-invalid', 'true')
    expect(inDialog().getByLabelText('Option 1 key')).not.toHaveAttribute('aria-invalid', 'true')
  })

  it('lists the effects that mark an option the save would remove', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json(sheetWithConditions),
      [`PUT ${base}/sheet/fields/physical`]: json(
        {
          error:
            'The option "hurt" of the sheet field "physical" is used at checks[0].push.effects[0].',
          paths: ['checks[0].push.effects[0]'],
        },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit physical' }))
    await user.click(inDialog().getByRole('button', { name: 'Remove option 1' }))
    await user.click(inDialog().getByRole('button', { name: 'Save field' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      /^The option "hurt" of the sheet field "physical" is used at checks\[0\]\.push\.effects\[0\]\.$/,
    )
    expect(inDialog().getByLabelText('Option 1 key')).toHaveValue('tired')
  })
})
