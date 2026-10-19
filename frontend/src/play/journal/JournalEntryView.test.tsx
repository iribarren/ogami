import { render, screen, within } from '@testing-library/react'

import {
  chosenWay,
  flowOutcomeEntry,
  fateDouble,
  gateNote,
  keepThreeRoll,
  lockedDoorAnswer,
  luckCheckEntry,
  pickedNothing,
  pickedTwo,
  plainAnswer,
  sanityLossEntry,
  skillCheckEntry,
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

  it('renders a pick as the names picked, with the tags shown by their label', () => {
    render(<JournalEntryView entry={pickedTwo} tags={[{ key: 'leader', label: 'Leader' }]} />)

    const pick = screen.getByRole('article', { name: 'Pick NPC' })
    expect(pick).toHaveTextContent('Picked NPCs: Mira Vane, Brann')
    expect(pick).toHaveTextContent('Tagged: Leader')
  })

  it('shows a tag key as it is when the release no longer names it', () => {
    render(<JournalEntryView entry={pickedTwo} />)

    expect(screen.getByRole('article', { name: 'Pick NPC' })).toHaveTextContent('Tagged: leader')
  })

  it('renders a pick that found nothing as No match, without tags', () => {
    render(<JournalEntryView entry={pickedNothing} />)

    const pick = screen.getByRole('article', { name: 'Pick Thread' })
    expect(pick).toHaveTextContent('Picked Thread: No match')
    expect(pick).not.toHaveTextContent('Tagged')
  })

  it('marks a fate question that answered with a random event', () => {
    render(<JournalEntryView entry={fateDouble} />)

    const entry = screen.getByRole('article', { name: 'Oracle Fate question' })
    expect(within(entry).getByText('Random event')).toBeInTheDocument()
    expect(within(entry).getByText('Yes')).toBeInTheDocument()
  })

  it('shows no random event badge on a plain answer', () => {
    render(<JournalEntryView entry={lockedDoorAnswer} />)

    expect(screen.queryByText('Random event')).not.toBeInTheDocument()
  })
})

describe('JournalEntryView check entries', () => {
  it('renders the band, the dice and how the total adds up against the difficulty', () => {
    render(<JournalEntryView entry={skillCheckEntry} />)

    const entry = screen.getByRole('article', { name: 'Check Skill check' })
    expect(within(entry).getByText('Vi · Skill check')).toBeInTheDocument()
    expect(within(entry).getByText('Success')).toHaveClass('text-2xl')
    expect(within(entry).getByRole('list', { name: '1d10 dice' })).toHaveTextContent('7')
    const details = within(entry)
    expect(details.getByText('Dice').nextElementSibling).toHaveTextContent('1d10 = 7')
    expect(details.getByText('Modifier').nextElementSibling).toHaveTextContent('+6')
    expect(details.getByText('Situational').nextElementSibling).toHaveTextContent('+2')
    expect(details.getByText('Total').nextElementSibling).toHaveTextContent('15 vs 13')
  })

  it('leaves out a zero modifier, a zero situational modifier and a missing difficulty', () => {
    render(<JournalEntryView entry={luckCheckEntry} />)

    const entry = screen.getByRole('article', { name: 'Check Luck' })
    expect(within(entry).getByText('Bad luck')).toBeInTheDocument()
    expect(within(entry).queryByText('Modifier')).not.toBeInTheDocument()
    expect(within(entry).queryByText('Situational')).not.toBeInTheDocument()
    expect(within(entry).getByText('Total').nextElementSibling).toHaveTextContent(/^12$/)
  })

  it('renders a sheet change one line per field, with the sheet labels and the dice rolled', () => {
    render(
      <JournalEntryView entry={sanityLossEntry} sheet={[{ key: 'sanity', label: 'Sanity' }]} />,
    )

    const entry = screen.getByRole('article', { name: 'Sheet Vi' })
    expect(
      within(entry)
        .getAllByRole('listitem')
        .map((line) => line.textContent),
    ).toEqual([
      'Vi: Sanity 65 → 61 (1d6: 4)',
      // A field the sheet does not name shows its key.
      'Vi: hp 12 → 11',
    ])
  })

  it('renders what the Flow decided as compact muted lines in normal case', () => {
    render(<JournalEntryView entry={flowOutcomeEntry} />)

    const entry = screen.getByRole('article', { name: 'Flow outcome' })
    const lines = within(entry).getAllByRole('listitem')
    expect(lines.map((line) => line.textContent)).toEqual([
      'Strong hit',
      'Threat 3 → 4',
      'Scene Type: Chase → Combat',
      'Next scene: Briefing',
      'The phase ends after this scene',
    ])
    expect(within(entry).getByRole('list')).toHaveClass('text-muted-foreground')
    expect(within(entry).getByRole('list')).not.toHaveClass('uppercase')
  })

  it('shows a penalty with its minus sign', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    render(
      <JournalEntryView entry={{ ...skillCheckEntry, content: { ...content, situational: -3 } }} />,
    )

    expect(screen.getByText('Situational').nextElementSibling).toHaveTextContent('-3')
  })
})
