import { render, screen } from '@testing-library/react'

import { persuadeCheck } from '../campaigns/fixtures'
import { nightJobCampaign, viCharacter } from '../characters/fixtures'
import { bestialFailureEntry } from '../journal/fixtures'
import type { JournalEntry } from '../journal/useJournal'
import { PushOffer } from './PushOffer'

const campaign = { ...nightJobCampaign, checks: [persuadeCheck] }

/** The Hunger roll "Persuade" with the content changed. */
function rolled(over: Partial<Extract<JournalEntry['content'], { kind: 'check' }>>): JournalEntry {
  const { content } = bestialFailureEntry
  if (content.kind !== 'check') {
    throw new Error('The fixture is no check roll.')
  }
  return { ...bestialFailureEntry, content: { ...content, ...over } }
}

function offer(entry: JournalEntry) {
  render(
    <PushOffer
      campaign={campaign}
      character={viCharacter}
      entry={entry}
      pending={false}
      onPush={() => undefined}
      onKeep={() => undefined}
    />,
  )
}

describe('the push offer', () => {
  it('says what a push can still change and risks on a roll that already succeeded', () => {
    offer(rolled({ difficulty: 3, total: 3, special: null }))

    const note = screen.getByText(/This roll already succeeded/)
    expect(note).toHaveTextContent('cannot lower the result')
    expect(note).toHaveTextContent('higher band or a critical')
    expect(note).toHaveTextContent('cost is paid whatever the dice show')
    expect(note).toHaveTextContent('pushed once')
    expect(screen.getByRole('button', { name: 'Push' })).toBeEnabled()
  })

  it('keeps the offer of a failed roll as it was', () => {
    offer(rolled({ difficulty: 5, total: 1 }))

    expect(screen.queryByText(/already succeeded/)).not.toBeInTheDocument()
    expect(screen.getByText('Re-roll up to 3 failed dice.')).toBeInTheDocument()
  })
})
