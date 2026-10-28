import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { typeText } from '@/test/typeText'

import { draft, sheet, sheetWithEconomy, validDraft } from '../fixtures'
import { SheetCurrencyPanel } from './SheetCurrencyPanel'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openSheet(handlers: Record<string, FakeHandler> = {}, source: unknown = sheet) {
  const user = userEvent.setup()
  renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/sheet`]: json(source),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Sheet' }))
  return user
}

const panel = () => within(screen.getByRole('region', { name: 'Currency' }))
const reload = { name: 'Reload the latest version' }
const url = `${base}/sheet/currency`

/** What a write sent: its JSON body, or the query of a DELETE. */
function recorder(response: Response) {
  const sent: unknown[] = []
  const handler: FakeHandler = async (request) => {
    sent.push(
      request.method === 'DELETE'
        ? new URL(request.url).search
        : ((await request.json()) as Record<string, unknown>),
    )
    return response.clone()
  }
  return { sent, handler }
}

describe('a sheet without a currency', () => {
  it('says so and offers the number fields only', async () => {
    const user = await openSheet()

    expect(await screen.findByText(/Characters have no money/)).toBeVisible()
    const options = await listOptions(user, panel().getByLabelText('Currency field'))
    expect(options.map((option) => option.label)).toEqual([
      'Not set',
      'Power (pow)',
      'Dexterity (dex)',
    ])
    expect(panel().queryByRole('button', { name: 'Remove currency' })).not.toBeInTheDocument()
  })

  it('saves the field and the unit with the version it read', async () => {
    const saved = { ...sheet, version: 8, currency: { field: 'dex', unit: 'eb' } }
    const { sent, handler } = recorder(Response.json(saved))
    const user = await openSheet({ [`PUT ${url}`]: handler })

    await screen.findByLabelText('Currency field')
    await chooseOption(user, 'Currency field', 'dex')
    await typeText(user, panel().getByLabelText('Unit'), ' eb ')
    await user.click(panel().getByRole('button', { name: 'Save currency' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 7, field: 'dex', unit: 'eb' }])
    })
    expect(await screen.findByText('Characters spend dex in eb.')).toBeVisible()
  })

  it('sends no unit when it is empty, and needs a field and a short unit first', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheet, version: 8 }))
    const user = await openSheet({ [`PUT ${url}`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Save currency' }))
    expect(await panel().findByRole('alert')).toHaveTextContent('Pick the number field')
    await chooseOption(user, 'Currency field', 'pow')
    await typeText(user, panel().getByLabelText('Unit'), 'credits-long')
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    expect(await panel().findByRole('alert')).toHaveTextContent('at most 10 characters')
    expect(sent).toEqual([])

    await user.clear(panel().getByLabelText('Unit'))
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 7, field: 'pow', unit: null }])
    })
  })
})

describe('a sheet with a currency', () => {
  const economy = () => openSheet({}, sheetWithEconomy)

  it('shows it and fills the form from it', async () => {
    await economy()

    expect(await screen.findByText('Characters spend eddies in eb.')).toBeVisible()
    expect(panel().getByLabelText('Currency field')).toHaveValue('eddies')
    expect(panel().getByLabelText('Unit')).toHaveValue('eb')
  })

  it('removes it with the version it read', async () => {
    const { sent, handler } = recorder(
      Response.json({ ...sheetWithEconomy, version: 14, currency: null }),
    )
    const user = await openSheet({ [`DELETE ${url}`]: handler }, sheetWithEconomy)

    await user.click(await screen.findByRole('button', { name: 'Remove currency' }))

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=13'])
    })
    expect(await screen.findByText(/Characters have no money/)).toBeVisible()
  })

  it('shows the message of a 404 on removal without a reload', async () => {
    const user = await openSheet(
      { [`DELETE ${url}`]: json({ error: 'The draft has no currency.' }, 404) },
      sheetWithEconomy,
    )

    await user.click(await screen.findByRole('button', { name: 'Remove currency' }))

    expect(await panel().findByRole('alert')).toHaveTextContent('The draft has no currency.')
    expect(screen.queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('offers the reload when another request saved the draft', async () => {
    const user = await openSheet(
      {
        [`DELETE ${url}`]: json(
          { error: 'Another request saved the draft.', code: 'modified_concurrently' },
          409,
        ),
      },
      sheetWithEconomy,
    )

    await user.click(await screen.findByRole('button', { name: 'Remove currency' }))

    expect(await panel().findByText('Another request saved the draft.')).toBeVisible()
    expect(panel().getByRole('button', reload)).toBeVisible()
  })

  it('shows a refusal of the removal', async () => {
    const user = await openSheet(
      { [`DELETE ${url}`]: json({ error: 'sheet.currency: a shop still needs it' }, 422) },
      sheetWithEconomy,
    )

    await user.click(await screen.findByRole('button', { name: 'Remove currency' }))

    expect(await panel().findByRole('alert')).toHaveTextContent('a shop still needs it')
  })

  it('shows the latest removal error over an earlier validation message', async () => {
    const user = await openSheet(
      { [`DELETE ${url}`]: json({ error: 'The draft has no currency.' }, 404) },
      sheetWithEconomy,
    )

    await user.clear(panel().getByLabelText('Unit'))
    await typeText(user, panel().getByLabelText('Unit'), 'twelve-chars')
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    expect(await panel().findByRole('alert')).toHaveTextContent('at most 10 characters')
    await user.click(panel().getByRole('button', { name: 'Remove currency' }))

    expect(await panel().findByRole('alert')).toHaveTextContent('The draft has no currency.')
    expect(panel().getAllByRole('alert')).toHaveLength(1)
  })

  it('follows the stored currency when it changes from outside', async () => {
    const { api } = createFakeApiClient({})
    const ui = (source: typeof sheetWithEconomy) => (
      <QueryClientProvider client={createQueryClient()}>
        <ApiClientProvider client={api}>
          <SheetCurrencyPanel gameSystemKey="my-draft" sheet={source} />
        </ApiClientProvider>
      </QueryClientProvider>
    )
    const { rerender } = render(ui(sheetWithEconomy))
    expect(panel().getByLabelText('Unit')).toHaveValue('eb')

    rerender(ui({ ...sheetWithEconomy, currency: { field: 'eddies', unit: 'cr' } }))

    await waitFor(() => {
      expect(panel().getByLabelText('Unit')).toHaveValue('cr')
    })
  })
})

describe('a refused save', () => {
  async function refuse(error: unknown, status: number) {
    const user = await openSheet({ [`PUT ${url}`]: json(error, status) }, sheetWithEconomy)
    await user.click(await screen.findByRole('button', { name: 'Save currency' }))
    return user
  }

  it('puts the message of the field and of the unit next to their inputs', async () => {
    await refuse({ error: 'sheet.currency.field: "eddies" is a derived field' }, 422)

    expect(await panel().findByText('"eddies" is a derived field')).toBeVisible()
    expect(panel().getByLabelText('Currency field')).toBeInvalid()
    expect(panel().queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows any other refusal with its path', async () => {
    await refuse({ error: 'sheet.currency: the currency must be an object' }, 422)

    expect(await panel().findByRole('alert')).toHaveTextContent('sheet.currency: the currency')
  })

  it('says a library pack and schema version 1 refuse a currency', async () => {
    await refuse({ error: 'A library pack has no currency.', code: 'library_pack' }, 409)

    expect(await panel().findByText('A library pack has no currency.')).toBeVisible()
  })

  it('offers the reload on a concurrent save and keeps what was typed', async () => {
    const user = await refuse(
      { error: 'Another request saved the draft.', code: 'modified_concurrently' },
      409,
    )

    expect(await panel().findByText('Another request saved the draft.')).toBeVisible()
    await user.click(panel().getByRole('button', reload))

    await waitFor(() => {
      expect(panel().queryByText('Another request saved the draft.')).not.toBeInTheDocument()
    })
    expect(panel().getByLabelText('Unit')).toHaveValue('eb')
  })
})
