import { screen } from '@testing-library/react'

import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
import { fateDouble } from './fixtures'
import { Journal } from './Journal'

function renderJournal(current: { session: number; scene: number } | null) {
  return renderWithApi(
    <Journal
      campaignId={lostMineCampaign.id}
      sessions={lostMineCampaign.sessions}
      sceneTypes={lostMineCampaign.sceneTypes}
      tags={lostMineCampaign.tags}
      npcs={lostMineCampaign.npcs}
      characters={lostMineCampaign.characters}
      oracleTables={lostMineCampaign.oracleTables}
      current={current}
      entries={[fateDouble]}
    />,
  )
}

describe('Journal', () => {
  it('flags a random event and offers its table in the current scene', () => {
    renderJournal({ session: 2, scene: 1 })

    expect(screen.getByText('Random event')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Roll Weather' })).toBeInTheDocument()
  })

  it('only flags it in a scene that is no longer the current one', () => {
    renderJournal({ session: 2, scene: 2 })

    expect(screen.getByText('Random event')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Roll Weather' })).not.toBeInTheDocument()
  })

  it('flags it without an offer when no scene is under way', () => {
    renderJournal(null)

    expect(screen.queryByRole('button', { name: 'Roll Weather' })).not.toBeInTheDocument()
  })
})
