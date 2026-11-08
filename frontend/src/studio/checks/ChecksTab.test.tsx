import {
  cleanup,
  screen,
  waitFor,
  within,
  type BoundFunctions,
  type queries,
} from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { checks, checksV1, draft, sheet, validDraft } from '../fixtures'
import { checksKey } from './useGameSystemChecks'
import { replaceText, typeText } from '@/test/typeText'

/** The placeholders of the inputs drawn, so a test can count the band rows that rendered. */
const drawn = vi.hoisted(() => ({ placeholders: [] as (string | undefined)[] }))

vi.mock('@/shared/ui/input', async (importOriginal) => {
  const { Input: Real } = await importOriginal<typeof import('@/shared/ui/input')>()
  return {
    Input: (props: React.ComponentProps<'input'>) => {
      drawn.placeholders.push(props.placeholder)
      return <Real {...props} />
    },
  }
})

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
  await user.click(await screen.findByRole('link', { name: 'Checks' }))
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

  it('opens a check in the editor with its bands and their bounds', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))

    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    expect(form.getByLabelText('Key')).toHaveValue('skill')
    expect(form.getByLabelText('Dice')).toHaveValue('1d10')
    expect(form.getByLabelText('Modifier')).toHaveValue('{pow}')
    expect(form.getByLabelText('Difficulty')).toBeChecked()
    expect(form.getByLabelText('Default')).toHaveValue('13')
    expect(form.getByLabelText('Band 1 key')).toHaveValue('fail')
    expect(form.getByLabelText('Band 1 bound')).toHaveValue('formula')
    expect(form.getByLabelText('Band 1 formula')).toHaveValue('{check:difficulty}-1')
    expect(form.getByLabelText('Band 2 key')).toHaveValue('win')
    expect(form.queryByLabelText('Band 2 bound')).toBeNull()
    expect(form.getByText(/The last band catches the rest/)).toBeVisible()
  })

  it('titles the editor with the label while the form keeps its name by the key', async () => {
    await openChecks()

    await userEvent.click(await screen.findByRole('button', { name: 'Edit skill' }))

    const form = screen.getByRole('form', { name: 'Edit skill' })
    expect(within(form).getByRole('heading', { name: 'Edit Skill check' })).toBeVisible()
  })

  it('titles the editor with the key when the label is empty', async () => {
    await openChecks({
      [`GET ${base}/checks`]: json({
        ...checks,
        checks: [{ ...checks.checks[1], label: '' }],
      }),
    })

    await userEvent.click(await screen.findByRole('button', { name: 'Edit luck' }))

    const form = screen.getByRole('form', { name: 'Edit luck' })
    expect(within(form).getByRole('heading', { name: 'Edit luck' })).toBeVisible()
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
    expect(form.getByLabelText('Key')).toHaveAccessibleDescription(
      'Lower case letters, digits and hyphens.',
    )
    await typeText(user, form.getByLabelText('Key'), 'grit')
    await typeText(user, form.getByLabelText('Label'), 'Grit')
    await typeText(user, form.getByLabelText('Dice'), '2d6')
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
    await replaceText(user, form.getByLabelText('Key'), 'fortune')
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

  it('toggles "No situational modifier" and saves it with the check', async () => {
    const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
    const { user } = await openChecks({ [`PUT ${base}/checks/luck`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
    const form = within(screen.getByRole('form', { name: 'Edit luck' }))
    expect(form.getByLabelText('No situational modifier')).not.toBeChecked()
    await user.click(form.getByLabelText('No situational modifier'))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'luck',
          label: 'Luck',
          dice: 'd%',
          bands: checks.checks[1]?.bands,
          noSituationalModifier: true,
        },
      },
    ])
  })

  it('opens a check that takes no situational modifier with the toggle on', async () => {
    const steady = { ...checks.checks[1], noSituationalModifier: true }
    const { user } = await openChecks({
      [`GET ${base}/checks`]: json({ ...checks, checks: [checks.checks[0], steady] }),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit luck' }))

    expect(
      within(screen.getByRole('form', { name: 'Edit luck' })).getByLabelText(
        'No situational modifier',
      ),
    ).toBeChecked()
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
    await replaceText(user, form.getByLabelText('Dice'), '2x6')
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

  describe('on a conflict while saving', () => {
    const theirs = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], label: 'Theirs' }, checks.checks[1]],
    }

    /** Edits skill's label to "Mine" and saves it: the first save answers 409, the rest succeed. */
    async function conflictOnSkill(handlers: Record<string, FakeHandler> = {}) {
      const state = {
        latest: checks as unknown,
        down: false,
        attempts: 0,
        sent: [] as Record<string, unknown>[],
      }
      const opened = await openChecks({
        [`GET ${base}/checks`]: () =>
          state.down
            ? Response.json({ error: 'Down.' }, { status: 500 })
            : Response.json(state.latest),
        [`PUT ${base}/checks/skill`]: async (request) => {
          state.sent.push((await request.json()) as Record<string, unknown>)
          state.attempts += 1
          return state.attempts === 1
            ? Response.json(changed, { status: 409 })
            : Response.json({ ...(state.latest as typeof checks), version: 7 })
        },
        ...handlers,
      })
      await opened.user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      await replaceText(opened.user, form.getByLabelText('Label'), 'Mine')
      state.latest = theirs
      await opened.user.click(form.getByRole('button', { name: 'Save check' }))
      return { ...opened, state, form }
    }

    it('keeps the draft and offers to reload, with no list notice', async () => {
      const { form } = await conflictOnSkill()

      expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
      expect(
        screen.getByText('Reloading replaces your unsaved edits with the latest stored check.'),
      ).toBeVisible()
      expect(form.getByRole('button', { name: 'Reload the latest version' })).toHaveAttribute(
        'type',
        'button',
      )
      expect(form.getByLabelText('Label')).toHaveValue('Mine')
      expect(screen.queryByText(/The latest version was loaded/)).toBeNull()
    })

    it('replaces the draft only on reload, and the next save carries the new version', async () => {
      const { user, state } = await conflictOnSkill()

      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      await waitFor(() => {
        expect(
          within(screen.getByRole('form', { name: 'Edit skill' })).getByLabelText('Label'),
        ).toHaveValue('Theirs')
      })
      expect(screen.queryByText(/Reloading replaces/)).toBeNull()
      await user.click(screen.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(state.sent).toHaveLength(2)
      })
      expect(state.sent.map((body) => body.expectedVersion)).toEqual([5, 6])
      expect(state.sent[1]).toMatchObject({ check: { label: 'Theirs' } })
    })

    it('keeps the draft and the old version when the reload fails', async () => {
      const { user, state, form } = await conflictOnSkill()
      await screen.findByRole('button', { name: 'Reload the latest version' })
      state.down = true

      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      expect(
        await screen.findByText("Couldn't reload the latest version. Try again."),
      ).toBeVisible()
      expect(form.getByLabelText('Label')).toHaveValue('Mine')
      await user.click(form.getByRole('button', { name: 'Save check' }))
      await waitFor(() => {
        expect(state.sent).toHaveLength(2)
      })
      expect(state.sent[1]).toMatchObject({ expectedVersion: 5, check: { label: 'Mine' } })
    })

    it('keeps the draft with a note when the check is gone, and Duplicate saves it as a new check', async () => {
      const { user, state, form } = await conflictOnSkill()
      state.latest = { ...checks, version: 6, checks: [checks.checks[1]] }

      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(
        await screen.findByText(
          'The check skill no longer exists. Your changes are kept: Duplicate saves them as a new check.',
        ),
      ).toBeVisible()
      expect(form.getByLabelText('Label')).toHaveValue('Mine')

      await user.click(form.getByRole('button', { name: 'Duplicate' }))

      const copy = within(await screen.findByRole('form', { name: 'New check' }))
      expect(copy.getByLabelText('Label')).toHaveValue('Mine (copy)')
    })

    it('keeps the draft with a note when the check changed to a shape the editor cannot read', async () => {
      const { user, state, form } = await conflictOnSkill()
      state.latest = {
        ...checks,
        version: 6,
        checks: [{ key: 'skill', label: 'Odd', dice: 6, bands: [] }, checks.checks[1]],
      }

      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(
        await screen.findByText(
          'The check skill changed to a shape this editor cannot read. Your changes are kept; the next save still conflicts.',
        ),
      ).toBeVisible()
      expect(form.getByLabelText('Label')).toHaveValue('Mine')
    })

    it('still reports the unsaved edits to the guard after the conflict', async () => {
      const { user } = await conflictOnSkill()
      await screen.findByRole('button', { name: 'Reload the latest version' })

      await user.click(screen.getByRole('button', { name: 'New check' }))

      expect(await screen.findByRole('dialog')).toBeVisible()
    })
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

  describe('on a conflict while moving with an open editor', () => {
    const theirs = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], label: 'Theirs' }, checks.checks[1]],
    }

    async function moveWhileEditing(edit: boolean) {
      let latest: unknown = checks
      const sent: Record<string, unknown>[] = []
      const opened = await openChecks({
        [`GET ${base}/checks`]: () => Response.json(latest),
        [`POST ${base}/checks/luck/move`]: () => {
          latest = theirs
          return Response.json(changed, { status: 409 })
        },
        [`PUT ${base}/checks/skill`]: async (request) => {
          sent.push((await request.json()) as Record<string, unknown>)
          return Response.json(changed, { status: 409 })
        },
      })
      await opened.user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      if (edit) {
        await replaceText(opened.user, form.getByLabelText('Label'), 'Mine')
      }
      await opened.user.click(screen.getByRole('button', { name: 'Move up luck' }))
      expect(await screen.findByText(/The latest version was loaded\./)).toBeVisible()
      return { ...opened, sent }
    }

    it('keeps an editor with unsaved edits on its draft and version', async () => {
      const { user, sent } = await moveWhileEditing(true)

      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      expect(form.getByLabelText('Label')).toHaveValue('Mine')
      await user.click(form.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect(sent[0]).toMatchObject({ expectedVersion: 5, check: { label: 'Mine' } })
      expect(await screen.findByRole('button', { name: 'Reload the latest version' })).toBeVisible()
    })

    it('reopens an editor with no unsaved edits on the fresh version', async () => {
      await moveWhileEditing(false)

      await waitFor(() => {
        expect(
          within(screen.getByRole('form', { name: 'Edit skill' })).getByLabelText('Label'),
        ).toHaveValue('Theirs')
      })
    })

    it('closes an editor with no unsaved edits when its check is gone', async () => {
      let latest: unknown = checks
      const { user } = await openChecks({
        [`GET ${base}/checks`]: () => Response.json(latest),
        [`POST ${base}/checks/luck/move`]: () => {
          latest = { ...checks, version: 6, checks: [checks.checks[1]] }
          return Response.json(changed, { status: 409 })
        },
      })
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      expect(screen.getByRole('form', { name: 'Edit skill' })).toBeVisible()

      await user.click(screen.getByRole('button', { name: 'Move up luck' }))

      await waitFor(() => {
        expect(screen.queryByRole('form', { name: 'Edit skill' })).toBeNull()
      })
    })

    it('says so, and keeps the editor, when its check changed to a shape it cannot read', async () => {
      let latest: unknown = checks
      const { user } = await openChecks({
        [`GET ${base}/checks`]: () => Response.json(latest),
        [`POST ${base}/checks/luck/move`]: () => {
          latest = {
            ...checks,
            version: 6,
            checks: [{ key: 'skill', label: 'Odd', dice: 6, bands: [] }, checks.checks[1]],
          }
          return Response.json(changed, { status: 409 })
        },
      })
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const label = within(screen.getByRole('form', { name: 'Edit skill' })).getByLabelText('Label')
      const before = (label as HTMLInputElement).value

      await user.click(screen.getByRole('button', { name: 'Move up luck' }))

      expect(await screen.findByText(/cannot read/)).toHaveTextContent(
        'the check skill changed to a shape this editor cannot read',
      )
      expect(screen.queryByText(/The latest version was loaded\.$/)).toBeNull()
      expect(
        within(screen.getByRole('form', { name: 'Edit skill' })).getByLabelText('Label'),
      ).toHaveValue(before)
    })

    it('leaves an editor opened during the refetch alone when the first check is gone', async () => {
      let latest: unknown = checks
      let gate: Promise<void> | null = null
      const hold = { release: null as (() => void) | null }
      const { user } = await openChecks({
        [`GET ${base}/checks`]: async () => {
          if (gate !== null) {
            await gate
          }
          return Response.json(latest)
        },
        [`POST ${base}/checks/luck/move`]: () => {
          latest = { ...checks, version: 6, checks: [checks.checks[1]] }
          gate = new Promise<void>((resolve) => {
            hold.release = resolve
          })
          return Response.json(changed, { status: 409 })
        },
      })
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      await user.click(screen.getByRole('button', { name: 'Move up luck' }))
      // The refetch is held back: open another check meanwhile and edit it.
      await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
      const form = within(screen.getByRole('form', { name: 'Edit luck' }))
      await replaceText(user, form.getByLabelText('Label'), 'Mine')

      hold.release?.()

      expect(await screen.findByText(/The latest version was loaded\./)).toBeVisible()
      expect(
        within(screen.getByRole('form', { name: 'Edit luck' })).getByLabelText('Label'),
      ).toHaveValue('Mine')
    })
  })

  describe('the bands editor', () => {
    async function editLuck(handlers: Record<string, FakeHandler> = {}) {
      const opened = await openChecks(handlers)
      await opened.user.click(await screen.findByRole('button', { name: 'Edit luck' }))
      return { ...opened, form: within(screen.getByRole('form', { name: 'Edit luck' })) }
    }

    it('shows a bound of every kind and saves the untouched check unchanged', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
      const { user, form } = await editLuck({ [`PUT ${base}/checks/luck`]: handler })

      expect(form.getByLabelText('Band 1 bound')).toHaveValue('tracker')
      expect(form.getByLabelText('Band 1 tracker')).toHaveValue('heat')
      expect(form.getByLabelText('Band 2 bound')).toHaveValue('number')
      expect(form.getByLabelText('Band 2 number')).toHaveValue('40')
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(sent[0]).toMatchObject({ check: { bands: checks.checks[1]?.bands } })
    })

    it('adds, edits, reorders and removes bands, sending them in order', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
      const { user, form } = await editLuck({ [`PUT ${base}/checks/luck`]: handler })

      await user.click(form.getByRole('button', { name: 'Add band' }))
      await typeText(user, form.getByLabelText('Band 4 key'), 'crit')
      await typeText(user, form.getByLabelText('Band 4 label'), 'Critical')
      // The old last band now needs a bound.
      await chooseOption(user, 'Band 3 bound', 'number', form)
      await typeText(user, form.getByLabelText('Band 3 number'), '90')
      await user.click(form.getByRole('button', { name: 'Move down band 1' }))
      // Band 1 is now Middling and band 2 the Bad one.
      await user.click(form.getByRole('button', { name: 'Remove band 2' }))
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(sent[0]).toMatchObject({
        check: {
          bands: [
            { key: 'mid', label: 'Middling', upTo: 40 },
            { key: 'good', label: 'Good', upTo: 90 },
            { key: 'crit', label: 'Critical' },
          ],
        },
      })
      expect(sent[0]).not.toHaveProperty('check.bands.2.upTo')
    })

    it('says a bound moved to the last place is dropped, and does not send it', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
      const { user, form } = await editLuck({ [`PUT ${base}/checks/luck`]: handler })

      await user.click(form.getByRole('button', { name: 'Move down band 2' }))

      expect(form.getByText(/Its bound is dropped when you save/)).toBeVisible()
      await user.click(form.getByRole('button', { name: 'Save check' }))
      // Band 3 (no bound) moved up, so it needs one: nothing is sent.
      expect(sent).toEqual([])
      expect(form.getByText('Choose a bound: only the last band catches the rest.')).toBeVisible()
    })

    it('limits a check to between 1 and 10 bands', async () => {
      const { user, form } = await editLuck()

      for (let count = 3; count < 10; count += 1) {
        await user.click(form.getByRole('button', { name: 'Add band' }))
      }
      expect(form.getByRole('button', { name: 'Add band' })).toBeDisabled()
      for (let count = 10; count > 1; count -= 1) {
        await user.click(form.getByRole('button', { name: 'Remove band 1' }))
      }
      expect(form.getByRole('button', { name: 'Remove band 1' })).toBeDisabled()
    })

    it('refuses a band number that is not whole without calling the API', async () => {
      const { sent, handler } = recorder(Response.json(checks))
      const { user, form } = await editLuck({ [`PUT ${base}/checks/luck`]: handler })

      await replaceText(user, form.getByLabelText('Band 2 number'), '4.5')
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByText('Must be a whole number.')).toBeVisible()
      expect(form.getByLabelText('Band 2 number')).toBeInvalid()
      expect(sent).toEqual([])
    })

    it('keeps a stored tracker the draft no longer has, and a bound of a shape it has no input for', async () => {
      const { sent, handler } = recorder(Response.json(checks))
      const odd = {
        key: 'odd',
        label: 'Odd',
        dice: '1d6',
        bands: [
          { key: 'a', label: 'A', upTo: { tracker: 'gone' } },
          { key: 'b', label: 'B', upTo: 2.5 },
          { key: 'c', label: 'C' },
        ],
      }
      const { user } = await openChecks({
        [`GET ${base}/checks`]: json({ ...checks, checks: [odd] }),
        [`PUT ${base}/checks/odd`]: handler,
      })
      await user.click(await screen.findByRole('button', { name: 'Edit odd' }))
      const form = within(screen.getByRole('form', { name: 'Edit odd' }))

      expect(form.getByLabelText('Band 1 tracker')).toHaveValue('gone')
      expect(form.getByLabelText('Band 1 tracker')).toHaveTextContent('gone (not in this draft)')
      expect(form.getByText('2.5')).toBeVisible()
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(sent[0]).toMatchObject({ check: { bands: odd.bands } })
    })

    it.each([
      ['key', 'Band 2 key'],
      ['label', 'Band 2 label'],
      ['upTo', 'Band 2 number'],
    ])('puts the publisher refusal of a band %s on that band input', async (path, input) => {
      const { user, form } = await editLuck({
        [`PUT ${base}/checks/luck`]: json(
          { error: `checks[1].bands[1].${path}: is refused.` },
          422,
        ),
      })

      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByText('is refused.')).toBeVisible()
      expect(form.getByLabelText(input)).toBeInvalid()
      expect(form.queryByRole('alert')).toBeNull()
    })

    it('shows the refusal of a formula bound with its path below the bound on the formula input', async () => {
      const { user, form } = await editLuck({
        [`PUT ${base}/checks/luck`]: json(
          { error: 'checks[1].bands[0].upTo.tracker: Unknown tracker "heat".' },
          422,
        ),
      })

      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByText('Unknown tracker "heat".')).toBeVisible()
      expect(form.getByLabelText('Band 1 tracker')).toBeInvalid()
    })

    it('shows a refusal listing outcome paths as the server returns it', async () => {
      const text =
        'Band "good" is used by outcomes: sceneTypes[0].play[0].outcomes[1].band, flows[0].phases[0].steps[0].outcomes[2].band.'
      const { user, form } = await editLuck({
        [`PUT ${base}/checks/luck`]: json({ error: text }, 422),
      })

      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByRole('alert')).toHaveTextContent(text)
    })

    describe('a refusal of a band follows the band', () => {
      const refused = {
        [`PUT ${base}/checks/luck`]: json({ error: 'checks[1].bands[1].key: is refused.' }, 422),
      }

      async function refuseMid() {
        const opened = await editLuck(refused)
        await opened.user.click(opened.form.getByRole('button', { name: 'Save check' }))
        expect(await opened.form.findByText('is refused.')).toBeVisible()
        expect(opened.form.getByLabelText('Band 2 key')).toBeInvalid()
        return opened
      }

      it('moves with its band when the band is moved', async () => {
        const { user, form } = await refuseMid()

        await user.click(form.getByRole('button', { name: 'Move up band 2' }))

        expect(form.getByLabelText('Band 1 key')).toHaveValue('mid')
        expect(form.getByLabelText('Band 1 key')).toBeInvalid()
        expect(form.getByLabelText('Band 2 key')).toBeValid()
        expect(form.getAllByText('is refused.')).toHaveLength(1)
      })

      it('shifts up when an earlier band is removed, and goes with a removed band', async () => {
        const { user, form } = await refuseMid()

        await user.click(form.getByRole('button', { name: 'Remove band 1' }))

        expect(form.getByLabelText('Band 1 key')).toHaveValue('mid')
        expect(form.getByLabelText('Band 1 key')).toBeInvalid()
        expect(form.getByLabelText('Band 2 key')).toBeValid()

        await user.click(form.getByRole('button', { name: 'Remove band 1' }))

        expect(form.queryByText('is refused.')).toBeNull()
        expect(form.getByLabelText('Band 1 key')).toBeValid()
      })

      it('stays on its band when a band is added, and the new band starts clean', async () => {
        const { user, form } = await refuseMid()

        await user.click(form.getByRole('button', { name: 'Add band' }))

        expect(form.getByLabelText('Band 2 key')).toBeInvalid()
        expect(form.getByLabelText('Band 4 key')).toBeValid()
        expect(form.getAllByText('is refused.')).toHaveLength(1)
      })

      it('keeps the form’s own message about another input of the same band', async () => {
        const { user, form } = await editLuck({
          [`PUT ${base}/checks/luck`]: json({ error: 'checks[1].bands[0].key: is refused.' }, 422),
        })
        await user.click(form.getByRole('button', { name: 'Save check' }))
        expect(await form.findByText('is refused.')).toBeVisible()

        await user.clear(form.getByLabelText('Band 1 label'))
        await user.click(form.getByRole('button', { name: 'Save check' }))

        expect(form.getByText('is refused.')).toBeVisible()
        expect(form.getByText('Give the band a label.')).toBeVisible()
        expect(form.getByLabelText('Band 1 key')).toBeInvalid()
        expect(form.getByLabelText('Band 1 label')).toBeInvalid()
      })
    })

    it('renders only the edited band row when a band changes', async () => {
      const { user, form } = await editLuck()
      drawn.placeholders.length = 0

      await replaceText(user, form.getByLabelText('Band 2 label'), 'Steady')

      expect(form.getByLabelText('Band 2 label')).toHaveValue('Steady')
      // Every band row has a label input: only the second one was drawn again.
      expect(drawn.placeholders.filter((placeholder) => placeholder === 'Label')).toHaveLength(1)
    })

    it('shows the bands-level refusal under the bands', async () => {
      const { user, form } = await editLuck({
        [`PUT ${base}/checks/luck`]: json(
          { error: 'checks[1].bands: must have 1 to 10 items.' },
          422,
        ),
      })

      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByText('must have 1 to 10 items.')).toBeVisible()
      expect(form.queryByRole('alert')).toBeNull()
    })

    it('reports a 409 on a concurrent save of the bands', async () => {
      const { user, form } = await editLuck({ [`PUT ${base}/checks/luck`]: json(changed, 409) })

      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    })

    it('offers {check:difficulty} only in band formulas of a check with a difficulty', async () => {
      const { user } = await openChecks()
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))

      // Only band 1's menu offers it: the modifier's never does.
      const offers = async () => {
        let count = 0
        for (const menu of form.getAllByRole('combobox', { name: 'Insert reference' })) {
          const options = await listOptions(user, menu)
          count += options.filter((option) => option.label.includes('{check:difficulty}')).length
        }
        return count
      }
      expect(await offers()).toBe(1)

      await user.click(form.getByLabelText('Difficulty'))
      expect(await offers()).toBe(0)
      expect(form.getByText(/this check declares no difficulty/)).toBeVisible()
    })

    it('diagnoses the modifier and a band formula as they are typed', async () => {
      const { user } = await openChecks()
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))

      await replaceText(user, form.getByLabelText('Modifier'), '{check:difficulty}')
      expect(form.getByText(/the modifier cannot read a check reference/)).toBeVisible()

      await replaceText(user, form.getByLabelText('Modifier'), '{pow}+2')
      expect(form.getByText('Value with the sample values: 2')).toBeVisible()

      await replaceText(user, form.getByLabelText('Band 1 formula'), '{nope}')
      expect(form.getByText(/unknown field "nope"/)).toBeVisible()
      expect(form.getByRole('button', { name: 'Save check' })).toBeEnabled()
    })
  })

  describe('duplicating a check', () => {
    const grit = {
      key: 'grit',
      label: 'Grit',
      dice: '2d6',
      bands: [{ key: 'outcome', label: 'Outcome' }],
    }

    it('opens an unsaved copy from the list, saved through the create endpoint', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }, { status: 201 }))
      const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

      await user.click(await screen.findByRole('button', { name: 'Duplicate skill' }))

      const form = within(screen.getByRole('form', { name: 'New check' }))
      expect(form.getByLabelText('Key')).toHaveValue('skill-copy')
      expect(form.getByLabelText('Label')).toHaveValue('Skill check (copy)')
      expect(form.getByLabelText('Dice')).toHaveValue('1d10')
      expect(form.getByLabelText('Band 1 formula')).toHaveValue('{check:difficulty}-1')
      // Nothing is stored until the person saves.
      expect(sent).toEqual([])
      await user.click(form.getByRole('button', { name: 'Create check' }))

      expect(sent).toHaveLength(1)
      expect(sent[0]).toMatchObject({
        expectedVersion: 5,
        check: { key: 'skill-copy', label: 'Skill check (copy)', dice: '1d10' },
      })
    })

    it('duplicates from the editor head and takes the first free copy key', async () => {
      const { user } = await openChecks({
        [`GET ${base}/checks`]: json({
          ...checks,
          checks: [...checks.checks, { ...grit, key: 'skill-copy' }],
        }),
      })

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      await user.click(
        within(screen.getByRole('form', { name: 'Edit skill' })).getByRole('button', {
          name: 'Duplicate',
        }),
      )

      const form = within(screen.getByRole('form', { name: 'New check' }))
      expect(form.getByLabelText('Key')).toHaveValue('skill-copy-2')
    })

    it('duplicates the visible draft from the editor head, unsaved edits included', async () => {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }, { status: 201 }))
      const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const edit = within(screen.getByRole('form', { name: 'Edit skill' }))
      await replaceText(user, edit.getByLabelText('Dice'), '3d6')
      await user.click(edit.getByRole('button', { name: 'Duplicate' }))

      const form = within(screen.getByRole('form', { name: 'New check' }))
      expect(form.getByLabelText('Key')).toHaveValue('skill-copy')
      expect(form.getByLabelText('Dice')).toHaveValue('3d6')
      await user.click(form.getByRole('button', { name: 'Create check' }))
      expect(sent[0]).toMatchObject({ check: { key: 'skill-copy', dice: '3d6' } })
    })
  })

  describe('band presets and the key that follows the label', () => {
    async function editLuck() {
      const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
      const opened = await openChecks({ [`PUT ${base}/checks/luck`]: handler })
      await opened.user.click(await screen.findByRole('button', { name: 'Edit luck' }))
      return { ...opened, sent, form: within(screen.getByRole('form', { name: 'Edit luck' })) }
    }

    it('replaces the bands with a preset and sends them', async () => {
      const { user, form, sent } = await editLuck()

      await user.click(form.getByRole('button', { name: 'Use a preset' }))
      await user.click(await screen.findByRole('menuitem', { name: 'Miss / partial / full' }))

      expect(form.getByLabelText('Band 1 key')).toHaveValue('miss')
      expect(form.getByLabelText('Band 2 number')).toHaveValue('9')
      expect(form.queryByLabelText('Band 4 key')).toBeNull()
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(sent[0]).toMatchObject({
        check: {
          bands: [
            { key: 'miss', label: 'Miss', upTo: 6 },
            { key: 'partial', label: 'Partial hit', upTo: 9 },
            { key: 'full', label: 'Full hit' },
          ],
        },
      })
    })

    it('leaves the bands alone when the menu is closed without a choice', async () => {
      const { user, form } = await editLuck()

      await user.click(form.getByRole('button', { name: 'Use a preset' }))
      await user.keyboard('{Escape}')

      expect(form.getByLabelText('Band 1 key')).toHaveValue('bad')
      expect(form.getByLabelText('Band 3 key')).toHaveValue('good')
    })

    it('asks for the bound a Failure / success preset leaves empty, without calling the API', async () => {
      const { user, form, sent } = await editLuck()

      await user.click(form.getByRole('button', { name: 'Use a preset' }))
      await user.click(await screen.findByRole('menuitem', { name: 'Failure / success' }))
      await user.click(form.getByRole('button', { name: 'Save check' }))

      expect(await form.findByText('Give the band a number.')).toBeVisible()
      expect(sent).toEqual([])
    })

    it('copies the bands of another check, bounds included', async () => {
      const { user, form, sent } = await editLuck()

      await user.click(form.getByRole('button', { name: 'Use a preset' }))
      // A submenu opens from the keyboard in jsdom (the pointer's grace area needs a layout).
      screen.getByRole('menuitem', { name: 'Copy from a check…' }).focus()
      await user.keyboard('{ArrowRight}')
      await user.click(await screen.findByRole('menuitem', { name: 'Skill check' }))

      expect(form.getByLabelText('Band 1 formula')).toHaveValue('{check:difficulty}-1')
      expect(form.getByLabelText('Band 2 key')).toHaveValue('win')
      expect(form.queryByLabelText('Band 3 key')).toBeNull()
      await user.click(form.getByRole('button', { name: 'Save check' }))
      expect(sent[0]).toMatchObject({ check: { bands: checks.checks[0]?.bands } })
    })

    it('disables Copy from a check when no other check can be read', async () => {
      const { user } = await openChecks({
        [`GET ${base}/checks`]: json({ ...checks, checks: [checks.checks[1]] }),
      })
      await user.click(await screen.findByRole('button', { name: 'Edit luck' }))
      const form = within(screen.getByRole('form', { name: 'Edit luck' }))

      await user.click(form.getByRole('button', { name: 'Use a preset' }))

      expect(await screen.findByRole('menuitem', { name: 'Copy from a check…' })).toHaveAttribute(
        'aria-disabled',
        'true',
      )
    })

    it('starts a new band as a number bound and writes its key from its label until edited', async () => {
      const { user, form } = await editLuck()

      await user.click(form.getByRole('button', { name: 'Add band' }))
      // The band that was last needs a bound now: it starts as a number too.
      expect(form.getByLabelText('Band 3 bound')).toHaveValue('number')
      expect(form.queryByLabelText('Band 4 bound')).toBeNull()
      await typeText(user, form.getByLabelText('Band 4 label'), 'Great hit')
      expect(form.getByLabelText('Band 4 key')).toHaveValue('great-hit')

      await replaceText(user, form.getByLabelText('Band 4 key'), 'great')
      await user.type(form.getByLabelText('Band 4 label'), '!')
      expect(form.getByLabelText('Band 4 key')).toHaveValue('great')
    })

    it('does not rewrite the key of a stored band when its label changes', async () => {
      const { user, form } = await editLuck()

      await typeText(user, form.getByLabelText('Band 2 label'), ' now')

      expect(form.getByLabelText('Band 2 label')).toHaveValue('Middling now')
      expect(form.getByLabelText('Band 2 key')).toHaveValue('mid')
    })

    it('says what the modifier reads', async () => {
      const { user } = await openChecks()

      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      expect(form.getByText('Reads Power')).toBeVisible()

      await replaceText(user, form.getByLabelText('Modifier'), '{pow}+{tracker:heat}')
      expect(form.getByText('Reads Power, Heat')).toBeVisible()
    })
  })

  describe('the formula previews', () => {
    // pow defaults to 7: the sandbox starts it there, not at 0.
    const sample = {
      ...sheet,
      fields: sheet.fields.map((field) => (field.key === 'pow' ? { ...field, default: 7 } : field)),
    }

    async function formulas(handlers: Record<string, FakeHandler>) {
      const { user } = await openChecks(handlers)
      await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
      const form = within(screen.getByRole('form', { name: 'Edit skill' }))
      await replaceText(user, form.getByLabelText('Modifier'), '{pow}+1')
      await replaceText(user, form.getByLabelText('Band 1 formula'), '{pow}*2')
      return form
    }

    it('read the number fields at the sandbox defaults', async () => {
      const form = await formulas({ [`GET ${base}/sheet`]: json(sample) })

      await waitFor(() => {
        expect(form.getByText('Value with the sample values: 8')).toBeVisible()
      })
      expect(form.getByText('Value with the sample values: 14')).toBeVisible()
    })

    it('read a number field as 0 while the sheet is still being read', async () => {
      const form = await formulas({
        [`GET ${base}/sheet`]: () => new Promise<Response>(() => undefined),
      })

      expect(form.getByText('Value with the sample values: 1')).toBeVisible()
      expect(form.getByText('Value with the sample values: 0')).toBeVisible()
    })

    it('read a number field as 0 when the sheet could not be read', async () => {
      const form = await formulas({ [`GET ${base}/sheet`]: json({ error: 'No' }, 500) })

      await waitFor(() => {
        expect(form.getByText('Value with the sample values: 1')).toBeVisible()
      })
      expect(form.getByText('Value with the sample values: 0')).toBeVisible()
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
      await replaceText(user, form.getByLabelText('Label'), 'Mine')
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
      await replaceText(user, form.getByLabelText('Label'), 'Mine')
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

/** A pool check with a count formula and a push, as the publisher stores it. */
const sneak = {
  key: 'sneak',
  label: 'Sneak',
  hint: 'Count the dice you keep',
  pool: { count: { formula: '{pow} + 1' }, sides: 6, successAt: 5 },
  push: {
    label: 'Take a Condition',
    effects: [{ sheet: { field: 'hp', op: 'subtract', value: 1 } }],
  },
  bands: [
    { key: 'fail', label: 'Fail', upTo: 0 },
    { key: 'win', label: 'Win' },
  ],
}

const withSneak = { ...checks, checks: [...checks.checks, sneak] }

describe('the dice and pool modes of the check editor', () => {
  it('lists a pool check as a dice pool, editable', async () => {
    await openChecks({ [`GET ${base}/checks`]: json(withSneak) })

    const row = (await screen.findByRole('button', { name: 'Edit sneak' })).closest('li')
    expect(row).toHaveTextContent('dice pool')
    expect(screen.getByRole('button', { name: 'Edit sneak' })).toBeEnabled()
  })

  it('opens a pool check in pool mode with its count, sides and success threshold, and no modifier', async () => {
    const { user } = await openChecks({ [`GET ${base}/checks`]: json(withSneak) })

    await user.click(await screen.findByRole('button', { name: 'Edit sneak' }))
    const form = within(screen.getByRole('form', { name: 'Edit sneak' }))

    expect(form.getByLabelText('Dice pool')).toBeChecked()
    expect(form.getByLabelText('Pool count')).toHaveValue('{pow} + 1')
    expect(form.getByLabelText('Pool sides')).toHaveValue('6')
    expect(form.getByLabelText('Success at')).toHaveValue('5')
    expect(form.queryByLabelText('Dice')).toBeNull()
    expect(form.queryByLabelText('Modifier')).toBeNull()
  })

  it('opens a dice check in dice mode, and switches the inputs with the mode', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    expect(form.getByLabelText('Dice roll')).toBeChecked()
    expect(form.getByLabelText('Dice')).toHaveValue('1d10')
    expect(form.getByLabelText('Modifier')).toHaveValue('{pow}')
    expect(form.getByText(/A push goes with the pool/)).toBeVisible()

    await user.click(form.getByLabelText('Dice pool'))

    expect(form.queryByLabelText('Dice')).toBeNull()
    expect(form.queryByLabelText('Modifier')).toBeNull()
    expect(form.getByLabelText('Pool count')).toHaveValue('')
    expect(form.getByLabelText('Pool sides')).toHaveValue('')

    await user.click(form.getByLabelText('Dice roll'))

    expect(form.getByLabelText('Dice')).toHaveValue('1d10')
    expect(form.queryByLabelText('Pool count')).toBeNull()
  })

  it('creates a pool check with an integer count and with no dice or modifier', async () => {
    const { sent, handler } = recorder(Response.json(withSneak, { status: 201 }))
    const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    const form = within(screen.getByRole('form', { name: 'New check' }))
    await typeText(user, form.getByLabelText('Key'), 'grit')
    await typeText(user, form.getByLabelText('Label'), 'Grit')
    await user.click(form.getByLabelText('Dice pool'))
    await typeText(user, form.getByLabelText('Pool count'), ' 3 ')
    await typeText(user, form.getByLabelText('Pool sides'), '10')
    await typeText(user, form.getByLabelText('Success at'), '8')
    await user.click(form.getByRole('button', { name: 'Create check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'grit',
          label: 'Grit',
          pool: { count: 3, sides: 10, successAt: 8 },
          bands: [{ key: 'outcome', label: 'Outcome' }],
        },
      },
    ])
  })

  it('saves a stored pool check with its push exactly as stored', async () => {
    const { sent, handler } = recorder(Response.json({ ...withSneak, version: 6 }))
    const { user } = await openChecks({
      [`GET ${base}/checks`]: json(withSneak),
      [`PUT ${base}/checks/sneak`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit sneak' }))
    const form = within(screen.getByRole('form', { name: 'Edit sneak' }))
    await replaceText(user, form.getByLabelText('Label'), 'Sneak about')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(sent).toEqual([{ expectedVersion: 5, check: { ...sneak, label: 'Sneak about' } }])
    expect(JSON.stringify((sent[0]?.check as { push: unknown }).push)).toBe(
      JSON.stringify(sneak.push),
    )
  })

  it('drops the pool and its push when the check goes back to dice', async () => {
    const { sent, handler } = recorder(Response.json({ ...withSneak, version: 6 }))
    const { user } = await openChecks({
      [`GET ${base}/checks`]: json(withSneak),
      [`PUT ${base}/checks/sneak`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit sneak' }))
    const form = within(screen.getByRole('form', { name: 'Edit sneak' }))
    await user.click(form.getByLabelText('Dice roll'))
    await typeText(user, form.getByLabelText('Dice'), '2d6')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'sneak',
          label: 'Sneak',
          hint: 'Count the dice you keep',
          dice: '2d6',
          bands: sneak.bands,
        },
      },
    ])
  })

  it('saves a dice check as before: no pool, no push', async () => {
    const { sent, handler } = recorder(Response.json({ ...checks, version: 6 }))
    const { user } = await openChecks({ [`PUT ${base}/checks/skill`]: handler })

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    await user.type(form.getByLabelText('Hint'), '!')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(sent).toHaveLength(1)
    expect(sent[0]?.check).toEqual({ ...checks.checks[0], hint: 'Roll low, add your edge!' })
    expect(sent[0]?.check).not.toHaveProperty('pool')
    expect(sent[0]?.check).not.toHaveProperty('push')
  })

  it('asks for what the pool needs without calling the API', async () => {
    const { sent, handler } = recorder(Response.json(checks))
    const { user } = await openChecks({ [`POST ${base}/checks`]: handler })

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    const form = within(screen.getByRole('form', { name: 'New check' }))
    await user.click(form.getByLabelText('Dice pool'))
    await user.click(form.getByRole('button', { name: 'Create check' }))

    expect(form.getByText('Give the pool a count.')).toBeVisible()
    expect(form.getByText('Give the pool its sides.')).toBeVisible()
    expect(form.getByText('Give the success threshold.')).toBeVisible()
    expect(form.queryByText('Write the dice.')).toBeNull()

    await typeText(user, form.getByLabelText('Pool count'), '101')
    await typeText(user, form.getByLabelText('Pool sides'), '6')
    await typeText(user, form.getByLabelText('Success at'), '7')
    await user.click(form.getByRole('button', { name: 'Create check' }))

    expect(form.getByText('The count must be between 1 and 100, or a formula.')).toBeVisible()
    expect(form.getByText('Must be between 1 and 6.')).toBeVisible()
    expect(form.getByLabelText('Pool count')).toBeInvalid()
    expect(sent).toEqual([])
  })

  it('advises on the count formula as a modifier is advised on', async () => {
    const { user } = await openChecks({ [`GET ${base}/checks`]: json(withSneak) })

    await user.click(await screen.findByRole('button', { name: 'Edit sneak' }))
    const form = within(screen.getByRole('form', { name: 'Edit sneak' }))
    expect(form.getByText('Reads Power')).toBeVisible()

    await replaceText(user, form.getByLabelText('Pool count'), '{ghost} + 1')
    expect(await form.findByText(/unknown field "ghost"/)).toBeVisible()
    await replaceText(user, form.getByLabelText('Pool count'), '{check:difficulty}')
    expect(await form.findByText(/cannot read a check reference/)).toBeVisible()
  })

  it('shows the publisher refusal of the count on the count input', async () => {
    const { user } = await openChecks({
      [`GET ${base}/checks`]: json(withSneak),
      [`PUT ${base}/checks/sneak`]: json(
        { error: 'checks[2].pool.count.formula: unknown field "ghost".' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit sneak' }))
    const form = within(screen.getByRole('form', { name: 'Edit sneak' }))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText('unknown field "ghost".')).toBeVisible()
    expect(form.getByLabelText('Pool count')).toBeInvalid()
  })
})

/** A pool check that cannot be pushed yet, and the conditions field a push cost may mark. */
const raid = {
  key: 'raid',
  label: 'Raid',
  pool: { count: { formula: '{pow} + 1' }, sides: 6, successAt: 5 },
  bands: [
    { key: 'fail', label: 'Fail', upTo: 0 },
    { key: 'win', label: 'Win' },
  ],
}

const withRaid = {
  ...checks,
  checks: [...checks.checks, raid],
  conditions: [
    {
      key: 'physical',
      label: 'Physical',
      options: [
        { key: 'hurt', label: 'Hurt' },
        { key: 'exhausted', label: 'Exhausted' },
      ],
    },
  ],
}

describe('the push of a pool check in the check editor', () => {
  async function editRaid(check: Record<string, unknown> = raid, handlers = {}) {
    const opened = await openChecks({
      [`GET ${base}/checks`]: json({ ...withRaid, checks: [...checks.checks, check] }),
      ...handlers,
    })
    await opened.user.click(await screen.findByRole('button', { name: 'Edit raid' }))
    const form = within(screen.getByRole('form', { name: 'Edit raid' }))
    const effect = (number: number) =>
      within(form.getByRole('group', { name: `Effect ${String(number)}` }))
    return { ...opened, form, effect }
  }

  const addEffect = async (
    user: ReturnType<typeof userEvent.setup>,
    form: BoundFunctions<typeof queries>,
    kind: string,
  ) => {
    await chooseOption(user, 'New effect kind', kind, form)
    await user.click(form.getByRole('button', { name: 'Add effect' }))
  }

  it('offers the push only in Pool mode, off until it is switched on', async () => {
    const { user, form } = await editRaid()

    expect(form.getByLabelText('Can be pushed')).not.toBeChecked()
    expect(form.queryByLabelText('Cost label')).toBeNull()

    await user.click(form.getByLabelText('Can be pushed'))
    expect(form.getByLabelText('Cost label')).toHaveValue('')
    expect(form.getByText('No effects.')).toBeVisible()

    await user.click(form.getByLabelText('Dice roll'))
    expect(form.queryByLabelText('Can be pushed')).toBeNull()
    expect(form.queryByLabelText('Cost label')).toBeNull()
  })

  it('does not offer it on a dice check', async () => {
    await openChecks()
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Edit skill' }))

    expect(screen.queryByLabelText('Can be pushed')).toBeNull()
  })

  it('saves the cost label and effects of every shape: mark with an option, mark with the pick, clear all, a number and a tracker', async () => {
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form, effect } = await editRaid(raid, { [`PUT ${base}/checks/raid`]: handler })

    await user.click(form.getByLabelText('Can be pushed'))
    await typeText(user, form.getByLabelText('Cost label'), 'Take a Condition')
    await addEffect(user, form, 'sheet')
    await chooseOption(user, 'Sheet field', 'physical', effect(1))
    await chooseOption(user, 'Option', 'Hurt', effect(1))
    await addEffect(user, form, 'sheet')
    await chooseOption(user, 'Sheet field', 'physical', effect(2))
    await addEffect(user, form, 'sheet')
    await chooseOption(user, 'Sheet field', 'physical', effect(3))
    await chooseOption(user, 'Operation', 'clear', effect(3))
    await addEffect(user, form, 'sheet')
    await chooseOption(user, 'Sheet field', 'hp', effect(4))
    await chooseOption(user, 'Operation', 'subtract', effect(4))
    await addEffect(user, form, 'tracker')
    await chooseOption(user, 'Tracker', 'heat', effect(5))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: {
        ...raid,
        push: {
          label: 'Take a Condition',
          effects: [
            { kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' },
            { kind: 'sheet', field: 'physical', op: 'mark' },
            { kind: 'sheet', field: 'physical', op: 'clear' },
            { kind: 'sheet', field: 'hp', op: 'subtract', value: 1 },
            { kind: 'tracker', tracker: 'heat', op: 'add', value: 1 },
          ],
        },
      },
    })
  })

  it('offers the player’s pick on a mark of the cost, and only sheet and tracker effects', async () => {
    const { user, form, effect } = await editRaid({
      ...raid,
      push: { label: 'Pay', effects: [{ kind: 'sheet', field: 'physical', op: 'mark' }] },
    })

    expect(
      (await listOptions(user, form.getByLabelText('New effect kind'))).map((kind) => kind.value),
    ).toEqual(['tracker', 'sheet'])
    expect(
      (await listOptions(user, effect(1).getByRole('combobox', { name: 'Option' }))).map(
        (option) => option.label,
      ),
    ).toEqual(['The player picks', 'Hurt', 'Exhausted'])
    expect(
      (await listOptions(user, effect(1).getByRole('combobox', { name: 'Sheet field' }))).map(
        (option) => option.label,
      ),
    ).toEqual(['Pick a sheet field', 'Power', 'Hit points', 'Physical'])
  })

  it('lists the cost’s sheet fields in the order of the sheet, a conditions field among them (S8)', async () => {
    const [pow, , hp] = sheet.fields
    const physical = { key: 'physical', label: 'Physical', type: 'conditions', options: [] }
    const { user, effect } = await editRaid(
      {
        ...raid,
        push: { label: 'Pay', effects: [{ kind: 'sheet', field: 'physical', op: 'mark' }] },
      },
      { [`GET ${base}/sheet`]: json({ ...sheet, fields: [physical, pow, hp] }) },
    )

    await waitFor(async () => {
      expect(
        (await listOptions(user, effect(1).getByRole('combobox', { name: 'Sheet field' }))).map(
          (option) => option.label,
        ),
      ).toEqual(['Pick a sheet field', 'Physical', 'Power', 'Hit points'])
    })
  })

  it('reads a stored push into the form and saves it unchanged', async () => {
    const stored = {
      ...raid,
      push: {
        label: 'Take a Condition',
        effects: [
          { kind: 'sheet', field: 'physical', op: 'clear', value: 'hurt' },
          { kind: 'tracker', tracker: 'heat', op: 'add', value: { formula: '{tracker:heat} + 1' } },
        ],
      },
    }
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form, effect } = await editRaid(stored, { [`PUT ${base}/checks/raid`]: handler })

    expect(form.getByLabelText('Can be pushed')).toBeChecked()
    expect(form.getByLabelText('Cost label')).toHaveValue('Take a Condition')
    expect(effect(1).getByRole('combobox', { name: 'Option' })).toHaveTextContent('Hurt')
    await replaceText(user, form.getByLabelText('Cost label'), 'Pay')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: { ...stored, push: { ...stored.push, label: 'Pay' } },
    })
  })

  it('drops the push when it is switched off', async () => {
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form } = await editRaid(
      { ...raid, push: { label: 'Pay', effects: [] } },
      { [`PUT ${base}/checks/raid`]: handler },
    )

    await user.click(form.getByLabelText('Can be pushed'))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({ expectedVersion: 5, check: raid })
  })

  it('asks for the cost label without calling the API', async () => {
    const { sent, handler } = recorder(Response.json(withRaid))
    const { user, form } = await editRaid(raid, { [`PUT ${base}/checks/raid`]: handler })

    await user.click(form.getByLabelText('Can be pushed'))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText('Give the push a cost label.')).toBeVisible()
    expect(form.getByLabelText('Cost label')).toBeInvalid()
    expect(sent).toHaveLength(0)
  })

  it('shows the publisher refusals on the cost label and on the effect they name', async () => {
    const { user, form, effect } = await editRaid(
      {
        ...raid,
        push: {
          label: 'Pay',
          effects: [
            { kind: 'sheet', field: 'physical', op: 'mark' },
            { kind: 'sheet', field: 'hp', op: 'add', value: 1 },
          ],
        },
      },
      {
        [`PUT ${base}/checks/raid`]: json(
          { error: 'checks[2].push.effects[1].value: must be an integer.' },
          422,
        ),
      },
    )

    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await effect(2).findByText('must be an integer.')).toBeVisible()
    expect(effect(1).queryByText('must be an integer.')).toBeNull()
  })

  it('shows a refusal of the label on the cost label', async () => {
    const { user, form } = await editRaid(
      { ...raid, push: { label: 'Pay', effects: [] } },
      { [`PUT ${base}/checks/raid`]: json({ error: 'checks[2].push.label: is too long.' }, 422) },
    )

    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText('is too long.')).toBeVisible()
    expect(form.getByLabelText('Cost label')).toBeInvalid()
  })

  it('keeps an effect it cannot edit as stored, read-only', async () => {
    const stored = { ...raid, push: { label: 'Pay', effects: [{ kind: 'teleport', to: 'moon' }] } }
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form } = await editRaid(stored, { [`PUT ${base}/checks/raid`]: handler })

    expect(form.getByText(/kind or shape this editor cannot edit/)).toBeVisible()
    await replaceText(user, form.getByLabelText('Label'), 'Raid!')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({ expectedVersion: 5, check: { ...stored, label: 'Raid!' } })
  })

  const vampire = {
    ...raid,
    pool: {
      count: { formula: '{pow} + 1' },
      sides: 10,
      successAt: 6,
      criticals: { bonus: 2 },
      hunger: { count: 2 },
    },
    push: { label: 'Spend Willpower', maxDice: 3 },
  }

  it('reads criticals, Hunger dice and the push cap, and saves them unchanged', async () => {
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form } = await editRaid(vampire, { [`PUT ${base}/checks/raid`]: handler })

    expect(form.getByLabelText('Critical pairs bonus')).toHaveValue('2')
    expect(form.getByLabelText('Hunger dice')).toHaveValue('2')
    expect(form.getByLabelText('Dice cap')).toHaveValue('3')
    await replaceText(user, form.getByLabelText('Dice cap'), '2')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: { ...vampire, push: { label: 'Spend Willpower', maxDice: 2 } },
    })
  })

  it('sends a Hunger formula, and nothing for the inputs left empty', async () => {
    const { sent, handler } = recorder(Response.json({ ...withRaid, version: 6 }))
    const { user, form } = await editRaid(raid, { [`PUT ${base}/checks/raid`]: handler })

    await typeText(user, form.getByLabelText('Hunger dice'), '{pow}')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: { ...raid, pool: { ...raid.pool, hunger: { count: { formula: '{pow}' } } } },
    })
  })

  it('asks for a bonus and a cap in range without calling the API', async () => {
    const { sent, handler } = recorder(Response.json(withRaid))
    const { user, form } = await editRaid(vampire, { [`PUT ${base}/checks/raid`]: handler })

    await replaceText(user, form.getByLabelText('Critical pairs bonus'), '0')
    await replaceText(user, form.getByLabelText('Dice cap'), '0')
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText(/The bonus must be a whole number from 1 to 10/)).toBeVisible()
    expect(form.getByText(/The cap must be a whole number from 1 to 100/)).toBeVisible()
    expect(form.getByLabelText('Critical pairs bonus')).toBeInvalid()
    expect(form.getByLabelText('Dice cap')).toBeInvalid()
    expect(sent).toHaveLength(0)
  })

  it('shows the publisher refusals on the inputs of the new fields', async () => {
    const { user, form } = await editRaid(vampire, {
      [`PUT ${base}/checks/raid`]: json(
        { error: 'checks[2].pool.hunger.count: must be at most 100, 101 given.' },
        422,
      ),
    })

    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText('must be at most 100, 101 given.')).toBeVisible()
    expect(form.getByLabelText('Hunger dice')).toBeInvalid()
  })
})

/** An action roll with a momentum and a burn, and a progress roll, as the publisher stores them (ADR 0049). */
const edge = {
  key: 'edge',
  label: 'Edge',
  dice: '1d6',
  modifier: '{pow}',
  challenge: { count: 2, sides: 10, scoreMax: 10, momentum: 'momentum' },
  push: {
    label: 'Burn momentum',
    effects: [{ kind: 'sheet', field: 'momentum', op: 'subtract', value: 8 }],
  },
  bands: [
    { key: 'miss', label: 'Miss', upTo: 0 },
    { key: 'weak', label: 'Weak hit', upTo: 1 },
    { key: 'strong', label: 'Strong hit' },
  ],
}

const fulfill = {
  key: 'fulfill',
  label: 'Fulfill a vow',
  modifier: '{pow}',
  challenge: { count: 2, sides: 10 },
  bands: [
    { key: 'miss', label: 'Miss', upTo: 0 },
    { key: 'hit', label: 'Hit' },
  ],
}

const withChallenges = {
  ...checks,
  checks: [...checks.checks, edge, fulfill],
  fields: [...checks.fields, { key: 'momentum', label: 'Momentum', type: 'number', max: 10 }],
}

describe('the challenge mode of the check editor (ADR 0049)', () => {
  async function editCheck(key: string, handlers: Record<string, FakeHandler> = {}) {
    const opened = await openChecks({ [`GET ${base}/checks`]: json(withChallenges), ...handlers })
    await opened.user.click(await screen.findByRole('button', { name: `Edit ${key}` }))
    return { ...opened, form: within(screen.getByRole('form', { name: `Edit ${key}` })) }
  }

  it('lists a challenge check, with or without dice, as editable challenge dice', async () => {
    await openChecks({ [`GET ${base}/checks`]: json(withChallenges) })

    const progress = (await screen.findByRole('button', { name: 'Edit fulfill' })).closest('li')
    expect(progress).toHaveTextContent('challenge dice')
    expect(screen.getByRole('button', { name: 'Edit fulfill' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Edit edge' }).closest('li')).toHaveTextContent('1d6')
  })

  it('offers a test roll for a challenge check, as for any saved check', async () => {
    await editCheck('edge')

    expect(screen.getByRole('region', { name: 'Test roll sandbox' })).toBeVisible()
    expect(screen.queryByText(/not available yet/)).not.toBeInTheDocument()
  })

  it('offers a test roll for a progress roll, with no difficulty', async () => {
    await editCheck('fulfill')

    const sandbox = within(screen.getByRole('region', { name: 'Test roll sandbox' }))
    expect(sandbox.getByRole('button', { name: 'Roll the check' })).toBeVisible()
    expect(sandbox.queryByLabelText('Test difficulty')).toBeNull()
  })

  it('offers no test roll for a check not saved yet', async () => {
    const { user } = await editCheck('edge')

    await user.click(screen.getByRole('button', { name: 'New check' }))

    expect(screen.queryByRole('region', { name: 'Test roll sandbox' })).not.toBeInTheDocument()
  })

  it('opens an action roll with its challenge, momentum and burn, and no difficulty', async () => {
    const { form } = await editCheck('edge')

    expect(form.getByLabelText('Challenge count')).toHaveValue('2')
    expect(form.getByLabelText('Challenge sides')).toHaveValue('10')
    expect(form.getByLabelText('Score cap')).toHaveValue('10')
    expect(form.getByLabelText('Action roll')).toBeChecked()
    expect(form.getByLabelText('Dice')).toHaveValue('1d6')
    expect(form.getByLabelText('Modifier')).toHaveValue('{pow}')
    expect(form.getByRole('combobox', { name: 'Momentum' })).toHaveTextContent(
      'Momentum (momentum)',
    )
    expect(form.getByLabelText('Can be burned')).toBeChecked()
    expect(form.getByLabelText('Cost label')).toHaveValue('Burn momentum')
    expect(form.queryByLabelText('Dice cap')).toBeNull()
    expect(form.queryByLabelText('Difficulty')).toBeNull()
  })

  it('lists the number fields only as momentum, plus no momentum', async () => {
    const { user, form } = await editCheck('edge')

    expect(
      (await listOptions(user, form.getByRole('combobox', { name: 'Momentum' }))).map(
        (option) => option.label,
      ),
    ).toEqual(['No momentum', 'Power (pow)', 'Momentum (momentum)'])
  })

  it('saves an action roll with a burn, and a progress roll, exactly as stored', async () => {
    for (const [key, stored] of [
      ['edge', edge],
      ['fulfill', fulfill],
    ] as const) {
      const { sent, handler } = recorder(Response.json({ ...withChallenges, version: 6 }))
      const { user, form } = await editCheck(key, { [`PUT ${base}/checks/${key}`]: handler })

      await replaceText(user, form.getByLabelText('Label'), 'Renamed')
      await user.click(form.getByRole('button', { name: 'Save check' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect(sent[0]).toEqual({ expectedVersion: 5, check: { ...stored, label: 'Renamed' } })
      cleanup()
    }
  })

  it('opens a progress roll without dice, momentum or burn', async () => {
    const { form } = await editCheck('fulfill')

    expect(form.getByLabelText('Progress roll')).toBeChecked()
    expect(form.queryByLabelText('Dice')).toBeNull()
    expect(form.queryByRole('combobox', { name: 'Momentum' })).toBeNull()
    expect(form.queryByLabelText('Can be burned')).toBeNull()
    expect(form.getByLabelText('Modifier')).toHaveValue('{pow}')
  })

  it('creates a challenge check from the mode, with the difficulty dropped', async () => {
    const { sent, handler } = recorder(Response.json(withChallenges, { status: 201 }))
    const { user } = await openChecks({
      [`GET ${base}/checks`]: json(withChallenges),
      [`POST ${base}/checks`]: handler,
    })

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    const form = within(screen.getByRole('form', { name: 'New check' }))
    await typeText(user, form.getByLabelText('Key'), 'iron')
    await typeText(user, form.getByLabelText('Label'), 'Iron')
    await user.click(form.getByLabelText('Difficulty'))
    await user.click(form.getByLabelText('Challenge dice'))
    expect(form.queryByLabelText('Difficulty')).toBeNull()
    await typeText(user, form.getByLabelText('Dice'), '1d6')
    await typeText(user, form.getByLabelText('Challenge count'), '2')
    await typeText(user, form.getByLabelText('Challenge sides'), '10')
    await typeText(user, form.getByLabelText('Score cap'), '10')
    await user.click(form.getByRole('button', { name: 'Create check' }))

    expect(sent).toEqual([
      {
        expectedVersion: 5,
        check: {
          key: 'iron',
          label: 'Iron',
          dice: '1d6',
          challenge: { count: 2, sides: 10, scoreMax: 10 },
          bands: [{ key: 'outcome', label: 'Outcome' }],
        },
      },
    ])
  })

  it('offers the burn only while a momentum is chosen, and drops it when the momentum is cleared', async () => {
    const { sent, handler } = recorder(Response.json({ ...withChallenges, version: 6 }))
    const { user, form } = await editCheck('edge', { [`PUT ${base}/checks/edge`]: handler })

    await chooseOption(user, 'Momentum', 'No momentum', form)

    expect(form.queryByLabelText('Can be burned')).toBeNull()
    await user.click(form.getByRole('button', { name: 'Save check' }))
    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: {
        key: 'edge',
        label: 'Edge',
        dice: '1d6',
        modifier: '{pow}',
        challenge: { count: 2, sides: 10, scoreMax: 10 },
        bands: edge.bands,
      },
    })
  })

  it('clears the dice, the momentum and the burn on a progress roll', async () => {
    const { sent, handler } = recorder(Response.json({ ...withChallenges, version: 6 }))
    const { user, form } = await editCheck('edge', { [`PUT ${base}/checks/edge`]: handler })

    await user.click(form.getByLabelText('Progress roll'))

    expect(form.queryByLabelText('Dice')).toBeNull()
    expect(form.queryByRole('combobox', { name: 'Momentum' })).toBeNull()
    expect(form.queryByLabelText('Can be burned')).toBeNull()
    await user.click(form.getByRole('button', { name: 'Save check' }))
    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toEqual({
      expectedVersion: 5,
      check: {
        ...fulfill,
        key: 'edge',
        label: 'Edge',
        challenge: { count: 2, sides: 10, scoreMax: 10 },
        bands: edge.bands,
      },
    })
  })

  it('asks for the limits and the modifier of a progress roll without calling the API', async () => {
    const { sent, handler } = recorder(Response.json(withChallenges))
    const { user, form } = await editCheck('fulfill', { [`PUT ${base}/checks/fulfill`]: handler })

    await replaceText(user, form.getByLabelText('Challenge count'), '11')
    await replaceText(user, form.getByLabelText('Challenge sides'), '1')
    await replaceText(user, form.getByLabelText('Score cap'), '0')
    await user.clear(form.getByLabelText('Modifier'))
    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText(/The count must be a whole number from 2 to 10/)).toBeVisible()
    expect(form.getByText('Must be a whole number from 2 to 1000.')).toBeVisible()
    expect(form.getByText(/The cap must be a whole number from 1 to 1000/)).toBeVisible()
    expect(form.getByText('Give the modifier: it is the score of a progress roll.')).toBeVisible()
    expect(form.getByLabelText('Challenge count')).toBeInvalid()
    expect(sent).toHaveLength(0)
  })

  it('shows the publisher refusals on the inputs of the challenge', async () => {
    const { user, form } = await editCheck('edge', {
      [`PUT ${base}/checks/edge`]: json(
        {
          error:
            'checks[2].challenge.momentum: needs dice that rolls exactly one die, "2d6" given.',
        },
        422,
      ),
    })

    await user.click(form.getByRole('button', { name: 'Save check' }))

    expect(await form.findByText(/needs dice that rolls exactly one die/)).toBeVisible()
    expect(form.getByRole('combobox', { name: 'Momentum' })).toBeInvalid()
  })

  it('does not edit a challenge of an unknown shape', async () => {
    await openChecks({
      [`GET ${base}/checks`]: json({
        ...checks,
        checks: [{ ...edge, challenge: { count: 2, sides: 10, extra: true } }],
      }),
    })

    expect(await screen.findByText("Can't be edited here")).toBeVisible()
    expect(screen.getByRole('button', { name: 'Edit edge' })).toBeDisabled()
  })

  it('keeps a dice check as before, with its difficulty, and no challenge', async () => {
    const { sent, handler } = recorder(Response.json({ ...withChallenges, version: 6 }))
    const { user, form } = await editCheck('skill', { [`PUT ${base}/checks/skill`]: handler })

    expect(form.getByLabelText('Dice roll')).toBeChecked()
    expect(form.getByLabelText('Difficulty')).toBeChecked()
    expect(form.queryByLabelText('Challenge sides')).toBeNull()
    await user.click(form.getByRole('button', { name: 'Save check' }))
    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]?.check).not.toHaveProperty('challenge')
  })
})

describe('leaving a check with unsaved edits', () => {
  const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })
  const leave = (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole('button', { name: 'Leave without saving' }))

  /** Opens `skill` and changes its dice, so the form holds an unsaved edit. */
  async function editedSkill(handlers: Record<string, FakeHandler> = {}) {
    const opened = await openChecks(handlers)
    await opened.user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const form = within(screen.getByRole('form', { name: 'Edit skill' }))
    await replaceText(opened.user, form.getByLabelText('Dice'), '3d6')
    return { ...opened, form }
  }

  it('switches to another check with no question while the form is untouched', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    await user.click(screen.getByRole('button', { name: 'Edit luck' }))

    expect(screen.getByRole('form', { name: 'Edit luck' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks before opening another check, and Stay keeps the draft', async () => {
    const { user, form } = await editedSkill()

    await user.click(screen.getByRole('button', { name: 'Edit luck' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Stay' }))

    expect(dialog()).not.toBeInTheDocument()
    expect(form.getByLabelText('Dice')).toHaveValue('3d6')
  })

  it('opens the other check when the person chooses to leave', async () => {
    const { user } = await editedSkill()

    await user.click(screen.getByRole('button', { name: 'Edit luck' }))
    await leave(user)

    expect(await screen.findByRole('form', { name: 'Edit luck' })).toBeVisible()
    expect(screen.queryByRole('form', { name: 'Edit skill' })).not.toBeInTheDocument()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks before reopening the check already open, which would restart the form', async () => {
    const { user } = await editedSkill()

    await user.click(screen.getByRole('button', { name: 'Edit skill' }))
    await leave(user)

    const form = within(await screen.findByRole('form', { name: 'Edit skill' }))
    expect(form.getByLabelText('Dice')).toHaveValue('1d10')
  })

  it('asks before New check', async () => {
    const { user } = await editedSkill()

    await user.click(screen.getByRole('button', { name: 'New check' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await leave(user)

    expect(await screen.findByRole('form', { name: 'New check' })).toBeVisible()
  })

  it('asks before a row’s Duplicate', async () => {
    const { user } = await editedSkill()

    await user.click(screen.getByRole('button', { name: 'Duplicate luck' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await leave(user)

    const form = within(await screen.findByRole('form', { name: 'New check' }))
    expect(form.getByLabelText('Key')).toHaveValue('luck-copy')
  })

  it('lets an untouched New check go', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'New check' }))
    await user.click(screen.getByRole('button', { name: 'Edit skill' }))

    expect(screen.getByRole('form', { name: 'Edit skill' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('counts a duplicate as unsaved', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Duplicate skill' }))
    await user.click(screen.getByRole('button', { name: 'Edit luck' }))

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
  })

  it('does not ask for the editor’s own Duplicate, which takes the edits along', async () => {
    const { user, form } = await editedSkill()

    await user.click(form.getByRole('button', { name: 'Duplicate' }))

    expect(await screen.findByRole('form', { name: 'New check' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('asks before a change of tab, and Stay keeps the draft', async () => {
    const { user, form } = await editedSkill()

    await user.click(screen.getByRole('link', { name: 'Overview' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    await user.click(screen.getByRole('button', { name: 'Stay' }))

    expect(form.getByLabelText('Dice')).toHaveValue('3d6')
  })

  it('leaves for another tab with no question while the form is untouched', async () => {
    const { user } = await openChecks()

    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    await user.click(screen.getByRole('link', { name: 'Overview' }))

    expect(await screen.findByRole('form', { name: 'Metadata' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })

  it('goes on with the action when the save finishes behind the question', async () => {
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], dice: '3d6' }, checks.checks[1]],
    }
    const { user, form } = await editedSkill({
      [`PUT ${base}/checks/skill`]: async () => {
        await gate
        return Response.json(saved)
      },
    })

    await user.click(form.getByRole('button', { name: 'Save check' }))
    await user.click(screen.getByRole('button', { name: 'Edit luck' }))
    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
    release()

    expect(await screen.findByRole('form', { name: 'Edit luck' })).toBeVisible()
    await waitFor(() => {
      expect(dialog()).not.toBeInTheDocument()
    })
  })

  it('opens on the version a save behind the question returned', async () => {
    const saved = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], dice: '3d6' }, checks.checks[1]],
    }
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const luck = recorder(() => Response.json(saved))
    const { user, form } = await editedSkill({
      [`PUT ${base}/checks/skill`]: async () => {
        await gate
        return Response.json(saved)
      },
      [`PUT ${base}/checks/luck`]: luck.handler,
    })

    await user.click(form.getByRole('button', { name: 'Save check' }))
    await user.click(screen.getByRole('button', { name: 'Edit luck' }))
    await screen.findByRole('dialog', { name: 'Leave without saving?' })
    release()
    const opened = within(await screen.findByRole('form', { name: 'Edit luck' }))
    await replaceText(user, opened.getByLabelText('Dice'), '2d6')
    await user.click(opened.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(luck.sent).toHaveLength(1)
    })
    expect(luck.sent[0]).toMatchObject({ expectedVersion: 6 })
  })

  it('opens on the checks current at Leave, not at the click', async () => {
    let reads = 0
    const luck = recorder(() => Response.json({ ...checks, version: 7 }))
    const { user, queryClient } = await editedSkill({
      [`GET ${base}/checks`]: () =>
        Response.json(++reads === 1 ? checks : { ...checks, version: 6 }),
      [`PUT ${base}/checks/luck`]: luck.handler,
    })

    await user.click(screen.getByRole('button', { name: 'Edit luck' }))
    await screen.findByRole('dialog', { name: 'Leave without saving?' })
    await queryClient.refetchQueries({ queryKey: checksKey('my-draft') })
    await waitFor(() => {
      expect(reads).toBe(2)
    })
    await user.click(screen.getByRole('button', { name: 'Leave without saving' }))
    const opened = within(await screen.findByRole('form', { name: 'Edit luck' }))
    await replaceText(user, opened.getByLabelText('Dice'), '2d6')
    await user.click(opened.getByRole('button', { name: 'Save check' }))

    await waitFor(() => {
      expect(luck.sent).toHaveLength(1)
    })
    expect(luck.sent[0]).toMatchObject({ expectedVersion: 6 })
  })

  it('reads a saved form as clean', async () => {
    const saved = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], dice: '3d6' }, checks.checks[1]],
    }
    const { user, form } = await editedSkill({
      [`PUT ${base}/checks/skill`]: json(saved),
    })

    await user.click(form.getByRole('button', { name: 'Save check' }))
    // The row shows the dice the save returned.
    await waitFor(() => {
      const row = screen.getByRole('button', { name: 'Edit skill' }).closest('li') as HTMLElement
      expect(within(row).getByText('3d6')).toBeVisible()
    })
    await user.click(screen.getByRole('button', { name: 'Edit luck' }))

    expect(await screen.findByRole('form', { name: 'Edit luck' })).toBeVisible()
    expect(dialog()).not.toBeInTheDocument()
  })
})
