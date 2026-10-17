import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { checks, checksV1, draft, validDraft } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openChecks(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/checks`]: json(checks),
    ...handlers,
  })
  await user.click(await screen.findByRole('radio', { name: 'Checks' }))
  return { user, ...rendered }
}

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

const inDialog = () => within(screen.getByRole('dialog'))

describe('the checks tab', () => {
  it('lists the checks in order with their key and dice', async () => {
    await openChecks()

    const rows = within(await screen.findByRole('list')).getAllByRole('listitem')
    expect(rows).toHaveLength(2)
    expect(rows[0]).toHaveTextContent('Skill check')
    expect(rows[0]).toHaveTextContent('skill')
    expect(rows[0]).toHaveTextContent('1d10')
    expect(rows[1]).toHaveTextContent('Luck')
    expect(rows[1]).toHaveTextContent('d%')
    expect(screen.getByRole('button', { name: 'Move up skill' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move down luck' })).toBeDisabled()
  })

  it('says so when the draft has no checks', async () => {
    await openChecks({ [`GET ${base}/checks`]: json({ ...checks, checks: [] }) })

    expect(await screen.findByText('This draft has no checks yet.')).toBeVisible()
  })

  it('explains a schema version 1 draft', async () => {
    await openChecks({ [`GET ${base}/checks`]: json(checksV1) })

    expect(await screen.findByText(/Schema version 1 drafts have no checks/)).toBeVisible()
  })

  it('says the GameSystem is not found on a 404', async () => {
    await openChecks({ [`GET ${base}/checks`]: json({ error: 'No GameSystem' }, 404) })

    expect(
      await screen.findByText('This GameSystem does not exist, or it is not one of yours.'),
    ).toBeVisible()
  })

  it('lists a check of a shape the editor cannot read, without editing it', async () => {
    await openChecks({
      [`GET ${base}/checks`]: json({
        ...checks,
        checks: [{ key: 'odd', label: 'Odd', dice: 6, bands: [] }],
      }),
    })

    expect(await screen.findByText("Can't be edited here")).toBeVisible()
    expect(screen.getByRole('button', { name: 'Edit odd' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Delete odd' })).toBeEnabled()
  })

  it('opens a check in the editor with its bands read-only', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))

    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    expect(form.getByLabelText('Key')).toHaveValue('skill')
    expect(form.getByLabelText('Dice')).toHaveValue('1d10')
    expect(form.getByLabelText('Modifier')).toHaveValue('{pow}')
    expect(form.getByLabelText('Difficulty')).toBeChecked()
    expect(form.getByLabelText('Default')).toHaveValue('13')
    expect(form.getByText('up to {check:difficulty}-1')).toBeVisible()
    expect(form.getByText('catches the rest')).toBeVisible()
  })

  it('creates a check with the default band and selects the saved one', async () => {
    const saved = {
      ...checks,
      version: 6,
      checks: [
        ...checks.checks,
        {
          key: 'grit',
          label: 'Grit',
          dice: '2d6',
          bands: [{ key: 'outcome', label: 'Outcome' }],
        },
      ],
    }
    const { sent, handler } = recorder(Response.json(saved, { status: 201 }))
    const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    const form = within(screen.getByRole('form', { name: 'New check' }))
    await user.type(form.getByLabelText('Key'), 'grit')
    await user.type(form.getByLabelText('Label'), 'Grit')
    await user.type(form.getByLabelText('Dice'), '2d6')
    await user.click(form.getByRole('button', { name: 'Create check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'grit',
          label: 'Grit',
          dice: '2d6',
          bands: [{ key: 'outcome', label: 'Outcome' }],
        },
      },
    ])
    expect(await screen.findByRole('form', { name: 'Edit grit' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Edit grit' }).closest('li')).toHaveAttribute(
      'aria-current',
      'true',
    )
  })

  it('asks for what is missing without calling the API', async () => {
    const { sent, handler } = recorder(Response.json(checks))
    const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    await user.click(screen.getByRole('button', { name: 'Create check' }))

    expect(screen.getByText('Give the check a key.')).toBeVisible()
    expect(screen.getByText('Write the dice.')).toBeVisible()
    expect(sent).toEqual([])
  })

  it('saves an edit and a rename as the whole check, bands as stored', async () => {
    const renamed = { ...checks.checks[1], key: 'fortune' }
    const { sent, handler } = recorder(
      Response.json({ ...checks, version: 6, checks: [checks.checks[0], renamed] }),
    )
    const { user } = await openChecks({ [`PUT ${base}/checks/luck`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
    const form = within(screen.getByRole('form', { name: 'Edit luck' }))
    await user.clear(form.getByLabelText('Key'))
    await user.type(form.getByLabelText('Key'), 'fortune')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'fortune',
          label: 'Luck',
          dice: 'd%',
          bands: checks.checks[1]?.bands,
        },
      },
    ])
    expect(await screen.findByRole('form', { name: 'Edit fortune' })).toBeVisible()
  })

  it('shows the publisher refusal of the dice on the dice input', async () => {
    const { user } = await openChecks({
      [`PUT ${base}/checks/skill`]: json(
        { error: 'checks[0].dice: Unknown dice notation "2x6".' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    await user.clear(form.getByLabelText('Dice'))
    await user.type(form.getByLabelText('Dice'), '2x6')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText('Unknown dice notation "2x6".')).toBeVisible()
    expect(form.getByLabelText('Dice')).toBeInvalid()
    expect(form.queryByRole('alert')).toBeNull()
  })

  it('shows a refusal that has no input as a form message, with where the check is used', async () => {
    const { user } = await openChecks({
      [`PUT ${base}/checks/skill`]: json(
        { error: 'Check "skill" is used by a check step.', paths: ['sceneTypes[0].play[0].check'] },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    await user.click(screen.getByRole('button', { name: 'Save check' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Check "skill" is used by a check step. Used by sceneTypes[0].play[0].check.',
    )
  })

  it('on a conflict shows the message, reloads the editor and the next save carries the new version', async () => {
    let latest: unknown = checks
    let attempts = 0
    const sent: Record<string, unknown>[] = []
    const { user } = await openChecks({
      [`GET ${base}/checks`]: () => Response.json(latest),
      [`PUT ${base}/checks/skill`]: async (request) => {
        sent.push((await request.json()) as Record<string, unknown>)
        attempts += 1
        return attempts === 1
          ? Response.json(changed, { status: 409 })
          : Response.json({ ...(latest as typeof checks), version: 7 })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    await user.clear(form.getByLabelText('Label'))
    latest = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], label: 'Theirs' }, checks.checks[1]],
    }
    await user.type(form.getByLabelText('Label'), 'Mine')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await waitFor(() => {
      expect(
        within(screen.getByRole('form', { name: 'Edit skill' })).getByLabelText('Label'),
      ).toHaveValue('Theirs')
    })

    await user.click(screen.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(2)
    })
    expect(sent.map((body) => body.expectedVersion)).toEqual([5, 6])
    expect(sent[1]).toMatchObject({ check: { label: 'Theirs' } })
    await waitFor(() => {
      expect(screen.queryByText(/was changed by another request/)).toBeNull()
    })
  })

  it('closes the editor on a conflict when the check is gone', async () => {
    let latest: unknown = checks
    const { user } = await openChecks({
      [`GET ${base}/checks`]: () => Response.json(latest),
      [`PUT ${base}/checks/luck`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
    latest = { ...checks, version: 6, checks: [checks.checks[0]] }
    await user.click(screen.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(screen.queryByRole('form', { name: 'Edit luck' })).toBeNull()
    })
    expect(screen.getByRole('alert')).toHaveTextContent('was changed by another request')
  })

  it('moves a check to its neighbour’s position', async () => {
    const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
    const { user } = await openChecks({ [`POST ${base}/checks/luck/move`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Move up luck' }))

    expect(sent).toEqual([{ expectedVersion: 5, index: 0 }])
  })

  it('on a conflict while moving shows the message and reloads the list', async () => {
    let latest: unknown = checks
    const { user } = await openChecks({
      [`GET ${base}/checks`]: () => Response.json(latest),
      [`POST ${base}/checks/skill/move`]: () => {
        latest = { ...checks, version: 6, checks: [checks.checks[1], checks.checks[0]] }
        return Response.json(changed, { status: 409 })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Move down skill' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Move up skill' })).toBeEnabled()
    })
  })

  describe('when the person moves or deletes from the list while editing', () => {
    const swapped = { ...checks, version: 6, checks: [checks.checks[1], checks.checks[0]] }

    it('keeps the unsaved edits and saves at the version their own move returned', async () => {
      const { sent, handler } = recorder(Response.json({ ...swapped, version: 7 }))
      const { user } = await openChecks({
        [`POST ${base}/checks/luck/move`]: json(swapped),
        [`PUT ${base}/checks/skill`]: handler,
      })

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      await user.clear(form.getByLabelText('Label'))
      await user.type(form.getByLabelText('Label'), 'Mine')
      await user.click(screen.getByRole('button', { name: 'Move up luck' }))
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Move down luck' })).toBeEnabled()
      })
      expect(form.getByLabelText('Label')).toHaveValue('Mine')
      await user.click(form.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect(sent[0]).toMatchObject({ expectedVersion: 6, check: { label: 'Mine' } })
      expect(screen.queryByText(/was changed by another request/)).toBeNull()
    })

    it('keeps the edits and the new version after deleting another check', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 7 }))
      const { user } = await openChecks({
        [`DELETE ${base}/checks/luck`]: json({ ...checks, version: 6, checks: [checks.checks[0]] }),
        [`PUT ${base}/checks/skill`]: handler,
      })

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      await user.clear(form.getByLabelText('Label'))
      await user.type(form.getByLabelText('Label'), 'Mine')
      await user.click(screen.getByRole('button', { name: 'Delete luck' }))
      await user.click(inDialog().getByRole('button', { name: 'Delete the check' }))
      await waitFor(() => {
        expect(screen.queryByRole('dialog')).toBeNull()
      })
      await user.click(form.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect(sent[0]).toMatchObject({ expectedVersion: 6, check: { label: 'Mine' } })
    })

    it('closes the editor when the check being edited is deleted', async () => {
      const { user } = await openChecks({
        [`DELETE ${base}/checks/luck`]: json({ ...checks, version: 6, checks: [checks.checks[0]] }),
      })

      await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
      await user.click(screen.getByRole('button', { name: 'Delete luck' }))
      await user.click(inDialog().getByRole('button', { name: 'Delete the check' }))

      await waitFor(() => {
        expect(screen.queryByRole('form', { name: 'Edit luck' })).toBeNull()
      })
    })

    it('keeps the old version when the result skipped one, so a save reports the conflict', async () => {
      const { sent, handler } = recorder(Response.json(changed, { status: 409 }))
      const { user } = await openChecks({
        [`POST ${base}/checks/luck/move`]: json({ ...swapped, version: 7 }),
        [`PUT ${base}/checks/skill`]: handler,
      })

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      await user.click(screen.getByRole('button', { name: 'Move up luck' }))
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Move down luck' })).toBeEnabled()
      })
      await user.click(screen.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect(sent[0]).toMatchObject({ expectedVersion: 5 })
      expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    })
  })

  it('says the latest version could not be loaded when the reload after a conflict fails, and retries', async () => {
    let reachable = true
    const { user } = await openChecks({
      [`GET ${base}/checks`]: () =>
        reachable ? Response.json(checks) : Response.json({ error: 'Down.' }, { status: 500 }),
      [`POST ${base}/checks/skill/move`]: () => {
        reachable = false
        return Response.json(changed, { status: 409 })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Move down skill' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('was changed by another request')
    expect(alert).toHaveTextContent("Couldn't load the latest version")
    expect(alert).not.toHaveTextContent('The latest version was loaded.')

    reachable = true
    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText(/The latest version was loaded\./)).toBeVisible()
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull()
  })

  it('deletes a check after confirming', async () => {
    let calls = 0
    const { user } = await openChecks({
      [`DELETE ${base}/checks/luck`]: (request) => {
        calls += 1
        expect(new URL(request.url).searchParams.get('expectedVersion')).toBe('5')
        return Response.json({ ...checks, version: 6, checks: [checks.checks[0]] })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete luck' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the check' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull()
    })
    expect(calls).toBe(1)
    expect(screen.queryByRole('button', { name: 'Edit luck' })).toBeNull()
  })

  it('lists every usage when a check in use cannot be deleted', async () => {
    const paths = ['sceneTypes[0].play[0].check', 'flows[1].phases[0].steps[2].check']
    const { user } = await openChecks({
      [`DELETE ${base}/checks/skill`]: json(
        { error: 'Check "skill" is used by a check step.', paths },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete skill' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the check' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(`Used by ${paths.join(', ')}.`)
    // Still listed behind the dialog.
    expect(screen.getByRole('button', { name: 'Edit skill', hidden: true })).toBeInTheDocument()
  })

  it('on a conflict while deleting reloads and closes the dialog', async () => {
    const { user } = await openChecks({ [`DELETE ${base}/checks/luck`]: json(changed, 409) })

    await user.click(await screen.findByRole('button', { name: 'Delete luck' }))
    await user.click(inDialog().getByRole('button', { name: 'Delete the check' }))
    expect(await inDialog().findByRole('alert')).toHaveTextContent('was changed')
    await user.click(inDialog().getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull()
    })
  })
})
