import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { render, screen } from '@testing-library/react'

import { DeckBack, DeckFront, SceneBack, SceneFront } from '@/shared/print/CardFaces'
import { buildCards } from '@/shared/print/cards'
import { readRelease } from '@/shared/print/release'
import { miniRelease } from '@/shared/print/testRelease'

import { TableCards } from './TableCards'

const cards = buildCards(readRelease(miniRelease()), { flow: 'mini' })
const face = { bleed: false, image: '/api/campaigns/c1/card-images/' + 'a'.repeat(64) }
const scene = cards.sceneCards[0]
const deck = cards.decks[0]
const entry = deck?.cards[0]

describe('TableCards', () => {
  it('shows the four print faces of a release unchanged inside its scope', () => {
    if (scene === undefined || deck === undefined || entry === undefined) {
      throw new Error('The mini release has a scene card and a deck.')
    }
    const { container } = render(
      <TableCards>
        <SceneFront card={scene} face={face} />
        <SceneBack card={scene} face={face} />
        <DeckFront deck={deck} card={entry} index={1} face={face} />
        <DeckBack deck={deck} index={1} face={face} />
      </TableCards>,
    )

    expect(container.querySelector('.table-cards')?.querySelectorAll('.print-card')).toHaveLength(4)
    expect(screen.getByRole('heading', { name: scene.name, level: 2 })).toBeInTheDocument()
    expect(container.querySelector('[data-card$="front"]')).toHaveStyle({
      backgroundImage: `url("${face.image}")`,
    })
  })
})

describe('tableCards.css', () => {
  const css = readFileSync(resolve(import.meta.dirname, 'tableCards.css'), 'utf8').replace(
    /\/\*[\s\S]*?\*\//g,
    '',
  )
  const selectors = [...css.matchAll(/([^{}]+)\{/g)]
    .map((match) => (match[1] ?? '').trim())
    .flatMap((header) => header.split(','))
    .map((selector) => selector.trim())

  it('scopes every rule under .table-cards, so nothing else on the page changes', () => {
    expect(selectors.length).toBeGreaterThan(10)
    expect(selectors.filter((selector) => !selector.startsWith('.table-cards'))).toEqual([])
  })

  it('keeps the poker ratio and sizes the face in rem, not millimetres', () => {
    expect(css).toMatch(/aspect-ratio: 63 \/ 88;/)
    expect(css).toMatch(/--card-width: 12rem;/)
    expect(css).not.toMatch(/\dmm\b/)
  })
})
