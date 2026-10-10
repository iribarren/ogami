import { render, screen, within } from '@testing-library/react'

import {
  chosenWay,
  gateNote,
  keepThreeRoll,
  lockedDoorAnswer,
  plainAnswer,
  stormyWeather,
} from './fixtures'
import { JournalEntryView } from './JournalEntryView'

describe('JournalEntryView', () => {
  it('renders a note keeping its line breaks, with the time it was recorded', () => {
    render(<JournalEntryView entry={gateNote} />)

    const note = screen.getByRole('article', { name: 'Note' })
    const text = within(note).getByText(/The gate is open\./)
    expect(text.textContent).toBe('The gate is open.\nNobody guards it.')
    expect(text).toHaveClass('whitespace-pre-wrap')
    expect(note.querySelector('time')).toHaveAttribute('datetime', gateNote.recordedAt)
  })

  it('renders a roll as expression = total, with every die and the dropped ones marked', () => {
    render(<JournalEntryView entry={keepThreeRoll} />)

    const roll = screen.getByRole('article', { name: 'Roll 4d6kh3+2' })
    expect(roll).toHaveTextContent('4d6kh3+2=total17')
    expect(within(roll).getByText('17')).toHaveAccessibleDescription('total')
    const dice = within(within(roll).getByRole('list', { name: '4d6kh3 dice' })).getAllByRole(
      'listitem',
    )
    expect(dice.map((die) => die.textContent)).toEqual(['6', '1 (dropped)', '5', '4'])
    expect(dice[1]).toHaveClass('line-through')
    expect(dice[0]).not.toHaveClass('line-through')
    expect(within(roll).getByText('= 15')).toBeInTheDocument()
  })

  it('renders an oracle table result step by step, indenting nested rolls', () => {
    render(<JournalEntryView entry={stormyWeather} />)

    const oracle = screen.getByRole('article', { name: 'Oracle Weather' })
    const steps = within(
      within(oracle).getByRole('list', { name: 'Oracle table steps' }),
    ).getAllByRole('listitem')
    expect(steps).toHaveLength(2)
    expect(steps[0]).toHaveTextContent('Weather1d6= 6A storm')
    expect(steps[1]).toHaveTextContent('Nested roll:Storm kind1d4= 2Hail')
    expect(steps[0]).not.toHaveTextContent('Nested')
    expect(steps[1]?.style.paddingLeft).toBe('1.25rem')
  })

  it('renders a likelihood answer with its question, roll against target and chaos factor', () => {
    render(<JournalEntryView entry={lockedDoorAnswer} />)

    const oracle = screen.getByRole('article', { name: 'Oracle Fate question' })
    expect(within(oracle).getByText('“Is the door locked?”')).toBeInTheDocument()
    expect(within(oracle).getByText('Exceptional yes')).toBeInTheDocument()
    expect(oracle).toHaveTextContent('Roll4 vs 75 on d100')
    expect(oracle).toHaveTextContent('LikelihoodLikely')
    expect(oracle).toHaveTextContent('Chaos factor7')
  })

  it('leaves out the question and chaos factor of a likelihood answer without them', () => {
    render(<JournalEntryView entry={plainAnswer} />)

    const oracle = screen.getByRole('article', { name: 'Oracle Yes/no question' })
    expect(within(oracle).getByText('No')).toBeInTheDocument()
    expect(oracle).toHaveTextContent('Roll81 vs 50 on d100')
    expect(oracle).not.toHaveTextContent('Chaos factor')
    expect(oracle).not.toHaveTextContent('“')
  })

  it('renders a choice as its question and the option chosen', () => {
    render(<JournalEntryView entry={chosenWay} />)

    const choice = screen.getByRole('article', { name: 'Choice Which way?' })
    expect(choice).toHaveTextContent('Which way? → Go right')
  })
})
