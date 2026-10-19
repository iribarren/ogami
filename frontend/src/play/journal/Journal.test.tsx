import { screen } from '@testing-library/react'

import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
import {
  fateDouble,
  fateDoubleEvent,
  flowOutcomeEntry,
  gateNote,
  sanityLossEntry,
  skillCheckEntry,
} from './fixtures'
import { Journal } from './Journal'

function renderJournal(current: { session: number; scene: number } | null, entries = [fateDouble]) {
  return renderWithApi(
    <Journal
      campaignId={lostMineCampaign.id}
      sessions={lostMineCampaign.sessions}
      sceneTypes={lostMineCampaign.sceneTypes}
      tags={lostMineCampaign.tags}
      sheet={lostMineCampaign.pinnedRelease.sheet}
      npcs={lostMineCampaign.npcs}
      characters={lostMineCampaign.characters}
      oracleTables={lostMineCampaign.oracleTables}
      current={current}
      entries={entries}
    />,
  )
}

describe('Journal', () => {
  it('writes a scene heading in normal case, clamped, with its full text as a tooltip', () => {
    renderJournal({ session: 2, scene: 1 })

    const headings = screen.getAllByRole('heading', { level: 4 })
    expect(headings.length).toBeGreaterThan(0)
    for (const heading of headings) {
      expect(heading).toHaveAttribute('title', heading.textContent)
      expect(heading).toHaveClass('line-clamp-2')
      expect(heading).not.toHaveClass('uppercase')
    }
  })

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

  it('shows the event of a fate question under it, not at its place in time, and no roll', () => {
    const between = {
      ...gateNote,
      sessionNumber: 2,
      sceneNumber: 1,
      recordedAt: fateDouble.recordedAt,
    }
    renderJournal({ session: 2, scene: 1 }, [fateDouble, { ...between, id: 'n1' }, fateDoubleEvent])

    const fog = screen.getByText('Dense fog')
    const note = screen.getByText(/The gate is open/)
    expect(fog.compareDocumentPosition(note) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(screen.getAllByText('Dense fog')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Roll Weather' })).not.toBeInTheDocument()
  })

  it('shows an event under its question when they are in different scenes', () => {
    const later = { ...fateDoubleEvent, sceneNumber: 2 }
    renderJournal({ session: 2, scene: 2 }, [fateDouble, later])

    expect(screen.getAllByText('Dense fog')).toHaveLength(1)
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('keeps an event in place when its question is not in the journal', () => {
    renderJournal({ session: 2, scene: 1 }, [fateDoubleEvent])

    expect(screen.getByText('Dense fog')).toBeInTheDocument()
  })

  it('names the Flow step once for the step entry and the sheet change it made', () => {
    const check = { ...skillCheckEntry, flowStep: sanityLossEntry.flowStep }
    renderJournal(null, [check, sanityLossEntry])

    expect(screen.getAllByText('Flow step · Does the sight break you?')).toHaveLength(1)
    expect(screen.getByRole('article', { name: 'Sheet Vi' })).toBeInTheDocument()
  })

  it('names the Flow step once for the step entry and the outcome the Flow added', () => {
    const check = { ...skillCheckEntry, flowStep: flowOutcomeEntry.flowStep }
    renderJournal(null, [check, flowOutcomeEntry])

    expect(screen.getAllByText('Flow step · Does the sight break you?')).toHaveLength(1)
    expect(screen.getByRole('article', { name: 'Flow outcome' })).toBeInTheDocument()
  })
})
