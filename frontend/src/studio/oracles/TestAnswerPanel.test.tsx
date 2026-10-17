import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { fateAnswer, oracles, oraclesWithChaos, narrativeScenes } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'
const put = `PUT ${base}/likelihood-oracles/fate`
const ask = (key = 'fate') => `POST ${base}/likelihood-oracles/${key}/test-answers`

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(
  handlers: Record<string, FakeHandler> = {},
  stored: unknown = oraclesWithChaos,
) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft/likelihood-oracles/fate', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/oracles`]: json(stored),
    [`GET ${base}/narrative`]: json(narrativeScenes),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Likelihood oracle' })
  return { user, ...rendered }
}

async function replace(user: ReturnType<typeof userEvent.setup>, label: string, text: string) {
  const field = screen.getByLabelText(label)
  await user.clear(field)
  if (text !== '') {
    await user.type(field, text)
  }
}

const askButton = () => screen.getByRole('button', { name: 'Ask' })
const [fate] = oracles.likelihood as [(typeof oracles.likelihood)[number]]
const noEvent = { answer: { ...fateAnswer.answer, randomEvent: null }, randomEvent: null }

describe('the test answer panel', () => {
  it('asks the saved oracle without saving, and shows the answer and the random event roll', async () => {
    let saved = false
    const bodies: unknown[] = []
    const { user } = await openEditor({
      [put]: () => {
        saved = true
        return Response.json(oraclesWithChaos)
      },
      [ask()]: async (request) => {
        bodies.push(await request.json())
        return Response.json(fateAnswer)
      },
    })

    await user.selectOptions(screen.getByLabelText('Likelihood'), 'likely')
    await replace(user, 'Chaos factor', '7')
    await user.click(askButton())

    const results = within(await screen.findByRole('list', { name: 'Test answer results' }))
    expect(results.getByText('Exceptional yes')).toBeInTheDocument()
    expect(results.getByText('rolled 33 on 1d100 against 75')).toBeInTheDocument()
    expect(results.getByText('Likely, chaos factor 7')).toBeInTheDocument()
    expect(results.getByText(/Random event/)).toHaveTextContent('Random event: storm-kind')
    expect(within(results.getByRole('list', { name: 'Steps' })).getByText('Hail')).toBeVisible()
    expect(bodies).toEqual([{ likelihood: 'likely', chaosFactor: 7 }])
    expect(saved).toBe(false)
  })

  it('sends no chaos factor when the field is empty', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor({
      [ask()]: async (request) => {
        bodies.push(await request.json())
        return Response.json(noEvent)
      },
    })

    await user.click(askButton())
    await screen.findByRole('list', { name: 'Test answer results' })

    expect(bodies).toEqual([{ likelihood: 'even' }])
    expect(screen.queryByText(/Random event:/)).not.toBeInTheDocument()
  })

  it('has no chaos factor field when the oracle has no chaos', async () => {
    await openEditor({}, oracles)

    expect(screen.queryByLabelText('Chaos factor')).not.toBeInTheDocument()
  })

  it.each([['10'], ['0'], ['4.5'], ['x']])(
    'does not ask a chaos factor of %s, outside the whole numbers 1 to 9',
    async (factor) => {
      const { user } = await openEditor()

      await replace(user, 'Chaos factor', factor)

      expect(askButton()).toBeDisabled()
      expect(screen.getByText('The chaos factor is a whole number from 1 to 9.')).toBeVisible()
    },
  )

  it('keeps the last five answers, the newest first', async () => {
    let asked = 0
    const { user } = await openEditor({
      [ask()]: () =>
        Response.json({
          answer: { ...fateAnswer.answer, roll: ++asked, randomEvent: null },
          randomEvent: null,
        }),
    })

    for (let count = 0; count < 6; count += 1) {
      await user.click(askButton())
      await screen.findByText(`rolled ${String(count + 1)} on 1d100 against 75`)
    }

    const results = screen.getByRole('list', { name: 'Test answer results' })
    expect(within(results).getAllByRole('listitem')).toHaveLength(5)
    expect(results.firstElementChild).toHaveTextContent('rolled 6 on')
    expect(screen.queryByText('rolled 1 on 1d100 against 75')).not.toBeInTheDocument()
  })

  it('saves the unsaved edits first, then asks', async () => {
    const calls: string[] = []
    const { user } = await openEditor({
      [put]: () => {
        calls.push('save')
        return Response.json({ ...oraclesWithChaos, version: 8 })
      },
      [ask()]: () => {
        calls.push('ask')
        return Response.json(noEvent)
      },
    })

    await replace(user, 'Name', 'Luck')
    expect(screen.getByText(/unsaved edits are saved first/)).toBeInTheDocument()
    await user.click(askButton())

    expect(await screen.findByRole('list', { name: 'Test answer results' })).toBeInTheDocument()
    expect(calls).toEqual(['save', 'ask'])
  })

  it('does not ask when the save fails', async () => {
    let asked = false
    const { user } = await openEditor({
      [put]: json({ error: 'Oracle key "x y" is invalid.' }, 422),
      [ask()]: () => {
        asked = true
        return Response.json(noEvent)
      },
    })

    await replace(user, 'Key', 'x y')
    await user.click(askButton())

    expect(await screen.findByRole('alert')).toHaveTextContent('Oracle key "x y" is invalid.')
    expect(asked).toBe(false)
    expect(screen.queryByRole('list', { name: 'Test answer results' })).not.toBeInTheDocument()
  })

  it('cannot ask while the form is invalid', async () => {
    const { user } = await openEditor()

    await replace(user, 'Sides', 'many')

    expect(askButton()).toBeDisabled()
  })

  it('renames through an answer: saves, goes to the new key, asks it and keeps the answer', async () => {
    const asked: string[] = []
    const renamed = {
      ...oraclesWithChaos,
      version: 8,
      likelihood: [{ ...fate, definition: { ...fate.definition, key: 'luck' } }],
    }
    const { user, router } = await openEditor({
      [put]: json(renamed),
      [ask('luck')]: () => {
        asked.push('luck')
        return Response.json(noEvent)
      },
    })

    await replace(user, 'Key', 'luck')
    await user.click(askButton())

    expect(await screen.findByRole('list', { name: 'Test answer results' })).toBeInTheDocument()
    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/likelihood-oracles/luck',
      )
    })
    expect(asked).toEqual(['luck'])
    expect(await screen.findByLabelText('Key')).toHaveValue('luck')
    expect(screen.getByRole('list', { name: 'Test answer results' })).toBeInTheDocument()
  })

  it('shows why a question was refused', async () => {
    const { user } = await openEditor({
      [ask()]: json({ error: 'Chaos factor 12 is outside 1 to 9.' }, 422),
    })

    await user.click(askButton())

    const panel = within(screen.getByRole('region', { name: 'Test answer' }))
    expect(await panel.findByRole('alert')).toHaveTextContent('Chaos factor 12 is outside 1 to 9.')
  })
})
