import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { typeText } from '@/test/typeText'

import { draft, sheet, sheetWithLegacyBudget, sheetWithPointBudgets, validDraft } from '../fixtures'

// Radix checkboxes render a hidden input whose size it measures.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

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

const withBudgets = { [`GET ${base}/sheet`]: json(sheetWithPointBudgets) }
const inDialog = () => within(screen.getByRole('dialog'))
const panel = () => screen.getByRole('region', { name: 'Point budgets' })

/** What a write sent: its JSON body, or the query of a DELETE. */
function recorder(response: Response | (() => Response)) {
  const sent: unknown[] = []
  const handler: FakeHandler = async (request) => {
    sent.push(
      request.method === 'DELETE'
        ? new URL(request.url).search
        : ((await request.json()) as Record<string, unknown>),
    )
    return typeof response === 'function' ? response() : response
  }
  return { sent, handler }
}

const reload = { name: 'Reload the latest version' }
const entry = (key: string) => `${base}/sheet/point-budgets/${key}`
const added = { key: 'luck', label: 'Luck', total: 30, fields: ['pow'], min: null, max: null }

describe('a sheet without a point budget', () => {
  it('says so and adds a budget over the number fields only', async () => {
    const saved = { ...sheet, version: 8, pointBudgets: [added] }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openSheet({ [`PUT ${entry('luck')}`]: handler })

    expect(await screen.findByText('Characters are created without a point budget.')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Add point budget' }))

    expect(inDialog().getAllByRole('checkbox')).toHaveLength(2)
    expect(inDialog().getByText('Power')).toBeVisible()
    expect(inDialog().queryByText('Hit points')).not.toBeInTheDocument()
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
    expect(inDialog().getByLabelText('Key')).toHaveValue('luck')
    await typeText(user, inDialog().getByLabelText('Total'), '30')
    await user.click(inDialog().getByRole('checkbox', { name: /Power/ }))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 7, label: 'Luck', total: 30, fields: ['pow'] }])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(within(panel()).getByText('Luck: 30')).toBeVisible()
    expect(within(panel()).getByText('Spent over Power')).toBeVisible()
  })

  it('stops following the label once the key is typed, and sends the bounds', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, pointBudgets: [added] }))
    const { user } = await openSheet({ [`PUT ${entry('fortune')}`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add point budget' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'fortune')
    await typeText(user, inDialog().getByLabelText('Label'), 'Luck points')
    expect(inDialog().getByLabelText('Key')).toHaveValue('fortune')
    await typeText(user, inDialog().getByLabelText('Total'), '20')
    await user.click(inDialog().getByRole('checkbox', { name: /Dexterity/ }))
    await user.click(inDialog().getByRole('checkbox', { name: /Power/ }))
    await typeText(user, inDialog().getByLabelText('Creation min'), '5')
    await typeText(user, inDialog().getByLabelText('Creation max'), '15')
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          expectedVersion: 7,
          label: 'Luck points',
          total: 20,
          fields: ['pow', 'dex'],
          min: 5,
          max: 15,
        },
      ])
    })
  })

  it('needs a label, a key, a total and whole numbers before it asks the server', async () => {
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({ [`PUT ${entry('luck')}`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add point budget' }))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    expect(await inDialog().findByText('Enter the label.')).toBeVisible()
    expect(inDialog().getByText(/Use 1 to 64 lower case letters/)).toBeVisible()
    expect(inDialog().getByText('Enter the total.')).toBeVisible()

    await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
    await typeText(user, inDialog().getByLabelText('Key'), 'Bad Key')
    await typeText(user, inDialog().getByLabelText('Total'), '20')
    await typeText(user, inDialog().getByLabelText('Creation min'), '1.5')
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    expect(await inDialog().findByText('Enter a whole number.')).toBeVisible()
    expect(inDialog().getByText(/Use 1 to 64 lower case letters/)).toBeVisible()
    expect(sent).toEqual([])
  })

  it('cannot save when the sheet has no number field', async () => {
    const { user } = await openSheet({
      [`GET ${base}/sheet`]: json({
        ...sheet,
        fields: sheet.fields.filter((field) => field.type !== 'number'),
      }),
    })

    await user.click(await screen.findByRole('button', { name: 'Add point budget' }))

    expect(inDialog().getByText(/no number field to spend points on/)).toBeVisible()
    expect(inDialog().getByRole('button', { name: 'Save point budget' })).toBeDisabled()
  })
})

describe('a sheet with several point budgets', () => {
  it('lists each with its label, key, total, fields and creation range', async () => {
    await openSheet(withBudgets)

    const attributes = within(await screen.findByRole('listitem', { name: 'Attribute points' }))
    expect(attributes.getByText('Attribute points: 12')).toBeVisible()
    expect(attributes.getByText('attributes')).toBeVisible()
    expect(attributes.getByText('Spent over Power')).toBeVisible()
    expect(attributes.getByText('Creation range: 1 to 5')).toBeVisible()
    const skills = within(screen.getByRole('listitem', { name: 'Skill points' }))
    expect(skills.getByText('Spent over Dexterity')).toBeVisible()
    expect(skills.getByText('Creation range: at most 3')).toBeVisible()
  })

  it('says a lower bound alone as at least', async () => {
    await openSheet({
      [`GET ${base}/sheet`]: json({
        ...sheetWithPointBudgets,
        pointBudgets: [{ ...sheetWithPointBudgets.pointBudgets[0], min: 2, max: null }],
      }),
    })

    expect(await screen.findByText('Creation range: at least 2')).toBeVisible()
  })

  it('edits one budget by its key, which stays read-only', async () => {
    const saved = {
      ...sheetWithPointBudgets,
      version: 12,
      pointBudgets: [
        { ...sheetWithPointBudgets.pointBudgets[0], total: 14 },
        sheetWithPointBudgets.pointBudgets[1],
      ],
    }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openSheet({ ...withBudgets, [`PUT ${entry('attributes')}`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit Attribute points' }))
    expect(inDialog().queryByLabelText('Key')).not.toBeInTheDocument()
    expect(inDialog().getByText('attributes')).toBeVisible()
    expect(inDialog().getByLabelText('Label')).toHaveValue('Attribute points')
    expect(inDialog().getByLabelText('Total')).toHaveValue('12')
    expect(inDialog().getByRole('checkbox', { name: /Power/ })).toBeChecked()
    expect(inDialog().getByLabelText('Creation min')).toHaveValue('1')
    await user.clear(inDialog().getByLabelText('Total'))
    await typeText(user, inDialog().getByLabelText('Total'), '14')
    await user.clear(inDialog().getByLabelText('Creation min'))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { expectedVersion: 11, label: 'Attribute points', total: 14, fields: ['pow'], max: 5 },
      ])
    })
    expect(await within(panel()).findByText('Attribute points: 14')).toBeVisible()
  })

  it('removes one budget with the version it read', async () => {
    const saved = { ...sheetWithPointBudgets, version: 12, pointBudgets: [] }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openSheet({ ...withBudgets, [`DELETE ${entry('skills')}`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Remove Skill points' }))

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=11'])
    })
    expect(await screen.findByText('Characters are created without a point budget.')).toBeVisible()
  })

  it('shows why a removal failed and offers the reload on a 409', async () => {
    const { user } = await openSheet({
      ...withBudgets,
      [`DELETE ${entry('skills')}`]: json({ error: 'Another request saved the draft.' }, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Remove Skill points' }))

    expect(await screen.findByText('Another request saved the draft.')).toBeVisible()
    expect(screen.getByRole('button', reload)).toBeVisible()
  })

  it('shows the message of a 404 on removal without a reload', async () => {
    const { user } = await openSheet({
      ...withBudgets,
      [`DELETE ${entry('skills')}`]: json(
        { error: 'The draft has no point budget "skills".' },
        404,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Remove Skill points' }))

    expect(await screen.findByText('The draft has no point budget "skills".')).toBeVisible()
    expect(screen.queryByRole('button', reload)).not.toBeInTheDocument()
  })
})

describe('a legacy budget with no label', () => {
  const legacy = { [`GET ${base}/sheet`]: json(sheetWithLegacyBudget) }

  it('reads as the budget points and needs a label to be saved', async () => {
    const { sent, handler } = recorder(
      Response.json({
        ...sheetWithLegacyBudget,
        version: 13,
        pointBudgets: [
          { ...sheetWithLegacyBudget.pointBudgets[0], label: 'Characteristic points' },
        ],
      }),
    )
    const { user } = await openSheet({ ...legacy, [`PUT ${entry('points')}`]: handler })

    expect(await screen.findByText('Points: 30')).toBeVisible()
    expect(screen.getByText('points')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Edit Points' }))
    expect(inDialog().getByLabelText('Label')).toHaveValue('')
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    expect(await inDialog().findByText('Enter the label.')).toBeVisible()
    expect(sent).toEqual([])

    await typeText(user, inDialog().getByLabelText('Label'), 'Characteristic points')
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { expectedVersion: 12, label: 'Characteristic points', total: 30, fields: ['pow'] },
      ])
    })
    expect(await within(panel()).findByText('Characteristic points: 30')).toBeVisible()
  })
})

describe('a refused save', () => {
  async function refuse(error: string, status = 422, edit = 'Edit Skill points') {
    const { user } = await openSheet({
      ...withBudgets,
      [`PUT ${entry('skills')}`]: json({ error }, status),
      [`PUT ${entry('luck')}`]: json({ error }, status),
    })
    await user.click(await screen.findByRole('button', { name: edit }))
    if (edit === 'Add point budget') {
      await typeText(user, inDialog().getByLabelText('Label'), 'Luck')
      await typeText(user, inDialog().getByLabelText('Total'), '3')
      await user.click(inDialog().getByRole('checkbox', { name: /Power/ }))
    }
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    return user
  }

  it.each([
    ['Total', 'sheet.pointBudgets[1].total: must be at least 1', 'must be at least 1'],
    ['Label', 'sheet.pointBudgets[1].label: must not be blank', 'must not be blank'],
    ['Creation min', 'sheet.pointBudgets[1].min: must be a whole number', 'must be a whole number'],
    ['Creation max', 'sheet.pointBudgets[1].max: must be at least min', 'must be at least min'],
  ])(
    'puts the message of the %s input of an edited budget next to it',
    async (label, error, message) => {
      await refuse(error)

      expect(await inDialog().findByText(message)).toBeVisible()
      expect(inDialog().getByLabelText(label)).toBeInvalid()
      expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    },
  )

  it('reads the position of an added budget as the last of the list', async () => {
    await refuse('sheet.pointBudgets[2].key: duplicate budget key "luck"', 422, 'Add point budget')

    expect(await inDialog().findByText('duplicate budget key "luck"')).toBeVisible()
    expect(inDialog().getByLabelText('Key')).toBeInvalid()
  })

  it('puts a refusal of the fields with the list', async () => {
    await refuse('sheet.pointBudgets[1].fields[0]: field "pow" is already in budget "attributes"')

    expect(
      await inDialog().findByText('field "pow" is already in budget "attributes"'),
    ).toBeVisible()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it.each([
    ['the budget as a whole', 'sheet.pointBudgets[1]: unknown field "bonus"'],
    ['another budget', 'sheet.pointBudgets[0].total: must be at least 1'],
    ['the list as a whole', 'sheet.pointBudgets: at most 10 point budgets'],
  ])('keeps %s in the form, path and all', async (_name, error) => {
    await refuse(error)

    expect(await inDialog().findByRole('alert')).toHaveTextContent(error)
  })

  it('shows a 400 as it is, with no reload', async () => {
    await refuse('Send the point budget as JSON.', 400)

    expect(await inDialog().findByRole('alert')).toHaveTextContent('Send the point budget as JSON.')
    expect(inDialog().queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('offers the reload on a 409 and keeps what was typed', async () => {
    const user = await refuse('Another request saved the draft.', 409)

    expect(await inDialog().findByText('Another request saved the draft.')).toBeVisible()
    await user.click(inDialog().getByRole('button', reload))

    await waitFor(() => {
      expect(inDialog().queryByText('Another request saved the draft.')).not.toBeInTheDocument()
    })
    expect(inDialog().getByLabelText('Total')).toHaveValue('8')
  })
})
