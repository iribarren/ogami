import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import {
  draft,
  flowIssues,
  flowWarnings,
  narrativeFlows,
  narrativeScenes,
  narrativeV1,
  validDraft,
} from '../../fixtures'
import { validationKey } from '../../useGameSystems'
import { narrativeKey } from '../useGameSystemNarrative'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openTab(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/narrative`]: json(narrativeFlows),
    ...handlers,
  })
  await user.click(await screen.findByRole('radio', { name: 'Flows' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))
const row = async (name: string) => (await screen.findByText(name)).closest('li') as HTMLElement

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the Flows tab', () => {
  it('lists the flows in stored order with the default flag, the phases and where each is used', async () => {
    await openTab()

    const heist = await row('Heist')
    const side = await row('Side job')
    expect(heist).toHaveTextContent('heist')
    expect(within(heist).getByText('Default')).toBeInTheDocument()
    expect(heist).toHaveTextContent('2 phases')
    expect(heist).toHaveTextContent('Not used')
    expect(within(side).queryByText('Default')).not.toBeInTheDocument()
    expect(side).toHaveTextContent('1 phase')
    expect(side).toHaveTextContent('Used by sceneTypes[0].play[1]')
    const keys = within(screen.getByRole('region', { name: 'Flows' }))
      .getAllByRole('listitem')
      .map((item) => item.querySelector('span.font-mono')?.textContent)
    expect(keys).toEqual(['heist', 'side-job', 'odd-flow'])
  })

  it('links the rows it can edit to the flow editor, and not the one it cannot read', async () => {
    await openTab()

    const heist = await row('Heist')
    expect(within(heist).getByRole('link', { name: 'Heist' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/flows/heist',
    )
    expect(within(await row('Odd flow')).queryByRole('link')).toBeNull()
  })

  it('lists a flow of a shape it cannot read read-only, with a reason, and still lets it go', async () => {
    await openTab()

    const odd = await row('Odd flow')
    expect(odd).toHaveTextContent("Can't be edited here")
    expect(odd).toHaveTextContent('stays as it is until it is deleted')
    expect(within(odd).queryByText(/phase/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete odd-flow' })).toBeEnabled()
  })

  it('has an empty state', async () => {
    await openTab({ [`GET ${base}/narrative`]: json({ ...narrativeFlows, flows: [] }) })

    expect(await screen.findByText('This draft has no flows yet.')).toBeInTheDocument()
  })

  it('says a schema version 1 draft has no narrative sections and offers no create button', async () => {
    await openTab({ [`GET ${base}/narrative`]: json(narrativeV1) })

    expect(await screen.findByText(/have no narrative sections/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New flow' })).not.toBeInTheDocument()
  })

  it('shows why the narrative could not be loaded', async () => {
    await openTab({ [`GET ${base}/narrative`]: json({ error: 'No GameSystem' }, 404) })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This GameSystem does not exist, or it is not one of yours.',
    )
  })
})

describe('the issues of each flow', () => {
  it('counts the error and the warnings of each flow by its stored position', async () => {
    await openTab({
      [`GET ${base}/validation`]: json({
        ...flowIssues,
        warnings: ['flows[1].phases[0]: odd', 'flows[2]: odd too'],
      }),
    })

    expect(await within(await row('Side job')).findByText('2 issues')).toBeInTheDocument()
    expect(within(await row('Odd flow')).getByText('1 issue')).toBeInTheDocument()
    expect(within(await row('Heist')).queryByText(/issue/)).not.toBeInTheDocument()
  })

  it('counts the warnings of a valid draft', async () => {
    await openTab({ [`GET ${base}/validation`]: json(flowWarnings) })

    expect(await within(await row('Heist')).findByText('2 issues')).toBeInTheDocument()
    expect(within(await row('Side job')).queryByText(/issue/)).not.toBeInTheDocument()
  })

  it('shows no count while the validation is being refreshed after a delete, then counts the right rows', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const pending = new Promise<void>((resolve) => {
      release = resolve
    })
    const { user } = await openTab({
      [`GET ${base}/validation`]: async () => {
        if (++reads === 1) {
          return Response.json({ ...flowWarnings, warnings: ['flows[0]: stale'] })
        }
        await pending
        return Response.json({ ...flowWarnings, warnings: ['flows[1]: fresh'] })
      },
      [`DELETE ${base}/narrative/flows/heist`]: json({
        ...narrativeFlows,
        version: 6,
        flows: narrativeFlows.flows.filter(({ key }) => key !== 'heist'),
      }),
    })

    expect(await within(await row('Heist')).findByText('1 issue')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete heist' }))
    await user.click(screen.getByRole('button', { name: 'Delete the flow' }))

    await waitFor(() => {
      expect(screen.queryByText('Heist')).not.toBeInTheDocument()
    })
    // The stored position 0 is now the Side job: the old count must not land on it.
    expect(screen.queryByText(/issue/)).not.toBeInTheDocument()
    release()
    expect(await within(await row('Odd flow')).findByText('1 issue')).toBeInTheDocument()
    expect(within(await row('Side job')).queryByText(/issue/)).not.toBeInTheDocument()
  })

  it('keeps the counts while the validation is refreshed in the background and the narrative has not changed', async () => {
    let reads = 0
    let release: () => void = () => undefined
    const pending = new Promise<void>((resolve) => {
      release = resolve
    })
    const { queryClient } = await openTab({
      [`GET ${base}/validation`]: async () => {
        if (++reads > 1) {
          await pending
        }
        return Response.json({ ...flowWarnings, warnings: ['flows[0]: odd'] })
      },
    })

    expect(await within(await row('Heist')).findByText('1 issue')).toBeInTheDocument()
    void queryClient.refetchQueries({ queryKey: validationKey('my-draft') })
    await waitFor(() => {
      expect(reads).toBe(2)
    })
    expect(within(await row('Heist')).getByText('1 issue')).toBeInTheDocument()
    release()
  })

  it('shows no count once the narrative has changed until the validation is read again', async () => {
    let narrative: unknown = narrativeFlows
    const { queryClient } = await openTab({
      [`GET ${base}/narrative`]: () => Response.json(narrative),
      [`GET ${base}/validation`]: json({ ...flowWarnings, warnings: ['flows[0]: odd'] }),
    })

    expect(await within(await row('Heist')).findByText('1 issue')).toBeInTheDocument()
    narrative = {
      ...narrativeFlows,
      version: 6,
      flows: narrativeFlows.flows.filter(({ key }) => key !== 'heist'),
    }
    await queryClient.refetchQueries({ queryKey: narrativeKey('my-draft') })
    await waitFor(() => {
      expect(screen.queryByText('Heist')).not.toBeInTheDocument()
    })
    expect(screen.queryByText(/issue/)).not.toBeInTheDocument()
    await queryClient.refetchQueries({ queryKey: validationKey('my-draft') })
    expect(await within(await row('Side job')).findByText('1 issue')).toBeInTheDocument()
  })

  it('shows no count for a valid draft', async () => {
    await openTab()

    await row('Heist')
    expect(screen.queryByText(/issue/)).not.toBeInTheDocument()
  })

  it('shows no count, and no error, when the validation cannot be read', async () => {
    await openTab({ [`GET ${base}/validation`]: json({ error: 'Down' }, 500) })

    await row('Heist')
    await waitFor(() => {
      expect(screen.queryByText(/issue/)).not.toBeInTheDocument()
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('creating a flow', () => {
  it('sends the smallest flow the publisher accepts, starting with the chosen Scene Type', async () => {
    const sent: unknown[] = []
    const created = {
      key: 'chase',
      name: 'Chase',
      defaultView: 'focus',
      oracles: [],
      trackers: [],
      phases: [
        {
          key: 'main',
          name: 'Main',
          mode: 'once',
          selection: { rule: 'sequence', sceneTypes: ['quiet'] },
        },
      ],
    }
    const { user, router } = await openTab({
      [`POST ${base}/narrative/flows`]: async (request) => {
        sent.push(await request.json())
        return Response.json(
          {
            ...narrativeFlows,
            version: 6,
            flows: [...narrativeFlows.flows, { key: 'chase', definition: created, usedBy: [] }],
          },
          { status: 201 },
        )
      },
    })

    await user.click(await screen.findByRole('button', { name: 'New flow' }))
    expect(inDialog().getByLabelText('First Scene Type')).toHaveValue('ambush')
    await user.type(inDialog().getByLabelText('Key'), ' chase ')
    await user.type(inDialog().getByLabelText('Name'), 'Chase')
    await user.selectOptions(inDialog().getByLabelText('First Scene Type'), 'Quiet')
    await user.click(inDialog().getByRole('button', { name: 'Create flow' }))

    // The editor of the new flow opens.
    expect(await screen.findByRole('form', { name: 'Flow' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/flows/chase')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(sent).toEqual([{ expectedVersion: 5, item: created }])
  })

  it('cannot start without a Scene Type, and says so', async () => {
    await openTab({
      [`GET ${base}/narrative`]: json({ ...narrativeFlows, sceneTypes: [] }),
    })

    const button = await screen.findByRole('button', { name: 'New flow' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription('Add a Scene Type first.')
  })

  it.each([
    [
      409,
      { error: 'The draft already has a flow "heist".' },
      'The draft already has a flow "heist".',
    ],
    [422, { error: 'flows[3].name: must not be blank' }, 'flows[3].name: must not be blank'],
    [404, { error: 'No GameSystem' }, 'No GameSystem'],
  ])('shows the %s answer in the dialog and stays there', async (status, body, message) => {
    const { user } = await openTab({ [`POST ${base}/narrative/flows`]: json(body, status) })

    await user.click(await screen.findByRole('button', { name: 'New flow' }))
    await user.type(inDialog().getByLabelText('Key'), 'heist')
    await user.click(inDialog().getByRole('button', { name: 'Create flow' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(message)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('offers to reload when the draft changed since it was read, and takes the new list', async () => {
    let reads = 0
    const sent: number[] = []
    const { user } = await openTab({
      [`GET ${base}/narrative`]: () =>
        Response.json(
          ++reads === 1
            ? narrativeFlows
            : { ...narrativeFlows, version: 6, sceneTypes: narrativeScenes.sceneTypes.slice(0, 1) },
        ),
      [`POST ${base}/narrative/flows`]: async (request) => {
        sent.push(((await request.json()) as { expectedVersion: number }).expectedVersion)
        return sent.length === 1
          ? Response.json(changed, { status: 409 })
          : Response.json(narrativeFlows, { status: 201 })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'New flow' }))
    await user.type(inDialog().getByLabelText('Key'), 'chase')
    await user.click(inDialog().getByRole('button', { name: 'Create flow' }))
    expect(await inDialog().findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Reload the latest version' })).toBeNull()
    })
    // The reloaded Scene Types are what the select offers, and the next create sends their version.
    expect(inDialog().getByLabelText('First Scene Type')).toHaveValue('ambush')
    expect(
      within(inDialog().getByLabelText('First Scene Type')).getAllByRole('option'),
    ).toHaveLength(1)
    await user.click(inDialog().getByRole('button', { name: 'Create flow' }))
    await waitFor(() => {
      expect(sent).toEqual([5, 6])
    })
  })
})

describe('deleting a flow', () => {
  it('disables deleting one the draft uses, and says why', async () => {
    await openTab()

    const button = await screen.findByRole('button', { name: 'Delete side-job' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription('Used by sceneTypes[0].play[1]')
    expect(screen.getByRole('button', { name: 'Delete heist' })).toBeEnabled()
  })

  it('deletes an unused one with the version it read', async () => {
    let deleted: URL | undefined
    const { user } = await openTab({
      [`DELETE ${base}/narrative/flows/heist`]: (request) => {
        deleted = new URL(request.url)
        return Response.json({
          ...narrativeFlows,
          version: 6,
          flows: narrativeFlows.flows.filter(({ key }) => key !== 'heist'),
        })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete heist' }))
    await user.click(screen.getByRole('button', { name: 'Delete the flow' }))

    await waitFor(() => {
      expect(screen.queryByText('Heist')).not.toBeInTheDocument()
    })
    expect(deleted?.searchParams.get('expectedVersion')).toBe('5')
  })

  it.each([
    [404, { error: 'The draft has no flow "heist".' }, 'The draft has no flow "heist".'],
    [409, changed, 'was changed by another request'],
    [
      422,
      { error: 'The flow "heist" is used.', paths: ['sceneTypes[0].play[1]'] },
      'The flow "heist" is used. Used by sceneTypes[0].play[1].',
    ],
  ])('shows the %s answer in the dialog', async (status, body, message) => {
    const { user } = await openTab({
      [`DELETE ${base}/narrative/flows/heist`]: json(body, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete heist' }))
    await user.click(screen.getByRole('button', { name: 'Delete the flow' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
  })
})
