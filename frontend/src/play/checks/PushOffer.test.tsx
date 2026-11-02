import { render, screen } from '@testing-library/react'

import { edgeCheck, persuadeCheck } from '../campaigns/fixtures'
import { nightJobCampaign, viCharacter } from '../characters/fixtures'
import { bestialFailureEntry, burnableRollEntry } from '../journal/fixtures'
import type { JournalEntry } from '../journal/useJournal'
import { PushOffer } from './PushOffer'

const edgeCampaign = { ...nightJobCampaign, checks: [edgeCheck] }
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
  it('says what a push can change and risks on a band check, which has no difficulty', () => {
    offer(rolled({ difficulty: null, total: 3, special: null }))

    const note = screen.getByText(/A push re-rolls only the dice that failed/)
    expect(note).toHaveTextContent('cannot lower the result')
    expect(note).toHaveTextContent('higher band or a critical')
    expect(note).toHaveTextContent('cost is paid whatever the dice show')
    expect(note).toHaveTextContent('pushed once')
    expect(note).toHaveTextContent('Keep takes the result as it is, at no cost')
    expect(note).not.toHaveTextContent('already succeeded')
    expect(screen.getByRole('button', { name: 'Push' })).toBeEnabled()
  })

  it('says it on a failed roll too, with the Re-roll line', () => {
    offer(rolled({ difficulty: 5, total: 1 }))

    expect(screen.getByText(/A push re-rolls only the dice that failed/)).toBeInTheDocument()
    expect(screen.getByText('Re-roll up to 3 failed dice.')).toBeInTheDocument()
  })

  it('names the messy critical only when the roll has Hunger dice', () => {
    offer(rolled({}))
    expect(screen.getByText(/A push re-rolls/)).toHaveTextContent(
      'A new critical that pairs with a Hunger die is a messy critical.',
    )
  })

  it('leaves the Hunger sentence out of a roll without Hunger dice', () => {
    const { content } = rolled({})
    if (content.kind !== 'check' || content.pool === null) {
      throw new Error('The fixture is no pool roll.')
    }
    const { pool } = content
    offer(
      rolled({
        pool: { ...pool, dice: pool.dice.filter(({ group }) => group !== 'hunger') },
      }),
    )

    expect(screen.getByText(/A push re-rolls/)).not.toHaveTextContent('Hunger')
  })

  it('does not show it on a burn, which re-rolls nothing', () => {
    render(
      <PushOffer
        campaign={edgeCampaign}
        character={undefined}
        entry={burnableRollEntry}
        pending={false}
        onPush={() => undefined}
        onKeep={() => undefined}
      />,
    )

    expect(screen.queryByText(/A push re-rolls/)).not.toBeInTheDocument()
  })
})
