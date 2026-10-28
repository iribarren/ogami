import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { typeText } from '@/test/typeText'

import { draft, sheet, sheetV1, sheetWithEconomy, validDraft } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openGear(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/sheet`]: json(sheetWithEconomy),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Gear' }))
  return user
}

const inDialog = () => within(screen.getByRole('dialog'))
const reload = { name: 'Reload the latest version' }

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

describe('the gear catalog', () => {
  it('lists each entry with its key, category, cost in the currency unit and slots', async () => {
    await openGear()

    const pistol = (await screen.findByText('Used pistol')).closest('li')
    expect(pistol).toHaveTextContent('used-pistol')
    expect(pistol).toHaveTextContent('Weapons')
    expect(pistol).toHaveTextContent('Cost 50 eb · 1 slot')
    expect(screen.getByText('Medkit').closest('li')).toHaveTextContent('2 slots')
  })

  it('reads a legacy draft with no gear and no currency as an empty catalog', async () => {
    await openGear({ [`GET ${base}/sheet`]: json(sheet) })

    expect(await screen.findByText('This draft has no gear yet.')).toBeVisible()
    expect(screen.getByText(/The sheet has no currency yet/)).toBeVisible()
  })

  it('says a schema version 1 draft has no gear', async () => {
    await openGear({ [`GET ${base}/sheet`]: json(sheetV1) })

    expect(await screen.findByText(/Schema version 1 drafts have no gear/)).toBeVisible()
  })

  it('adds an entry: the key follows the name and only what is set is sent', async () => {
    const saved = {
      ...sheetWithEconomy,
      version: 14,
      gear: [
        ...sheetWithEconomy.gear,
        {
          key: 'flashlight',
          name: 'Flashlight',
          cost: 8,
          slots: 1,
          category: null,
          description: null,
        },
      ],
    }
    const { sent, handler } = recorder(Response.json(saved))
    const user = await openGear({ [`PUT ${base}/gear/flashlight`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add gear' }))
    await typeText(user, inDialog().getByLabelText('Name'), 'Flashlight')
    expect(inDialog().getByLabelText('Key')).toHaveValue('flashlight')
    await typeText(user, inDialog().getByLabelText('Cost'), '8')
    await user.click(inDialog().getByRole('button', { name: 'Save gear' }))

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 13, name: 'Flashlight', cost: 8 }])
    })
    expect(await screen.findByText('Flashlight')).toBeVisible()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('checks the key, the name and the numbers before it asks the server', async () => {
    const { sent, handler } = recorder(Response.json(sheetWithEconomy))
    const user = await openGear({ [`PUT ${base}/gear/rope`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Add gear' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'rope')
    await typeText(user, inDialog().getByLabelText('Cost'), '-3')
    await typeText(user, inDialog().getByLabelText('Slots'), '1.5')
    await user.click(inDialog().getByRole('button', { name: 'Save gear' }))

    expect(await inDialog().findByText('Another entry uses this key.')).toBeVisible()
    expect(inDialog().getByText('Enter a name of up to 60 characters.')).toBeVisible()
    expect(inDialog().getByText(/Enter a whole number from 0 to 1,000,000/)).toBeVisible()
    expect(inDialog().getByText(/Enter a whole number from 0 to 100/)).toBeVisible()
    expect(sent).toEqual([])
  })

  it('edits an entry by its read-only key, sending every set property', async () => {
    const { sent, handler } = recorder(Response.json({ ...sheetWithEconomy, version: 14 }))
    const user = await openGear({ [`PUT ${base}/gear/medkit`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit medkit' }))
    expect(inDialog().queryByLabelText('Key')).not.toBeInTheDocument()
    expect(inDialog().getByLabelText('Slots')).toHaveValue('2')
    await user.clear(inDialog().getByLabelText('Cost'))
    await typeText(user, inDialog().getByLabelText('Cost'), '25')
    await user.click(inDialog().getByRole('button', { name: 'Save gear' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { expectedVersion: 13, name: 'Medkit', cost: 25, slots: 2, category: 'Medical' },
      ])
    })
  })
})

describe('a refused save', () => {
  async function refuse(error: unknown, status: number) {
    const user = await openGear({ [`PUT ${base}/gear/medkit`]: json(error, status) })
    await user.click(await screen.findByRole('button', { name: 'Edit medkit' }))
    await user.click(inDialog().getByRole('button', { name: 'Save gear' }))
    return user
  }

  it('puts the publisher message of an input next to it', async () => {
    await refuse({ error: 'gear[1].cost: must be at most 1000000' }, 422)

    expect(await inDialog().findByText('must be at most 1000000')).toBeVisible()
    expect(inDialog().getByLabelText('Cost')).toBeInvalid()
    expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows a refusal of the catalog as a whole with its path', async () => {
    await refuse({ error: 'gear: the name "Medkit" is already taken' }, 422)

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'gear: the name "Medkit" is already taken',
    )
  })

  it('says a schema version 1 draft refuses gear', async () => {
    await refuse({ error: 'Schema version 1 drafts have no gear.' }, 422)

    expect(await inDialog().findByRole('alert')).toHaveTextContent('Schema version 1 drafts')
  })

  it('shows the message of a library pack as it is', async () => {
    await refuse({ error: 'A library pack has no gear.', code: 'library_pack' }, 409)

    expect(await inDialog().findByText('A library pack has no gear.')).toBeVisible()
  })

  it('offers the reload on a concurrent save and keeps what was typed', async () => {
    const user = await refuse(
      { error: 'Another request saved the draft.', code: 'modified_concurrently' },
      409,
    )

    expect(await inDialog().findByText('Another request saved the draft.')).toBeVisible()
    await user.click(inDialog().getByRole('button', reload))

    await waitFor(() => {
      expect(inDialog().queryByText('Another request saved the draft.')).not.toBeInTheDocument()
    })
    expect(inDialog().getByLabelText('Cost')).toHaveValue('20')
  })

  it('shows a 404 as it is, with no reload', async () => {
    await refuse({ error: 'The user has no GameSystem "my-draft".' }, 404)

    expect(await inDialog().findByRole('alert')).toHaveTextContent('has no GameSystem')
    expect(inDialog().queryByRole('button', reload)).not.toBeInTheDocument()
  })
})

describe('deleting an entry', () => {
  const remove = (key: string) => `DELETE ${base}/gear/${key}`

  it('sends the version it read', async () => {
    const { sent, handler } = recorder(
      Response.json({ ...sheetWithEconomy, version: 14, gear: sheetWithEconomy.gear.slice(1) }),
    )
    const user = await openGear({ [remove('used-pistol')]: handler })

    await user.click(await screen.findByRole('button', { name: 'Delete used-pistol' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the gear' }))

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=13'])
    })
    await waitFor(() => {
      expect(screen.queryByText('Used pistol')).not.toBeInTheDocument()
    })
  })

  it('names the shop that offers the entry and keeps the dialog open', async () => {
    const user = await openGear({
      [remove('used-pistol')]: json(
        { error: 'The gear "used-pistol" is offered at flows[0].phases[0].setup[1].offer.' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete used-pistol' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the gear' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent('is offered at flows[0]')
    expect(inDialog().queryByRole('button', reload)).not.toBeInTheDocument()
  })

  it('offers the reload when another request saved the draft', async () => {
    const user = await openGear({
      [remove('rope')]: json({ error: 'Another request saved the draft.' }, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete rope' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the gear' }))

    expect(await inDialog().findByText('Another request saved the draft.')).toBeVisible()
    expect(inDialog().getByRole('button', reload)).toBeVisible()
  })

  it('shows the message of a 404 when the entry is gone', async () => {
    const user = await openGear({
      [remove('rope')]: json({ error: 'The draft has no gear "rope".' }, 404),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete rope' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the gear' }))

    expect(await inDialog().findByText('The draft has no gear "rope".')).toBeVisible()
  })
})
