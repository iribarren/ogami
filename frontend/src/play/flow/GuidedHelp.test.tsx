import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { atStep, atTheScenePick, guided, promptStep, quiet, tour } from './fixtures'

const introduction =
  'You play alone. The guide asks one thing at a time.\n\nMandatory steps come first.'

/** `campaign`'s Play screen with an empty journal. */
function renderCampaign(campaign: Campaign) {
  const path = `/api/campaigns/${campaign.id}`
  return renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
  })
}

/** The guided campaign with `introduction` on its Flow. */
function withIntroduction(campaign: Campaign, text: string | null): Campaign {
  return { ...campaign, flows: campaign.flows.map((flow) => ({ ...flow, introduction: text })) }
}

/** The guided campaign before any session: nothing started yet. */
function beforeTheFirstSession(campaign: Campaign): Campaign {
  return { ...campaign, sessions: [], currentSessionNumber: null, currentSceneNumber: null }
}

/** The guided campaign in its current scene, a scene of the Scene Type Tour with `tips`. */
function inATourScene(campaign: Campaign, tips: string | null): Campaign {
  const sceneTypes = campaign.sceneTypes.map((type) =>
    type.key === 'tour' ? { ...type, tips } : type,
  )
  const sessions = campaign.sessions.map((session) => ({
    ...session,
    scenes: session.scenes.map((scene) => ({ ...scene, sceneType: 'tour', sceneTypeName: 'Tour' })),
  }))
  return { ...campaign, sceneTypes, sessions }
}

async function guide() {
  return within(await screen.findByRole('region', { name: 'Guided play' }))
}

/** The introduction's toggle, which sits at the top of the play area. */
const introductionToggle = () => screen.findByRole('button', { name: 'How this flow works' })

describe('the Flow introduction in guided play', () => {
  it('is open before the first session, with its paragraphs', async () => {
    renderCampaign(
      beforeTheFirstSession(withIntroduction(guided(atStep(promptStep)), introduction)),
    )

    expect(await introductionToggle()).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText(/You play alone/)).toHaveTextContent(
      'You play alone. The guide asks one thing at a time. Mandatory steps come first.',
    )
    expect(screen.getByText(/You play alone/).className).toContain('whitespace-pre-line')
  })

  it('sits above where play stands, once, and not in the guide', async () => {
    renderCampaign(
      beforeTheFirstSession(withIntroduction(guided(atStep(promptStep)), introduction)),
    )

    const toggle = await introductionToggle()
    const standing = screen.getByRole('region', { name: 'Where play stands' })
    expect(toggle.compareDocumentPosition(standing) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'How this flow works' })).toHaveLength(1)
    expect(
      (await guide()).queryByRole('button', { name: 'How this flow works' }),
    ).not.toBeInTheDocument()
  })

  it('is closed once a session has started, and opens on request', async () => {
    const user = userEvent.setup()
    renderCampaign(withIntroduction(guided(atStep(promptStep)), introduction))

    const toggle = await introductionToggle()
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText(/You play alone/)).not.toBeInTheDocument()

    await user.click(toggle)

    expect(screen.getByText(/You play alone/)).toBeInTheDocument()
  })

  it('is absent when the Flow has no introduction', async () => {
    renderCampaign(withIntroduction(guided(atStep(promptStep)), null))

    await guide()
    expect(screen.queryByRole('button', { name: 'How this flow works' })).not.toBeInTheDocument()
  })

  it('is absent for a campaign played freely', async () => {
    renderCampaign(lostMineCampaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(screen.queryByRole('button', { name: 'How this flow works' })).not.toBeInTheDocument()
  })

  it('shows once in focus mode too', async () => {
    renderCampaign(withIntroduction(guided(atStep(promptStep), 'focus'), introduction))

    await introductionToggle()
    expect(screen.getAllByRole('button', { name: 'How this flow works' })).toHaveLength(1)
  })
})

describe('Scene Type tips in guided play', () => {
  it('shows the tips of the current scene in a collapsible guide', async () => {
    const user = userEvent.setup()
    renderCampaign(inATourScene(guided(atStep(promptStep)), 'Ask what the contact wants.'))

    const help = await guide()
    const toggle = help.getByRole('button', { name: 'Tips for Tour' })
    expect(help.queryByText('Ask what the contact wants.')).not.toBeInTheDocument()

    await user.click(toggle)

    expect(help.getByText('Ask what the contact wants.')).toBeInTheDocument()
  })

  it('shows no tips outside a scene, as at the scene pick after End scene', async () => {
    renderCampaign(inATourScene(guided(atTheScenePick()), 'Ask what the contact wants.'))

    const help = await guide()
    expect(help.queryByRole('button', { name: /^Tips for/ })).not.toBeInTheDocument()
  })

  it('shows nothing for a Scene Type without tips', async () => {
    renderCampaign(inATourScene(guided(atStep(promptStep)), null))

    const help = await guide()
    expect(help.queryByRole('button', { name: /^Tips for/ })).not.toBeInTheDocument()
  })

  it('shows the tips of each Scene Type on the scene pick card, when it has some', async () => {
    const withTips = { ...tour, tips: 'Keep it short.' }
    renderCampaign(guided(atTheScenePick({ cards: [withTips, quiet] })))

    const pick = within(await screen.findByRole('region', { name: 'Scene pick' }))
    const cards = pick.getAllByRole('listitem')
    expect(cards).toHaveLength(2)
    expect(cards.map((card) => card.textContent.includes('Keep it short.'))).toEqual([true, false])
  })
})
