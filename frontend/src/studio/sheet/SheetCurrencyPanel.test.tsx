import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { replaceText, typeText } from '@/test/typeText'

import { draft, sheet, sheetWithEconomy, validDraft } from '../fixtures'
import { sheetKey } from './useGameSystemSheet'

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
    const queryClient = renderAppAt('/studio/game-systems/my-draft', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems': json([]),
      [`GET ${base}`]: json(draft),
      [`GET ${base}/validation`]: json(validDraft),
      [`GET ${base}/sheet`]: json(sheetWithEconomy),
    }).queryClient
    await userEvent.click(await screen.findByRole('link', { name: 'Sheet' }))
    expect(await screen.findByLabelText('Unit')).toHaveValue('eb')

    act(() => {
      queryClient.setQueryData(sheetKey('my-draft'), {
        ...sheetWithEconomy,
        currency: { field: 'eddies', unit: 'cr' },
      })
    })

    await waitFor(() => {
      expect(panel().getByLabelText('Unit')).toHaveValue('cr')
    })
  })
})

describe('when the stored currency changes while the panel is open', () => {
  const note =
    'The stored currency changed since you started editing. Reloading replaces your edit with it.'
  const theirs = { ...sheetWithEconomy, version: 14, currency: { field: 'eddies', unit: 'cr' } }

  /** Opens the sheet with a currency; `stored` is what the sheet endpoint answers from now on. */
  async function openEconomy(handlers: Record<string, FakeHandler> = {}) {
    const state = { stored: sheetWithEconomy as unknown, down: false }
    const user = userEvent.setup()
    const { queryClient } = renderAppAt('/studio/game-systems/my-draft', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems': json([]),
      [`GET ${base}`]: json(draft),
      [`GET ${base}/validation`]: json(validDraft),
      [`GET ${base}/sheet`]: () =>
        state.down
          ? Response.json({ error: 'Down.' }, { status: 500 })
          : Response.json(state.stored),
      ...handlers,
    })
    await user.click(await screen.findByRole('link', { name: 'Sheet' }))
    await screen.findByLabelText('Unit')
    /** Another request changed the stored sheet, and this page learns of it. */
    const store = (next: unknown) => {
      state.stored = next
      act(() => {
        queryClient.setQueryData(sheetKey('my-draft'), next)
      })
    }
    return { user, store, state }
  }

  it('follows it when nothing was edited, and the next save carries its version', async () => {
    const { sent, handler } = recorder(Response.json({ ...theirs, version: 15 }))
    const { user, store } = await openEconomy({ [`PUT ${url}`]: handler })

    store(theirs)

    await waitFor(() => {
      expect(panel().getByLabelText('Unit')).toHaveValue('cr')
    })
    expect(panel().queryByText(note)).not.toBeInTheDocument()
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 14, field: 'eddies', unit: 'cr' }])
    })
  })

  it('keeps an unsaved edit, says so and offers the reload', async () => {
    const { user, store } = await openEconomy()
    await typeText(user, panel().getByLabelText('Unit'), 'x')

    store(theirs)

    expect(await panel().findByText(note)).toBeVisible()
    expect(panel().getByLabelText('Unit')).toHaveValue('ebx')
    expect(panel().getByRole('button', reload)).toBeVisible()
  })

  it('adopts the stored currency without a note when the edit already equals it', async () => {
    const { user, store } = await openEconomy()
    await replaceText(user, panel().getByLabelText('Unit'), ' cr ')

    store(theirs)

    expect(await panel().findByText('Characters spend eddies in cr.')).toBeVisible()
    expect(panel().queryByText(note)).not.toBeInTheDocument()
    expect(panel().queryByRole('button', reload)).not.toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Overview' }))
    expect(screen.queryByRole('dialog', { name: 'Leave without saving?' })).not.toBeInTheDocument()
  })

  it('sends the old version on a save, and a reload replaces the edit and takes the new version', async () => {
    const sent: unknown[] = []
    let attempts = 0
    const { user, store } = await openEconomy({
      [`PUT ${url}`]: async (request) => {
        sent.push(await request.json())
        attempts += 1
        return attempts === 1
          ? Response.json(
              { error: 'Another request saved the draft.', code: 'modified_concurrently' },
              { status: 409 },
            )
          : Response.json({ ...theirs, version: 15 })
      },
    })
    await typeText(user, panel().getByLabelText('Unit'), 'x')
    store(theirs)
    await panel().findByText(note)

    await user.click(panel().getByRole('button', { name: 'Save currency' }))

    expect(await panel().findByText('Another request saved the draft.')).toBeVisible()
    expect(sent[0]).toEqual({ expectedVersion: 13, field: 'eddies', unit: 'ebx' })
    expect(panel().getByLabelText('Unit')).toHaveValue('ebx')

    await user.click(panel().getByRole('button', reload))

    await waitFor(() => {
      expect(panel().getByLabelText('Unit')).toHaveValue('cr')
    })
    expect(panel().queryByText(note)).not.toBeInTheDocument()
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    await waitFor(() => {
      expect(sent).toHaveLength(2)
    })
    expect(sent[1]).toEqual({ expectedVersion: 14, field: 'eddies', unit: 'cr' })
  })

  it('keeps the edit when the reload fails', async () => {
    const { user, store, state } = await openEconomy()
    await typeText(user, panel().getByLabelText('Unit'), 'x')
    store(theirs)
    await panel().findByText(note)

    state.down = true
    await user.click(panel().getByRole('button', reload))

    expect(await screen.findByText(/Couldn't reload the latest version/)).toBeVisible()
    expect(panel().getByLabelText('Unit')).toHaveValue('ebx')
    expect(panel().getByText(note)).toBeVisible()
  })

  it('lets a save send the new version when only the sheet version moved', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheetWithEconomy, version: 21 }))
    const { user, store } = await openEconomy({ [`PUT ${url}`]: handler })
    await typeText(user, panel().getByLabelText('Unit'), 'x')

    store({ ...sheetWithEconomy, version: 20 })
    await user.click(panel().getByRole('button', { name: 'Save currency' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 20, field: 'eddies', unit: 'ebx' }])
    })
    expect(panel().queryByText(note)).not.toBeInTheDocument()
  })

  it('takes the answer of a save as what the inputs started from', async () => {
    const saved = { ...sheetWithEconomy, version: 14, currency: { field: 'eddies', unit: 'cr' } }
    const { handler } = recorder(Response.json(saved))
    const { user } = await openEconomy({ [`PUT ${url}`]: handler })
    await replaceText(user, panel().getByLabelText('Unit'), 'cr')

    await user.click(panel().getByRole('button', { name: 'Save currency' }))

    expect(await screen.findByText('Characters spend eddies in cr.')).toBeVisible()
    await user.click(screen.getByRole('link', { name: 'Overview' }))
    expect(screen.queryByRole('dialog', { name: 'Leave without saving?' })).not.toBeInTheDocument()
  })

  it('never says the stored currency changed after the person own save, and the next save sends its version', async () => {
    const saved = { ...sheetWithEconomy, version: 14, currency: { field: 'eddies', unit: 'cr' } }
    const { sent, handler } = recorder(Response.json(saved))
    const { user } = await openEconomy({ [`PUT ${url}`]: handler })
    // Every state the document passes through counts: the note may flash for one render only.
    let flashed = false
    const watch = new MutationObserver(() => {
      flashed ||= document.body.textContent.includes('stored currency changed')
    })
    watch.observe(document.body, { childList: true, subtree: true, characterData: true })
    await replaceText(user, panel().getByLabelText('Unit'), 'cr')

    await user.click(panel().getByRole('button', { name: 'Save currency' }))

    expect(await screen.findByText('Characters spend eddies in cr.')).toBeVisible()
    watch.disconnect()
    expect(flashed).toBe(false)
    expect(panel().queryByRole('alert')).not.toBeInTheDocument()
    expect(panel().queryByRole('button', reload)).not.toBeInTheDocument()
    await replaceText(user, panel().getByLabelText('Unit'), 'cc')
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    await waitFor(() => {
      expect(sent).toHaveLength(2)
    })
    expect(sent[1]).toMatchObject({ expectedVersion: 14, unit: 'cc' })
  })

  it('takes the answer of a removal as what the inputs started from', async () => {
    const { handler } = recorder(
      Response.json({ ...sheetWithEconomy, version: 14, currency: null }),
    )
    const { user } = await openEconomy({ [`DELETE ${url}`]: handler })

    await user.click(panel().getByRole('button', { name: 'Remove currency' }))

    expect(await screen.findByText(/Characters have no money/)).toBeVisible()
    expect(panel().getByLabelText('Unit')).toHaveValue('')
    await user.click(screen.getByRole('link', { name: 'Overview' }))
    expect(screen.queryByRole('dialog', { name: 'Leave without saving?' })).not.toBeInTheDocument()
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

describe('leaving with an unsaved currency', () => {
  const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })
  const otherTab = () => screen.getByRole('link', { name: 'Overview' })

  it('asks before a change of tab, and Stay keeps the draft', async () => {
    const user = await openSheet()

    await screen.findByLabelText('Currency field')
    await chooseOption(user, 'Currency field', 'dex')
    await user.click(otherTab())
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Stay' }))

    expect(dialog()).not.toBeInTheDocument()
    expect(panel().getByLabelText('Currency field')).toHaveTextContent('Dexterity')
  })

  it('asks before leaving the page, and goes when the person chooses to', async () => {
    const user = await openSheet()

    await typeText(user, await screen.findByLabelText('Unit'), 'eb')
    await user.click(screen.getByRole('link', { name: /Back to Studio/ }))
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))

    expect(await screen.findByRole('heading', { name: /Studio/ })).toBeVisible()
  })

  it('lets an untouched panel go', async () => {
    const user = await openSheet()

    await screen.findByLabelText('Currency field')
    await user.click(otherTab())

    expect(await screen.findByRole('form', { name: 'Metadata' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('reads a unit that differs only by its spaces as the stored one', async () => {
    const user = await openSheet({}, sheetWithEconomy)

    await typeText(user, await screen.findByLabelText('Unit'), ' ')
    await user.click(otherTab())

    expect(await screen.findByRole('form', { name: 'Metadata' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('reads a saved currency as clean, though the unit was typed with spaces', async () => {
    const saved = { ...sheet, version: 8, currency: { field: 'dex', unit: 'eb' } }
    const user = await openSheet({ [`PUT ${url}`]: json(saved) })

    await screen.findByLabelText('Currency field')
    await chooseOption(user, 'Currency field', 'dex')
    await typeText(user, panel().getByLabelText('Unit'), ' eb ')
    await user.click(panel().getByRole('button', { name: 'Save currency' }))
    expect(await screen.findByText('Characters spend dex in eb.')).toBeVisible()
    await user.click(otherTab())

    expect(await screen.findByRole('form', { name: 'Metadata' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('reads a removed currency as clean', async () => {
    const user = await openSheet(
      { [`DELETE ${url}`]: json({ ...sheetWithEconomy, version: 14, currency: null }) },
      sheetWithEconomy,
    )

    await user.click(await screen.findByRole('button', { name: 'Remove currency' }))
    expect(await screen.findByText(/Characters have no money/)).toBeVisible()
    await user.click(otherTab())

    expect(await screen.findByRole('form', { name: 'Metadata' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })
})
