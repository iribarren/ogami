import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { typeText } from '@/test/typeText'

import { draft, sheet, sheetWithPointBudget, validDraft } from '../fixtures'

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

const withBudget = { [`GET ${base}/sheet`]: json(sheetWithPointBudget) }
const inDialog = () => within(screen.getByRole('dialog'))
const panel = () => screen.getByRole('region', { name: 'Point budget' })

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
const budget = { label: null, total: 30, fields: ['pow'], min: null, max: null }

describe('a sheet without a point budget', () => {
  it('says so and adds a budget over the number fields only', async () => {
    const saved = { ...sheet, version: 8, pointBudget: budget }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openSheet({ [`PUT ${base}/sheet/point-budget`]: handler })

    expect(await screen.findByText('Characters are created without a point budget.')).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Add point budget' }))

    expect(
      inDialog()
        .getAllByRole('checkbox')
        .map((box) => box.id),
    ).toHaveLength(2)
    expect(inDialog().getByText('Power')).toBeVisible()
    expect(inDialog().getByText('Dexterity')).toBeVisible()
    expect(inDialog().queryByText('Hit points')).not.toBeInTheDocument()
    await typeText(user, inDialog().getByLabelText('Total'), '30')
    await user.click(inDialog().getByRole('checkbox', { name: /Power/ }))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 7, total: 30, fields: ['pow'] }])
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(within(panel()).getByText('Points: 30')).toBeVisible()
    expect(within(panel()).getByText('Spent over Power')).toBeVisible()
  })

  it('sends the label and the creation bounds when they are set', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, pointBudget: budget }))
    const { user } = await openSheet({ [`PUT ${base}/sheet/point-budget`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add point budget' }))
    await typeText(user, inDialog().getByLabelText('Label'), 'Characteristic points')
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
          label: 'Characteristic points',
          total: 20,
          fields: ['pow', 'dex'],
          min: 5,
          max: 15,
        },
      ])
    })
  })

  it('needs a total and whole numbers before it asks the server', async () => {
    const { sent, handler } = recorder(Response.json(sheet))
    const { user } = await openSheet({ [`PUT ${base}/sheet/point-budget`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add point budget' }))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    expect(await inDialog().findByText('Enter the total.')).toBeVisible()

    await typeText(user, inDialog().getByLabelText('Total'), '20')
    await typeText(user, inDialog().getByLabelText('Creation min'), '1.5')
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    expect(await inDialog().findByText('Enter a whole number.')).toBeVisible()
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

  it('reads a legacy sheet without a budget the same way', async () => {
    const legacy: Record<string, unknown> = { ...sheet }
    delete legacy.pointBudget
    await openSheet({ [`GET ${base}/sheet`]: json({ ...legacy, pointBudget: null }) })

    expect(await screen.findByText('Characters are created without a point budget.')).toBeVisible()
  })
})

describe('a sheet with a point budget', () => {
  it('shows its label, total, fields and creation range', async () => {
    await openSheet(withBudget)

    expect(
      await within(await screen.findByRole('region', { name: 'Point budget' })).findByText(
        'Characteristic points: 20',
      ),
    ).toBeVisible()
    expect(within(panel()).getByText('Spent over Power, Dexterity')).toBeVisible()
    expect(within(panel()).getByText('Creation range: 5 to 15')).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Add point budget' })).not.toBeInTheDocument()
  })

  it.each([
    [{ min: 5, max: null }, 'Creation range: at least 5'],
    [{ min: null, max: 15 }, 'Creation range: at most 15'],
  ])('says %o as a one-sided range', async (bounds, text) => {
    await openSheet({
      [`GET ${base}/sheet`]: json({
        ...sheetWithPointBudget,
        pointBudget: { ...sheetWithPointBudget.pointBudget, ...bounds },
      }),
    })

    expect(await screen.findByText(text)).toBeVisible()
  })

  it('edits the whole budget from what it holds', async () => {
    const saved = { ...sheetWithPointBudget, version: 9, pointBudget: budget }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openSheet({ ...withBudget, [`PUT ${base}/sheet/point-budget`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    expect(inDialog().getByLabelText('Label')).toHaveValue('Characteristic points')
    expect(inDialog().getByLabelText('Total')).toHaveValue('20')
    expect(inDialog().getByRole('checkbox', { name: /Dexterity/ })).toBeChecked()
    expect(inDialog().getByLabelText('Creation min')).toHaveValue('5')
    await user.click(inDialog().getByRole('checkbox', { name: /Dexterity/ }))
    await user.clear(inDialog().getByLabelText('Label'))
    await user.clear(inDialog().getByLabelText('Creation min'))
    await user.clear(inDialog().getByLabelText('Creation max'))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 8, total: 20, fields: ['pow'] }])
    })
    expect(await within(panel()).findByText('Points: 30')).toBeVisible()
  })

  it('removes it with the version it read', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 9 }))
    const { user } = await openSheet({
      ...withBudget,
      [`DELETE ${base}/sheet/point-budget`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Remove' }))

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=8'])
    })
    expect(await screen.findByText('Characters are created without a point budget.')).toBeVisible()
  })

  it('shows why a removal failed and offers the reload on a 409', async () => {
    const { user } = await openSheet({
      ...withBudget,
      [`DELETE ${base}/sheet/point-budget`]: json(
        { error: 'Another request saved the draft.' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Remove' }))

    expect(await screen.findByText('Another request saved the draft.')).toBeVisible()
    expect(screen.getByRole('button', reload)).toBeVisible()
  })

  it('shows the message of a 404 on removal without a reload', async () => {
    const { user } = await openSheet({
      ...withBudget,
      [`DELETE ${base}/sheet/point-budget`]: json({ error: 'No GameSystem "my-draft".' }, 404),
    })

    await user.click(await screen.findByRole('button', { name: 'Remove' }))

    expect(await screen.findByText('No GameSystem "my-draft".')).toBeVisible()
    expect(screen.queryByRole('button', reload)).not.toBeInTheDocument()
  })
})

describe('a refused save', () => {
  async function refuse(error: string, status = 422) {
    const { user } = await openSheet({
      ...withBudget,
      [`PUT ${base}/sheet/point-budget`]: json({ error }, status),
    })
    await user.click(await screen.findByRole('button', { name: 'Edit' }))
    await user.click(inDialog().getByRole('button', { name: 'Save point budget' }))
    return user
  }

  it.each([
    ['Total', 'sheet.pointBudget.total: must be at least 1', 'must be at least 1'],
    ['Label', 'sheet.pointBudget.label: must not be blank', 'must not be blank'],
    ['Creation min', 'sheet.pointBudget.min: must be a whole number', 'must be a whole number'],
    ['Creation max', 'sheet.pointBudget.max: must be at least min (5)', 'must be at least min (5)'],
  ])('puts the message of the %s input next to it', async (label, error, message) => {
    await refuse(error)

    expect(await inDialog().findByText(message)).toBeVisible()
    expect(inDialog().getByLabelText(label)).toBeInvalid()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it('puts a refusal of the fields with the list', async () => {
    await refuse('sheet.pointBudget.fields[1]: duplicate field "pow"')

    expect(await inDialog().findByText('duplicate field "pow"')).toBeVisible()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it('keeps the whole budget message in the form', async () => {
    await refuse('sheet.pointBudget: unknown field "bonus"')

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'sheet.pointBudget: unknown field "bonus"',
    )
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
    expect(inDialog().getByLabelText('Total')).toHaveValue('20')
  })
})
