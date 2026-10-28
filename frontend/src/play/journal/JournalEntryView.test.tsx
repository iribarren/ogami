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
  shopBoughtOnlyEntry,
  shopEntry,
  shopLeftEntry,
  skillCheckEntry,
  pushedGritEntry,
  sneakCheckEntry,
  stormyWeather,
  bestialFailureEntry,
  hungerOnlyFailedEntry,
  messyCriticalEntry,
  pushedBestialEntry,
} from './fixtures'
import { JournalEntryView } from './JournalEntryView'

describe('JournalEntryView', () => {
  it('renders a note keeping its line breaks, with the time it was recorded', () => {
    render(<JournalEntryView entry={gateNote} />)

    const note = screen.getByRole('article', { name: 'Note' })
    const text = within(note).getByText(/The gate is open\./)
    expect(text.textContent).toBe('The gate is open.\nNobody guards it.')
    expect(text).toHaveClass('whitespace-pre-wrap')
    // The theme decides whether the first letter is a drop cap; the text itself is untouched.
    expect(text).toHaveClass('drop-cap')
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

  it('explains the modifier with the value each reference read, and shows the hint', () => {
    render(<JournalEntryView entry={skillCheckEntry} />)

    const entry = screen.getByRole('article', { name: 'Check Skill check' })
    expect(within(entry).getByText('Modifier').nextElementSibling).toHaveTextContent(
      '+6 · Hands (4) + Edge (2)',
    )
    expect(within(entry).getByText('A stat against a Difficulty Value.')).toHaveClass(
      'text-muted-foreground',
    )
  })

  it('keeps the formula operators as written and replaces a repeated reference everywhere', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const entry = {
      ...skillCheckEntry,
      content: {
        ...content,
        modifier: 3,
        modifierFormula: '{hot} - {tracker:chaos} * 2 + {hot}',
        modifierTerms: [
          { reference: '{hot}', label: 'Hot', value: 2 },
          { reference: '{tracker:chaos}', label: 'Chaos factor', value: 1 },
        ],
      },
    }
    render(<JournalEntryView entry={entry} />)

    expect(screen.getByText('Modifier').nextElementSibling).toHaveTextContent(
      '+3 · Hot (2) - Chaos factor (1) * 2 + Hot (2)',
    )
  })

  it('replaces references in a formula spaced and nested however it was written', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const entry = {
      ...skillCheckEntry,
      content: {
        ...content,
        modifier: 5,
        modifierFormula: 'max({hot},{edge})+( {hot}*2 )',
        modifierTerms: [
          { reference: '{hot}', label: 'Hot', value: 2 },
          { reference: '{edge}', label: 'Edge', value: 1 },
        ],
      },
    }
    render(<JournalEntryView entry={entry} />)

    expect(screen.getByText('Modifier').nextElementSibling).toHaveTextContent(
      '+5 · max(Hot (2),Edge (1))+( Hot (2)*2 )',
    )
  })

  it('lists the terms when the formula names a reference no term matches', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const entry = {
      ...skillCheckEntry,
      content: {
        ...content,
        modifier: 3,
        modifierFormula: '{hot} + {edge} + {tracker:chaos}',
        modifierTerms: [
          { reference: '{hot}', label: 'Hot', value: 2 },
          { reference: '{edge}', label: 'Edge', value: 1 },
        ],
      },
    }
    render(<JournalEntryView entry={entry} />)

    const modifier = screen.getByText('Modifier').nextElementSibling
    expect(modifier).toHaveTextContent('+3 · Hot (2), Edge (1)')
    expect(modifier).not.toHaveTextContent('{')
  })

  it('still shows the breakdown when the modifier totals zero', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const entry = {
      ...skillCheckEntry,
      content: {
        ...content,
        modifier: 0,
        modifierFormula: '{hot} - 1',
        modifierTerms: [{ reference: '{hot}', label: 'Hot', value: 1 }],
      },
    }
    render(<JournalEntryView entry={entry} />)

    expect(screen.getByText('Modifier').nextElementSibling).toHaveTextContent('0 · Hot (1) - 1')
  })

  it('renders an entry recorded before the breakdown as it always did', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const legacy = {
      ...skillCheckEntry,
      content: { ...content, hint: null, modifierFormula: null, modifierTerms: [] },
    }
    render(<JournalEntryView entry={legacy} />)

    expect(screen.getByText('Modifier').nextElementSibling).toHaveTextContent(/^\+6$/)
    expect(screen.queryByText('A stat against a Difficulty Value.')).not.toBeInTheDocument()
  })

  it('wraps the dice of a check in a well, and leaves the well out when no dice were rolled', () => {
    const { content } = skillCheckEntry
    if (content.kind !== 'check') {
      throw new Error('The fixture is no check entry.')
    }
    const { unmount } = render(<JournalEntryView entry={skillCheckEntry} />)
    const wrapped = screen.getByRole('article', { name: 'Check Skill check' })
    expect(
      within(wrapped).getByRole('list', { name: '1d10 dice' }).closest('.bg-well'),
    ).not.toBeNull()
    unmount()

    render(<JournalEntryView entry={{ ...skillCheckEntry, content: { ...content, groups: [] } }} />)
    const bare = screen.getByRole('article', { name: 'Check Skill check' })
    expect(within(bare).queryByRole('list')).not.toBeInTheDocument()
    expect(bare.querySelector('.bg-well')).toBeNull()
    // The rest of the check still renders.
    expect(within(bare).getByText('Success')).toBeInTheDocument()
    expect(within(bare).getByText('Dice').nextElementSibling).toHaveTextContent('1d10 = 7')
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

  it('renders a shop visit: bought and sold lines with unit prices, and the money before and after', () => {
    render(<JournalEntryView entry={shopEntry} />)

    const entry = within(screen.getByRole('article', { name: 'Shop Vi' }))
    expect(entry.getByText('Bought')).toBeVisible()
    expect(entry.getByText('2 × Knife @ 10')).toBeVisible()
    expect(entry.getByText('Sold')).toBeVisible()
    expect(entry.getByText('1 × Used pistol @ 25')).toBeVisible()
    expect(entry.getByText('Vi: 100 → 105')).toBeVisible()
  })

  it('leaves out the Sold section of a visit that only bought', () => {
    render(<JournalEntryView entry={shopBoughtOnlyEntry} />)

    const entry = within(screen.getByRole('article', { name: 'Shop Vi' }))
    expect(entry.getByText('Bought')).toBeVisible()
    expect(entry.queryByText('Sold')).not.toBeInTheDocument()
    expect(entry.getByText('Vi: 100 → 80')).toBeVisible()
  })

  it('says nothing was bought or sold when the visit left with an empty transaction', () => {
    render(<JournalEntryView entry={shopLeftEntry} />)

    const entry = within(screen.getByRole('article', { name: 'Shop Vi' }))
    expect(entry.getByText('Left the shop without buying or selling.')).toBeVisible()
    expect(entry.queryByText('Bought')).not.toBeInTheDocument()
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

describe('JournalEntryView icon tile', () => {
  it.each([
    [gateNote, 'Note'],
    [keepThreeRoll, 'Roll 4d6kh3+2'],
    [stormyWeather, 'Oracle Weather'],
    [skillCheckEntry, 'Check Skill check'],
  ])(
    'keeps the article name of %# as "%s" and hides the tile from assistive technology',
    (entry, name) => {
      render(<JournalEntryView entry={entry} />)

      const article = screen.getByRole('article', { name })
      const tile = article.querySelector('.icon-tile')
      expect(tile).toHaveAttribute('aria-hidden', 'true')
      expect(tile?.textContent).toBe('')
      expect(article).toHaveAccessibleName(name)
    },
  )

  it('renders a pool check with every die, the successes told by text, and the size explained', () => {
    render(<JournalEntryView entry={sneakCheckEntry} />)

    const dice = within(screen.getByRole('list', { name: 'Pool dice' })).getAllByRole('listitem')
    expect(dice.map((die) => die.textContent)).toEqual([
      '6 (success)',
      '2 (no success)',
      '5 (success)',
    ])
    expect(screen.getByText('Successes').nextElementSibling).toHaveTextContent('2 successes')
    expect(screen.getByText('Pool').nextElementSibling).toHaveTextContent(
      '3 d6, success on 5+ · Agility (2) + Stealth (1) - Physical (1) = 2 +1 extra',
    )
    expect(screen.queryByText('Dice')).not.toBeInTheDocument()
    expect(screen.queryByText('Modifier')).not.toBeInTheDocument()
  })

  it('shows the situational modifier of a pool check once, in the size explanation', () => {
    render(<JournalEntryView entry={sneakCheckEntry} />)

    expect(screen.queryByText('Situational')).not.toBeInTheDocument()
    expect(screen.getAllByText(/\+1 extra/)).toHaveLength(1)
  })

  it('shows a pushed pool check: the cost, the dice after the push with the re-rolled ones in text, the band change and the sheet changes', () => {
    render(
      <JournalEntryView entry={pushedGritEntry} sheet={[{ key: 'wounds', label: 'Wounds' }]} />,
    )

    expect(screen.getByText('Pushed: Take a Condition')).toBeInTheDocument()
    expect(screen.getByText(/Rolled Weak, pushed to Strong/)).toBeInTheDocument()
    const dice = within(screen.getByRole('list', { name: 'Pool dice' })).getAllByRole('listitem')
    expect(dice.map((die) => die.textContent)).toEqual([
      '6 (success)',
      '5 (success)re-rolled',
      '1 (no success)re-rolled',
    ])
    expect(screen.getByText('Successes').nextElementSibling).toHaveTextContent('2 successes')
    expect(screen.getByText(/Vi: Wounds/)).toHaveTextContent('Vi: Wounds 0 → 1')
  })

  it('tells Hunger dice from regular dice by text and by a flag, never by colour alone', () => {
    render(<JournalEntryView entry={bestialFailureEntry} />)

    const dice = within(screen.getByRole('list', { name: 'Pool dice' })).getAllByRole('listitem')
    expect(dice.map((die) => die.textContent)).toEqual([
      '2 (no success)',
      '3 (no success)',
      '7 (success)',
      '1 (no success)Hunger die',
      '4 (no success)Hunger die',
    ])
    expect(dice.map((die) => die.dataset.group)).toEqual([
      'pool',
      'pool',
      'pool',
      'hunger',
      'hunger',
    ])
  })

  it.each([
    [bestialFailureEntry, 'Bestial failure'],
    [messyCriticalEntry, 'Messy critical'],
  ])('shows the special result of a roll as text', (entry, label) => {
    render(<JournalEntryView entry={entry} />)

    expect(screen.getByText('Special result:').parentElement).toHaveTextContent(label)
  })

  it('shows no special result for a roll without one', () => {
    render(<JournalEntryView entry={hungerOnlyFailedEntry} />)

    expect(screen.queryByText('Special result:')).not.toBeInTheDocument()
  })

  it('shows the special result of the first roll and of the pushed one', () => {
    render(<JournalEntryView entry={pushedBestialEntry} />)

    expect(
      screen.getByText(/Rolled Failure \(bestial failure\), pushed to Win/),
    ).toBeInTheDocument()
    expect(screen.getByText('Special result:').parentElement).toHaveTextContent('Critical')
  })

  it('shows an unpushed pool check without a push line', () => {
    render(<JournalEntryView entry={sneakCheckEntry} />)

    expect(screen.queryByText(/Pushed:/)).not.toBeInTheDocument()
    expect(screen.queryByText('re-rolled')).not.toBeInTheDocument()
  })

  it('says one success in the singular and a fixed count without a formula', () => {
    const { content } = sneakCheckEntry
    if (content.kind !== 'check' || content.pool === null) {
      throw new Error('The fixture is no pool check entry.')
    }
    const entry = {
      ...sneakCheckEntry,
      content: {
        ...content,
        total: 1,
        situational: 0,
        pool: {
          ...content.pool,
          count: 3,
          countFormula: null,
          terms: [],
          successes: 1,
        },
      },
    }
    render(<JournalEntryView entry={entry} />)

    expect(screen.getByText('Successes').nextElementSibling).toHaveTextContent('1 success')
    expect(screen.getByText('Pool').nextElementSibling).toHaveTextContent('3 d6, success on 5+ · 3')
  })
})
