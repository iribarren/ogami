import { cleanup, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { chosenWay } from '../journal/fixtures'
import {
  atStep,
  atTheScenePick,
  choiceStep,
  fixedOracleStep,
  guided,
  inOpenPlay,
  oracleStep,
  promptStep,
  rollStep,
  tableStep,
} from './fixtures'
import type { FlowRun } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`

/**
 * The Play screen of a campaign along a Flow. `commands` answer the FlowRun requests by their
 * last path segment; `sent` collects each request body (undefined without one).
 */
function renderGuided(
  campaign: Campaign,
  commands: Record<string, FakeHandler> = {},
  journal: unknown[] = [],
) {
  const sent: Record<string, unknown> = {}
  const server = { campaign }
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(server.campaign),
    [`GET ${path}/journal`]: () => Response.json(journal),
  }
  for (const [name, handler] of Object.entries(commands)) {
    handlers[`POST ${path}/${name}`] = async (request) => {
      const text = await request.clone().text()
      sent[name] = text === '' ? undefined : JSON.parse(text)
      return handler(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${campaign.id}`, handlers), sent, server }
}

const answering =
  (next: Campaign): FakeHandler =>
  () =>
    Response.json(next)

async function panel() {
  return within(await screen.findByRole('region', { name: 'Guided play' }))
}

describe('the guided panel', () => {
  it('is absent for a campaign played freely', async () => {
    renderGuided(lostMineCampaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(screen.queryByRole('region', { name: 'Guided play' })).not.toBeInTheDocument()
  })

  it('shows the step with its kind, prompt and tip, the progress and the next step', async () => {
    renderGuided(guided(atStep(promptStep)))

    const guide = await panel()
    expect(guide.getByLabelText('Progress')).toHaveTextContent(
      'Act 1 › The start › Tour › Setup · step 1 of 3',
    )
    const card = within(guide.getByRole('region', { name: 'Current step: Who walks in?' }))
    expect(card.getByText('Prompt')).toBeInTheDocument()
    expect(card.getByText('Describe the first person.')).toBeInTheDocument()
    expect(card.getByText('Tip: Keep it short.')).toBeInTheDocument()
    expect(guide.getByText('Setup: Who walks in?')).toBeInTheDocument()
  })

  it('answers a prompt with the text typed, and shows the campaign it answers with', async () => {
    const user = userEvent.setup()
    const next = guided(atStep(rollStep))
    const { sent } = renderGuided(guided(atStep(promptStep)), {
      'flow-run/answer': answering(next),
    })

    const guide = await panel()
    expect(guide.getByRole('button', { name: 'Record' })).toBeDisabled()
    await user.type(guide.getByLabelText('Your answer'), 'A tall stranger')
    await user.click(guide.getByRole('button', { name: 'Record' }))

    expect(await guide.findByRole('button', { name: /^Roll/ })).toBeInTheDocument()
    expect(sent['flow-run/answer']).toEqual({ stepKey: 'intro', text: 'A tall stranger' })
  })

  it('asks an oracle at the likelihood the player picks', async () => {
    const user = userEvent.setup()
    const { sent } = renderGuided(guided(atStep(oracleStep)), {
      'flow-run/answer': answering(guided(atStep(rollStep))),
    })

    const guide = await panel()
    expect(guide.getByText('Fate question')).toBeInTheDocument()
    await user.selectOptions(guide.getByLabelText('Likelihood'), 'Likely')
    await user.click(guide.getByRole('button', { name: 'Ask' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'ask', likelihood: 'likely' })
    })
  })

  it('asks an oracle at the likelihood the step fixes without offering the choice', async () => {
    const user = userEvent.setup()
    const { sent } = renderGuided(guided(atStep(fixedOracleStep)), {
      'flow-run/answer': answering(guided(atStep(rollStep))),
    })

    const guide = await panel()
    expect(guide.queryByLabelText('Likelihood')).not.toBeInTheDocument()
    expect(guide.getByText('Likelihood: Even odds')).toBeInTheDocument()
    await user.click(guide.getByRole('button', { name: 'Ask' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'ask-even' })
    })
  })

  it.each([
    ['a table', tableStep, 'Roll on Weather', { stepKey: 'omen' }],
    ['a roll', rollStep, 'Roll 1d6', { stepKey: 'dice' }],
    ['a choice', choiceStep, 'Go right', { stepKey: 'fork', optionKey: 'right' }],
  ])('completes %s with one click', async (_kind, current, button, body) => {
    const user = userEvent.setup()
    const { sent } = renderGuided(guided(atStep(current)), {
      'flow-run/answer': answering(guided(atStep(promptStep))),
    })

    await user.click(await (await panel()).findByRole('button', { name: button }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual(body)
    })
  })

  it('skips a step unless it is mandatory', async () => {
    const user = userEvent.setup()
    const { sent } = renderGuided(guided(atStep(promptStep)), {
      'flow-run/skip': answering(guided(atStep(rollStep))),
    })

    await user.click(await (await panel()).findByRole('button', { name: 'Skip' }))

    await waitFor(() => {
      expect(sent['flow-run/skip']).toEqual({ stepKey: 'intro' })
    })
  })

  it('offers no skip for a mandatory step', async () => {
    renderGuided(guided(atStep({ ...promptStep, mandatory: true })))

    expect((await panel()).queryByRole('button', { name: 'Skip' })).not.toBeInTheDocument()
  })

  it('shows why an answer was refused and keeps the step', async () => {
    const user = userEvent.setup()
    renderGuided(guided(atStep(rollStep)), {
      'flow-run/answer': () =>
        Response.json({ error: 'The FlowRun is at another step.' }, { status: 409 }),
    })

    const guide = await panel()
    await user.click(await guide.findByRole('button', { name: /^Roll/ }))

    expect(await guide.findByRole('alert')).toHaveTextContent('The FlowRun is at another step.')
    expect(guide.getByRole('region', { name: 'Current step: Push your luck' })).toBeInTheDocument()
  })

  it('refetches the campaign when a command is refused, so the panel shows the server step', async () => {
    const user = userEvent.setup()
    const { server } = renderGuided(guided(atStep(rollStep)), {
      'flow-run/answer': () =>
        Response.json({ error: 'The FlowRun is at another step.' }, { status: 409 }),
    })

    const guide = await panel()
    // Another tab moved the FlowRun on: the next read answers with the new step.
    server.campaign = guided(atStep(promptStep))
    await user.click(await guide.findByRole('button', { name: /^Roll/ }))

    expect(
      await guide.findByRole('region', { name: 'Current step: Who walks in?' }),
    ).toBeInTheDocument()
    expect(guide.queryByRole('region', { name: 'Current step: Push your luck' })).toBeNull()
  })

  it('starts the same step in the next scene of a loop with an empty answer', async () => {
    const user = userEvent.setup()
    const first = guided(atStep(promptStep))
    const { server } = renderGuided(first, {
      'flow-run/answer': answering({ ...first, currentSceneNumber: 2 }),
    })

    const guide = await panel()
    await user.type(guide.getByLabelText('Your answer'), 'A tall stranger')
    server.campaign = { ...first, currentSceneNumber: 2 }
    await user.click(guide.getByRole('button', { name: 'Record' }))

    await waitFor(() => {
      expect(guide.getByLabelText('Your answer')).toHaveValue('')
    })
  })

  it('shows a generic message when a command fails unexpectedly', async () => {
    const user = userEvent.setup()
    renderGuided(guided(atStep(rollStep)), {
      'flow-run/answer': () => new Response('Oops', { status: 500 }),
    })

    const guide = await panel()
    await user.click(await guide.findByRole('button', { name: /^Roll/ }))

    expect(await guide.findByRole('alert')).toHaveTextContent(
      'Answering the step failed with HTTP 500.',
    )
  })

  describe('at the scene pick', () => {
    it('offers a card per Scene Type when the player chooses', async () => {
      const user = userEvent.setup()
      const { sent } = renderGuided(guided(atTheScenePick()), {
        'flow-run/pick': answering(guided(atStep(promptStep))),
      })

      const pick = within(await (await panel()).findByRole('region', { name: 'Scene pick' }))
      expect(pick.getAllByRole('listitem')).toHaveLength(2)
      expect(pick.getByText('Rest and take stock.')).toBeInTheDocument()
      await user.click(pick.getByRole('button', { name: 'Play Quiet' }))

      await waitFor(() => {
        expect(sent['flow-run/pick']).toEqual({ sceneType: 'quiet' })
      })
    })

    it.each([
      ['a sequence sets', { rule: 'sequence' as const }, false],
      ['is forced', { forced: true }, true],
    ])('offers the one Scene Type that %s', async (_why, overrides, forced) => {
      renderGuided(
        guided(
          atTheScenePick({
            cards: [{ key: 'tour', name: 'Tour', purpose: 'A tour.', oracles: [] }],
            ...overrides,
          }),
        ),
      )

      const pick = within(await (await panel()).findByRole('region', { name: 'Scene pick' }))
      expect(pick.getAllByRole('listitem')).toHaveLength(1)
      expect(pick.queryByText('Forced for this scene') !== null).toBe(forced)
    })

    it('rolls the Scene Type on the oracle table', async () => {
      const user = userEvent.setup()
      const { sent } = renderGuided(
        guided(atTheScenePick({ rule: 'oracle', cards: [], table: 'weather' })),
        {
          'flow-run/pick/roll': answering(guided(atStep(promptStep))),
        },
      )

      await user.click(await (await panel()).findByRole('button', { name: 'Roll on Weather' }))

      await waitFor(() => {
        expect(sent).toHaveProperty(['flow-run/pick/roll'])
      })
    })
  })

  describe('the controls', () => {
    const visible = async (flowRun: FlowRun) => {
      renderGuided(guided(flowRun))
      const guide = await panel()
      return ['End scene', 'Move on', 'End session', 'Pause guidance', 'Resume guidance'].filter(
        (name) => guide.queryByRole('button', { name }) !== null,
      )
    }

    it('end the scene in open play, and pause', async () => {
      expect(await visible(inOpenPlay)).toEqual(['End scene', 'Pause guidance'])
    })

    it('end the session only at the scene pick, where the server allows it', async () => {
      expect(await visible(atTheScenePick())).toEqual(['End session', 'Pause guidance'])
    })

    it('only pause while a step waits', async () => {
      expect(await visible(atStep(promptStep))).toEqual(['Pause guidance'])
    })

    it('move on from the phase only when the server allows it, naming the phase by its key', async () => {
      const user = userEvent.setup()
      expect(await visible(atTheScenePick({}))).not.toContain('Move on')
      cleanup()
      const { sent } = renderGuided(guided({ ...atTheScenePick(), canMoveOn: true }), {
        'flow-run/move-on': () =>
          Response.json({ error: 'Phase "start" plays once.' }, { status: 409 }),
      })

      const guide = await panel()
      await user.click(await guide.findByRole('button', { name: 'Move on' }))

      expect(await guide.findByRole('alert')).toHaveTextContent('Phase "start" plays once.')
      expect(sent['flow-run/move-on']).toEqual({ phase: 'start' })
    })

    it('end the scene by its number, then show the campaign it answers with', async () => {
      const user = userEvent.setup()
      const { sent } = renderGuided(guided(inOpenPlay), {
        'flow-run/end-scene': answering(guided(atTheScenePick())),
      })

      await user.click(await (await panel()).findByRole('button', { name: 'End scene' }))

      expect(await screen.findByRole('region', { name: 'Scene pick' })).toBeInTheDocument()
      expect(sent['flow-run/end-scene']).toEqual({ sceneNumber: 1 })
    })

    it('end the session, then show the refetched campaign', async () => {
      const user = userEvent.setup()
      const { sent, server } = renderGuided(guided(atTheScenePick()), {
        'sessions/current/end': () => {
          server.campaign = guided(atStep(promptStep, { step: null, waitsForSession: true }))
          return Response.json({
            number: 2,
            startedAt: '2026-10-06T12:00:00+00:00',
            scenes: [],
            endedAt: '2026-10-06T13:00:00+00:00',
          })
        },
      })

      await user.click(await (await panel()).findByRole('button', { name: 'End session' }))

      expect(await screen.findByText('Start a session to go on.')).toBeInTheDocument()
      expect(sent).toHaveProperty(['sessions/current/end'])
    })

    it('pause, leaving the free-play tools, and resume', async () => {
      const user = userEvent.setup()
      const paused = guided({ ...atStep(promptStep), status: 'paused' })
      const { sent } = renderGuided(guided(atStep(promptStep)), {
        'flow-run/pause': answering(paused),
        'flow-run/resume': answering(guided(atStep(promptStep))),
      })

      const guide = await panel()
      await user.click(await guide.findByRole('button', { name: 'Pause guidance' }))
      expect(await guide.findByText(/Guidance is paused/)).toBeInTheDocument()
      expect(guide.queryByRole('region', { name: /Current step/ })).not.toBeInTheDocument()
      expect(guide.getByText('Setup: Who walks in?')).toBeInTheDocument()
      await user.click(guide.getByRole('button', { name: 'Resume guidance' }))

      expect(
        await guide.findByRole('region', { name: 'Current step: Who walks in?' }),
      ).toBeInTheDocument()
      expect(Object.keys(sent)).toEqual(['flow-run/pause', 'flow-run/resume'])
    })

    it('hint at starting a session when none is under way', async () => {
      renderGuided(guided(atStep(promptStep, { step: null, waitsForSession: true })))

      expect(await (await panel()).findByText('Start a session to go on.')).toBeInTheDocument()
    })

    it('say the Flow is complete', async () => {
      renderGuided(
        guided(
          atStep(promptStep, {
            status: 'completed',
            step: null,
            progress: null,
            next: 'Flow complete',
          }),
        ),
      )

      const guide = await panel()
      expect(await guide.findByText('Flow complete. Play on freely.')).toBeInTheDocument()
      expect(guide.queryByRole('button')).not.toBeInTheDocument()
    })
  })

  it('heads a hook Scene apart from the scenes, which name their Scene Type, and captions flow entries', async () => {
    const scene = {
      startedAt: '2026-10-05T12:10:00+00:00',
      kind: 'scene' as const,
      sceneType: null,
      sceneTypeName: null,
      hook: null,
    }
    renderGuided(
      {
        ...guided(atStep(promptStep)),
        sessions: [
          {
            number: 1,
            startedAt: '2026-10-05T12:05:00+00:00',
            endedAt: null,
            scenes: [
              {
                ...scene,
                number: 1,
                kind: 'hook',
                hook: 'sessionOpening',
                title: 'Session 1 begins',
              },
              {
                ...scene,
                number: 2,
                sceneType: 'tour',
                sceneTypeName: 'Tour',
                title: 'Went Go right',
              },
            ],
          },
        ],
      },
      {},
      [{ ...chosenWay, sceneNumber: 2 }],
    )

    const journal = await screen.findByRole('region', { name: 'Journal' })
    expect(
      await within(journal).findByRole('region', { name: 'Session opening — Session 1 begins' }),
    ).toBeInTheDocument()
    const sceneRegion = within(journal).getByRole('region', {
      name: 'Scene 2 · Tour — Went Go right',
    })
    expect(within(sceneRegion).getByText('Flow step · Which way?')).toBeInTheDocument()
  })
})
