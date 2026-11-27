import { useMemo } from 'react'

import { DeckBack, DeckFront, SceneBack, SceneFront } from '@/shared/print/CardFaces'
import { buildCards, CARD_CAPACITY, type Cards } from '@/shared/print/cards'
import { pageLayout } from '@/shared/print/layout'
import { DuplexSheets, PrintCards } from '@/shared/print/PokerSheets'

import { resolvePrintOptions, type PrintOptions, type PrintSearch } from './printOptions'
import { useReleaseContent, type PinnedImages } from './useReleaseContent'

/** A release version in the address: a positive whole number. */
const VERSION = /^[1-9]\d{0,8}$/

function Problems({
  problems,
  flows = [],
}: {
  problems: string[]
  flows?: { key: string; name: string }[]
}) {
  return (
    <div role="alert" className="space-y-2 rounded-md border border-destructive p-4 text-sm">
      {problems.map((problem) => (
        <p key={problem}>{problem}</p>
      ))}
      {flows.length > 0 && (
        <>
          <p>The flows of this release:</p>
          <ul className="list-disc pl-5">
            {flows.map((flow) => (
              <li key={flow.key}>
                {flow.name} (<code>{flow.key}</code>)
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

function Sheets({
  cards,
  options,
  images,
  note,
}: {
  cards: Cards
  options: PrintOptions
  images: PinnedImages
  note: string
}) {
  const layout = pageLayout(options.paper, options.bleed)
  const text = { bleed: options.bleed, image: images.cardFront }
  const back = { bleed: options.bleed, image: images.cardBack }
  return (
    <PrintCards>
      <p className="print-note mb-4 text-sm text-muted-foreground">{note}</p>
      <DuplexSheets
        layout={layout}
        items={cards.sceneCards}
        front={(card) => <SceneFront card={card} face={text} />}
        back={(card) => <SceneBack card={card} face={text} />}
      />
      {cards.decks.map((deck) => (
        <DuplexSheets
          key={deck.key}
          layout={layout}
          items={deck.cards.map((card, at) => ({ card, index: at + 1 }))}
          front={({ card, index }) => (
            <DeckFront deck={deck} card={card} index={index} face={text} />
          )}
          back={({ index }) => <DeckBack deck={deck} index={index} face={back} />}
        />
      ))}
    </PrintCards>
  )
}

/**
 * The cards of one flow of a published release, ready for the browser's print dialog. Everything
 * that can go wrong (an unknown release, content the reader refuses, a bad option, a flow the
 * builder cannot build) is a message on the page, never a blank sheet or a crash.
 */
export function ReleasePrintPage({
  gameSystemKey,
  version,
  search,
}: {
  gameSystemKey: string
  version: string
  search: PrintSearch
}) {
  const validVersion = VERSION.test(version)
  const content = useReleaseContent(gameSystemKey, validVersion ? Number(version) : 0, validVersion)
  const built = useMemo(() => {
    if (content.data === undefined) {
      return undefined
    }
    const resolved = resolvePrintOptions(search, content.data.release)
    if (!resolved.ok) {
      return resolved
    }
    const { options } = resolved
    try {
      const cards = buildCards(content.data.release, {
        flow: options.flow,
        deckTables: options.decks,
        capacity: options.bleed ? CARD_CAPACITY.bleed : CARD_CAPACITY.poker,
      })
      return { ok: true as const, options, cards }
    } catch (error) {
      return {
        ok: false as const,
        problems: [error instanceof Error ? error.message : 'The cards could not be built.'],
        flows: [],
      }
    }
  }, [content.data, search])

  if (!validVersion) {
    return <Problems problems={[`"${version}" is not a release version: use a whole number.`]} />
  }
  if (content.isPending) {
    return <p role="status">Loading the release…</p>
  }
  if (content.isError) {
    return <Problems problems={[content.error.message]} />
  }
  if (built === undefined) {
    return null
  }
  if (!built.ok) {
    return <Problems problems={built.problems} flows={built.flows} />
  }
  const { options } = built
  const note = `${content.data.release.systemName}, release ${version}: print on ${options.paper.toUpperCase()}${options.bleed ? ' with bleed' : ''} from the browser, with margins set to none and background graphics on.`
  return <Sheets cards={built.cards} options={options} images={content.data.images} note={note} />
}
