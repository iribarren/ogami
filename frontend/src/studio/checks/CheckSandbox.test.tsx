import { screen, waitFor, within, type BoundFunctions, type queries } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { checks, draft, sheet, validDraft } from '../fixtures'
import { replaceText, typeText } from '@/test/typeText'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

/** A test roll of `skill` the way the API answers it. */
const rolled = {
  pool: null,
  push: null,
  rolledSuccesses: null,
  dice: {
    expression: '1d10',
    total: 6,
    groups: [{ notation: '1d10', sides: 10, dice: [{ value: 6, kept: true }], subtotal: 6 }],
  },
  modifier: 7,
  situational: 0,
  difficulty: 13,
  total: 13,
  bands: [
    { key: 'fail', label: 'Fail', bound: 12 },
    { key: 'win', label: 'Win', bound: null },
  ],
  band: 'win',
}

/** What the API was asked, in order, with the JSON body of each write and roll. */
function calls() {
  const sent: { call: string; body: Record<string, unknown> }[] = []
  const record =
    (call: string, response: Response | (() => Response)): FakeHandler =>
    async (request) => {
      sent.push({ call, body: (await request.json()) as Record<string, unknown> })
      return typeof response === 'function' ? response() : response
    }
  return { sent, record }
}

async function editCheck(key: string, handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/checks`]: json(checks),
    [`GET ${base}/sheet`]: json(sheet),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Checks' }))
  await user.click(await screen.findByRole('button', { name: `Edit ${key}` }))
  const sandbox = within(await screen.findByRole('region', { name: 'Test roll sandbox' }))
  // The sheet's defaults are known once it is read.
  await waitFor(() => {
    expect(sandbox.getByLabelText('Power')).toHaveAttribute('placeholder', '0')
  })
  return { user, sandbox, form: within(screen.getByRole('form', { name: `Edit ${key}` })) }
}

const roll = (sandbox: BoundFunctions<typeof queries>) =>
  sandbox.getByRole('button', { name: 'Roll the check' })

const rollPath = `POST ${base}/checks/skill/test-rolls`

describe('the test roll sandbox', () => {
  it('is beside a saved check, not a new one', async () => {
    const { user } = await editCheck('skill')
    expect(screen.getByRole('region', { name: 'Test roll sandbox' })).toBeVisible()

    await user.click(screen.getByRole('button', { name: 'New check' }))

    expect(screen.getByRole('form', { name: 'New check' })).toBeVisible()
    expect(screen.queryByRole('region', { name: 'Test roll sandbox' })).toBeNull()
  })

  it('shows the band bounds for the samples, live', async () => {
    const { user, sandbox } = await editCheck('skill')
    const bounds = within(sandbox.getByRole('list', { name: 'Band bounds' }))

    // The difficulty is preset to its default, 13.
    expect(sandbox.getByLabelText('Test difficulty')).toHaveValue('13')
    expect(bounds.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Failup to 12',
      'Winthe rest',
    ])

    await replaceText(user, sandbox.getByLabelText('Test difficulty'), '20')

    expect(bounds.getAllByRole('listitem')[0]).toHaveTextContent('Failup to 19')
  })

  it('follows the bands as they are edited, with a dash for a bound it cannot compute', async () => {
    const { user, sandbox, form } = await editCheck('skill')

    await replaceText(user, form.getByLabelText('Band 1 formula'), '{pow} + {ghost}')

    expect(
      within(sandbox.getByRole('list', { name: 'Band bounds' })).getAllByRole('listitem')[0],
    ).toHaveTextContent('Fail—')
  })

  it('warns when the bounds for the samples do not increase', async () => {
    const { user, sandbox } = await editCheck('luck')
    const bounds = within(sandbox.getByRole('list', { name: 'Band bounds' }))
    // heat starts at 0, below the 40 of the next band.
    expect(bounds.getAllByRole('listitem')[0]).toHaveTextContent('Badup to 0')
    expect(sandbox.queryByRole('status')).toBeNull()

    await typeText(user, sandbox.getByLabelText('Heat (Tracker)'), '50')

    expect(bounds.getAllByRole('listitem')[0]).toHaveTextContent('Badup to 50')
    expect(await sandbox.findByRole('status')).toHaveTextContent(
      "Band 2's bound (40) is not above band 1's (50)",
    )
  })

  it('rolls the saved check with the samples typed, and shows the result', async () => {
    const { sent, record } = calls()
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: record('roll', Response.json(rolled)),
    })

    await user.type(sandbox.getByLabelText('Power'), '7')
    await user.type(sandbox.getByLabelText('Hit points'), '5')
    await user.type(sandbox.getByLabelText('Heat (Tracker)'), '3')
    await replaceText(user, sandbox.getByLabelText('Test difficulty'), '15')
    await replaceText(user, sandbox.getByLabelText('Situational modifier'), '-2')
    await user.click(roll(sandbox))

    const results = within(await sandbox.findByRole('list', { name: 'Test roll results' }))
    expect(sent).toEqual([
      {
        call: 'roll',
        body: {
          sheet: { pow: 7, hp: 5 },
          trackers: { heat: 3 },
          difficulty: 15,
          situational: -2,
        },
      },
    ])
    expect(results.getByText('1d10', { selector: 'span.font-mono' })).toBeVisible()
    expect(results.getByText('= 6')).toBeVisible()
    expect(results.getByText('1d10: 6')).toBeVisible()
    expect(results.getByText('Modifier 7')).toBeVisible()
    expect(results.getByText('Situational 0')).toBeVisible()
    expect(results.getByText('Difficulty 13')).toBeVisible()
    expect(results.getByText('Total 13')).toBeVisible()
    const bands = within(results.getByRole('list', { name: 'Bands' })).getAllByRole('listitem')
    expect(bands[0]).toHaveTextContent('Failup to 12')
    expect(bands[0]).not.toHaveAttribute('aria-current')
    expect(bands[1]).toHaveTextContent('Winthe rest')
    expect(bands[1]).toHaveAttribute('aria-current', 'true')
    expect(bands[1]).toHaveTextContent('Reached')
  })

  it('leaves the inputs nobody typed in out of the request', async () => {
    const { sent, record } = calls()
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: record('roll', Response.json(rolled)),
    })

    await user.click(roll(sandbox))

    await sandbox.findByRole('list', { name: 'Test roll results' })
    expect(sent[0]?.body).toEqual({ sheet: {}, trackers: {}, difficulty: 13, situational: 0 })
  })

  it('saves the unsaved edits first, then rolls the saved check', async () => {
    const { sent, record } = calls()
    const saved = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], label: 'Skill roll' }, checks.checks[1]],
    }
    const { user, sandbox, form } = await editCheck('skill', {
      [`PUT ${base}/checks/skill`]: record('save', Response.json(saved)),
      [rollPath]: record('roll', Response.json(rolled)),
    })
    await replaceText(user, form.getByLabelText('Label'), 'Skill roll')
    expect(sandbox.getByText(/Your unsaved edits are saved first/)).toBeVisible()

    await user.click(roll(sandbox))

    await sandbox.findByRole('list', { name: 'Test roll results' })
    expect(sent.map(({ call }) => call)).toEqual(['save', 'roll'])
    expect(sent[0]?.body).toMatchObject({ expectedVersion: 5, check: { label: 'Skill roll' } })
    // The editor goes on from the save: the form keeps the label and the sandbox its result.
    expect(form.getByLabelText('Label')).toHaveValue('Skill roll')
    expect(sandbox.queryByText(/Your unsaved edits are saved first/)).toBeNull()
  })

  it('rolls under the new key after a rename', async () => {
    const { sent, record } = calls()
    const saved = {
      ...checks,
      version: 6,
      checks: [{ ...checks.checks[0], key: 'craft' }, checks.checks[1]],
    }
    const { user, sandbox, form } = await editCheck('skill', {
      [`PUT ${base}/checks/skill`]: record('save', Response.json(saved)),
      [`POST ${base}/checks/craft/test-rolls`]: record('roll', Response.json(rolled)),
    })
    await replaceText(user, form.getByLabelText('Key'), 'craft')

    await user.click(roll(sandbox))

    await sandbox.findByRole('list', { name: 'Test roll results' })
    expect(sent.map(({ call }) => call)).toEqual(['save', 'roll'])
  })

  it('rolls nothing when the save is refused, and says so', async () => {
    const { sent, record } = calls()
    const { user, sandbox, form } = await editCheck('skill', {
      [`PUT ${base}/checks/skill`]: record(
        'save',
        Response.json({ error: 'checks[0].dice: Invalid dice.' }, { status: 422 }),
      ),
      [rollPath]: record('roll', Response.json(rolled)),
    })
    await replaceText(user, form.getByLabelText('Dice'), 'x')

    await user.click(roll(sandbox))

    expect(await sandbox.findByText('The check was not saved, so it was not rolled.')).toBeVisible()
    expect(form.getByText('Invalid dice.')).toBeVisible()
    expect(sent.map(({ call }) => call)).toEqual(['save'])
  })

  it('rolls nothing when the form is not valid, and shows what is missing', async () => {
    const { sent, record } = calls()
    const { user, sandbox, form } = await editCheck('skill', {
      [rollPath]: record('roll', Response.json(rolled)),
    })
    await user.clear(form.getByLabelText('Dice'))

    await user.click(roll(sandbox))

    expect(await sandbox.findByText('The check was not saved, so it was not rolled.')).toBeVisible()
    expect(form.getByText('Write the dice.')).toBeVisible()
    expect(sent).toEqual([])
  })

  it('rolls nothing on a conflict at the save, and reloads the editor', async () => {
    const { sent, record } = calls()
    const { user, sandbox, form } = await editCheck('skill', {
      [`PUT ${base}/checks/skill`]: record(
        'save',
        Response.json(
          { error: 'GameSystem "my-draft" was changed by another request.' },
          { status: 409 },
        ),
      ),
      [rollPath]: record('roll', Response.json(rolled)),
    })
    await user.type(form.getByLabelText('Hint'), '!')

    await user.click(roll(sandbox))

    expect(await screen.findByText(/was changed by another request/)).toBeVisible()
    expect(sent.map(({ call }) => call)).toEqual(['save'])
  })

  it('shows the refusal of a roll with its path, on the inputs', async () => {
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: json({ error: 'sheet.pow: Must be at most 18.' }, 422),
    })

    await user.click(roll(sandbox))

    expect(await sandbox.findByRole('alert')).toHaveTextContent('sheet.pow: Must be at most 18.')
    expect(sandbox.queryByRole('list', { name: 'Test roll results' })).toBeNull()
  })

  it('says so when the check is gone', async () => {
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: json({ error: 'No check "skill" in this draft.' }, 404),
    })

    await user.click(roll(sandbox))

    expect(await sandbox.findByRole('alert')).toHaveTextContent('No check "skill" in this draft.')
  })

  it.each([
    ['Power', 'abc', 'Must be a whole number.'],
    ['Power', '4.5', 'Must be a whole number.'],
    ['Power', '19', 'Must be between 0 and 18.'],
    ['Hit points', '13', 'Must be between 0 and 12.'],
    ['Heat (Tracker)', '1e3', 'Must be a whole number.'],
    ['Test difficulty', '30', 'Must be between 9 and 29.'],
    ['Situational modifier', '101', 'Must be between -100 and 100.'],
  ])('refuses %s typed as "%s" and sends nothing', async (label, text, message) => {
    const { sent, record } = calls()
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: record('roll', Response.json(rolled)),
    })

    await user.clear(sandbox.getByLabelText(label))
    await user.type(sandbox.getByLabelText(label), text)

    expect(sandbox.getByLabelText(label)).toBeInvalid()
    expect(sandbox.getByText(message)).toBeVisible()
    expect(roll(sandbox)).toBeDisabled()
    expect(sandbox.getByText('Fix the sample values above to roll.')).toBeVisible()
    expect(sent).toEqual([])
  })

  it('keeps the last 5 results, the newest first', async () => {
    let total = 0
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: () => Response.json({ ...rolled, total: ++total }),
    })

    for (let count = 0; count < 6; count++) {
      await user.click(roll(sandbox))
      await waitFor(() => {
        expect(roll(sandbox)).toBeEnabled()
        expect(sandbox.getByText(`Total ${String(count + 1)}`)).toBeVisible()
      })
    }

    const results = within(sandbox.getByRole('list', { name: 'Test roll results' })).getAllByRole(
      'listitem',
      { name: '' },
    )
    expect(
      results
        .filter((item) => item.textContent.includes('Total'))
        .map((item) => /Total (\d+)/.exec(item.textContent)?.[1]),
    ).toEqual(['6', '5', '4', '3', '2'])
  })

  it('rolls a check whose bound has a shape the editor keeps as stored, with a dash for it', async () => {
    const { sent, record } = calls()
    const odd = {
      key: 'odd',
      label: 'Odd',
      dice: '1d6',
      bands: [
        { key: 'low', label: 'Low', upTo: { weird: 1 } },
        { key: 'high', label: 'High' },
      ],
    }
    const { user, sandbox } = await editCheck('odd', {
      [`GET ${base}/checks`]: json({ ...checks, checks: [odd] }),
      [`POST ${base}/checks/odd/test-rolls`]: record('roll', Response.json(rolled)),
    })

    expect(
      within(sandbox.getByRole('list', { name: 'Band bounds' })).getAllByRole('listitem')[0],
    ).toHaveTextContent('Low—')
    await user.click(roll(sandbox))

    await sandbox.findByRole('list', { name: 'Test roll results' })
    expect(sent.map(({ call }) => call)).toEqual(['roll'])
    expect(sandbox.queryByLabelText('Test difficulty')).toBeNull()
  })

  it('shares the sample values with the Sheet tab’s preview', async () => {
    const { user, sandbox } = await editCheck('skill')

    await user.type(sandbox.getByLabelText('Power'), '7')
    await user.type(sandbox.getByLabelText('Heat (Tracker)'), '4')
    await user.click(screen.getByRole('link', { name: 'Sheet' }))
    const preview = within(await screen.findByRole('region', { name: 'Preview' }))

    expect(preview.getByLabelText('Power')).toHaveValue(7)
    expect(preview.getByLabelText('Heat')).toHaveValue(4)
    // The derived field follows the shared sample.
    expect(preview.getByLabelText('Bonus')).toHaveTextContent('8')

    await replaceText(user, preview.getByLabelText('Power'), '9')
    await user.click(screen.getByRole('link', { name: 'Checks' }))
    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))

    expect(
      within(await screen.findByRole('region', { name: 'Test roll sandbox' })).getByLabelText(
        'Power',
      ),
    ).toHaveValue('9')
  })

  it('keeps the max of an open track shared with the Sheet preview when its current value is typed', async () => {
    const openTrack = (field: { key: string }) =>
      field.key === 'hp' ? { ...field, max: null } : field
    const { user } = await editCheck('skill', {
      [`GET ${base}/sheet`]: json({ ...sheet, fields: sheet.fields.map(openTrack) }),
      [`GET ${base}/checks`]: json({ ...checks, fields: checks.fields.map(openTrack) }),
    })
    await user.click(screen.getByRole('link', { name: 'Sheet' }))
    const preview = within(await screen.findByRole('region', { name: 'Preview' }))
    await replaceText(user, preview.getByLabelText('Hit points max'), '8')

    await user.click(screen.getByRole('link', { name: 'Checks' }))
    await user.click(await screen.findByRole('button', { name: 'Edit skill' }))
    const sandbox = within(await screen.findByRole('region', { name: 'Test roll sandbox' }))
    await user.type(sandbox.getByLabelText('Hit points'), '5')
    await user.click(screen.getByRole('link', { name: 'Sheet' }))

    const again = within(await screen.findByRole('region', { name: 'Preview' }))
    expect(again.getByLabelText('Hit points current')).toHaveValue(5)
    expect(again.getByLabelText('Hit points max')).toHaveValue(8)
  })
})

/** A pool check with a push, its roll (one success of four dice) and the pushed roll the API answers. */
const raid = {
  key: 'raid',
  label: 'Raid',
  pool: { count: { formula: '{pow} + 1' }, sides: 6, successAt: 5 },
  push: { label: 'Take a Condition' },
  bands: [
    { key: 'fail', label: 'Fail', upTo: 0 },
    { key: 'partial', label: 'Partial', upTo: 1 },
    { key: 'win', label: 'Win' },
  ],
}

const poolBands = [
  { key: 'fail', label: 'Fail', bound: 0 },
  { key: 'partial', label: 'Partial', bound: 1 },
  { key: 'win', label: 'Win', bound: null },
]

const die = (face: number, success: boolean, rerolled = false) => ({ face, success, rerolled })

const poolRolled = {
  dice: null,
  pool: {
    size: 4,
    sides: 6,
    successAt: 5,
    count: 4,
    countFormula: '{pow} + 1',
    dice: [die(1, false), die(5, true), die(2, false), die(3, false)],
    successes: 1,
  },
  push: { label: 'Take a Condition' },
  rolledSuccesses: null,
  modifier: 0,
  situational: 0,
  difficulty: null,
  total: 1,
  bands: poolBands,
  band: 'partial',
}

const poolPushed = {
  ...poolRolled,
  pool: {
    ...poolRolled.pool,
    dice: [die(6, true, true), die(5, true), die(2, false), die(5, true, true)],
    successes: 3,
  },
  rolledSuccesses: 1,
  total: 3,
  band: 'win',
}

const raidHandlers = (extra: Record<string, FakeHandler> = {}) => ({
  [`GET ${base}/checks`]: json({ ...checks, checks: [raid] }),
  ...extra,
})
const pushPath = `POST ${base}/checks/raid/test-rolls/push`
const raidRoll = `POST ${base}/checks/raid/test-rolls`

describe('the test roll sandbox of a pool check', () => {
  it('tells the pool the samples give, live, from the count formula', async () => {
    const { user, sandbox } = await editCheck('raid', raidHandlers())

    // pow starts at 0: the formula gives 1.
    expect(sandbox.getByText('The pool rolls 1 die of 6 sides.')).toBeVisible()

    await user.type(sandbox.getByLabelText('Power'), '3')
    expect(sandbox.getByText('The pool rolls 4 dice of 6 sides.')).toBeVisible()

    await replaceText(user, sandbox.getByLabelText('Situational modifier'), '2')
    expect(sandbox.getByText('The pool rolls 6 dice of 6 sides.')).toBeVisible()
  })

  it('shows every die with its success told by text, the successes and the band', async () => {
    const { user, sandbox } = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: json(poolRolled),
      }),
    )

    await user.click(roll(sandbox))

    const results = within(await sandbox.findByRole('list', { name: 'Test roll results' }))
    const dice = within(results.getByRole('list', { name: 'Pool dice' })).getAllByRole('listitem')
    expect(dice.map((item) => item.textContent)).toEqual([
      '1 (no success)',
      '5 (success)',
      '2 (no success)',
      '3 (no success)',
    ])
    expect(results.getByText('1 success')).toBeVisible()
    expect(results.getByText('4 d6, success on 5+')).toBeVisible()
    expect(results.getByText('{pow} + 1 = 4')).toBeVisible()
    expect(results.getByText('Partial', { selector: 'p' })).toBeVisible()
    expect(results.queryByText(/^Modifier/)).toBeNull()
    const bands = within(results.getByRole('list', { name: 'Bands' })).getAllByRole('listitem')
    expect(bands[1]).toHaveAttribute('aria-current', 'true')
    expect(results.getByText('Take a Condition', { exact: false })).toBeVisible()
  })

  it('pushes the roll with the same samples and its faces, and shows the pushed roll in the history', async () => {
    const { sent, record } = calls()
    const { user, sandbox } = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: record('roll', Response.json(poolRolled)),
        [pushPath]: record('push', Response.json(poolPushed)),
      }),
    )
    await user.type(sandbox.getByLabelText('Power'), '3')
    await user.click(roll(sandbox))

    await user.click(await sandbox.findByRole('button', { name: 'Push the roll' }))

    expect(await sandbox.findByText('Rolled Partial, pushed to Win')).toBeVisible()
    expect(sent.map(({ call }) => call)).toEqual(['roll', 'push'])
    expect(sent[1]?.body).toEqual({ ...sent[0]?.body, faces: [1, 5, 2, 3] })
    const [pushed, rolled] = sandbox.getByRole('list', { name: 'Test roll results' }).children
    expect(pushed).toHaveTextContent('Pushed: Take a Condition')
    expect(pushed).toHaveTextContent('The cost is not applied in a test roll.')
    expect(pushed).toHaveTextContent('3 successes')
    expect(pushed).toHaveTextContent('6 (success)re-rolled')
    expect(pushed).toHaveTextContent('2 (no success)')
    // Only the first roll can still be pushed.
    expect(sandbox.getAllByRole('button', { name: 'Push the roll' })).toHaveLength(1)
    // The roll stays in the history as it was.
    expect(rolled).toHaveTextContent('1 success')
  })

  it('says still when the push keeps the band', async () => {
    const { user, sandbox } = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: json(poolRolled),
        [pushPath]: json({ ...poolPushed, rolledSuccesses: 1, total: 1, band: 'partial' }),
      }),
    )
    await user.click(roll(sandbox))

    await user.click(await sandbox.findByRole('button', { name: 'Push the roll' }))

    expect(await sandbox.findByText('Rolled Partial, still Partial')).toBeVisible()
  })

  it('offers no push without a failed die, without a declared push, or for dice', async () => {
    const allWon = {
      ...poolRolled,
      pool: { ...poolRolled.pool, dice: [die(5, true), die(6, true)], successes: 2 },
    }
    const { user, sandbox } = await editCheck('raid', raidHandlers({ [raidRoll]: json(allWon) }))
    await user.click(roll(sandbox))
    await sandbox.findByRole('list', { name: 'Pool dice' })
    expect(sandbox.queryByRole('button', { name: 'Push the roll' })).toBeNull()

    const none = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: json({ ...poolRolled, push: null }),
      }),
    )
    await none.user.click(roll(none.sandbox))
    await none.sandbox.findAllByRole('list', { name: 'Pool dice' })
    expect(none.sandbox.queryByRole('button', { name: 'Push the roll' })).toBeNull()
  })

  it('offers no push on the result of a dice check', async () => {
    const { user, sandbox } = await editCheck('skill', {
      [rollPath]: json({ ...rolled, pool: null, push: null, rolledSuccesses: null }),
    })

    await user.click(roll(sandbox))

    await sandbox.findByRole('list', { name: 'Test roll results' })
    expect(sandbox.queryByRole('button', { name: 'Push the roll' })).toBeNull()
    expect(sandbox.queryByRole('list', { name: 'Pool dice' })).toBeNull()
  })

  it('shows a refused push in the alert and keeps the roll', async () => {
    const { user, sandbox } = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: json(poolRolled),
        [pushPath]: json({ error: 'This check declares no push.' }, 409),
      }),
    )
    await user.click(roll(sandbox))

    await user.click(await sandbox.findByRole('button', { name: 'Push the roll' }))

    expect(await sandbox.findByRole('alert')).toHaveTextContent('This check declares no push.')
    expect(sandbox.getByRole('button', { name: 'Push the roll' })).toBeEnabled()
    expect(sandbox.getByRole('list', { name: 'Test roll results' }).children).toHaveLength(1)
  })

  it('disables the push while it is sent', async () => {
    let answer: (response: Response) => void = () => undefined
    const { user, sandbox } = await editCheck(
      'raid',
      raidHandlers({
        [raidRoll]: json(poolRolled),
        [pushPath]: () =>
          new Promise<Response>((resolve) => {
            answer = resolve
          }),
      }),
    )
    await user.click(roll(sandbox))

    await user.click(await sandbox.findByRole('button', { name: 'Push the roll' }))

    await waitFor(() => {
      expect(sandbox.getByRole('button', { name: 'Push the roll' })).toBeDisabled()
    })
    expect(roll(sandbox)).toBeDisabled()
    answer(Response.json(poolPushed))
    expect(await sandbox.findByText('Rolled Partial, pushed to Win')).toBeVisible()
    expect(roll(sandbox)).toBeEnabled()
  })
})
