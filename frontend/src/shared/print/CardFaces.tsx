import type { Deck, DeckEntryCard, SceneCard } from './cards'
import { frameBackground, safeInset } from './layout'

/** How a face is drawn: with or without bleed, and the frame image behind its text, if any. */
export interface FaceStyle {
  bleed: boolean
  /** The URL of the frame image, or null for a plain face. */
  image: string | null
}

/**
 * One face of a card. The text sits in the safe area (the padding); the image, when there is one,
 * is the background of the whole face. `name` is its `data-card`, which the print check reads.
 */
function Face({
  name,
  className,
  face,
  children,
}: {
  name: string
  className: string
  face: FaceStyle
  children: React.ReactNode
}) {
  const frame = frameBackground(face.bleed)
  return (
    <article
      className={`print-card ${className}`}
      data-card={name}
      style={{
        padding: `${String(safeInset(face.bleed))}mm`,
        ...(face.image === null
          ? {}
          : {
              backgroundImage: `url("${face.image}")`,
              backgroundSize: frame.size,
              backgroundPosition: frame.position,
            }),
      }}
    >
      {children}
    </article>
  )
}

export function SceneFront({ card, face }: { card: SceneCard; face: FaceStyle }) {
  return (
    <Face name={`${card.name}, front`} className="print-scene-front" face={face}>
      <h2>{card.name}</h2>
      <p className="print-purpose">{card.purpose}</p>
      {card.tips !== '' && <p className="print-tips">{card.tips}</p>}
    </Face>
  )
}

export function SceneBack({ card, face }: { card: SceneCard; face: FaceStyle }) {
  return (
    <Face name={`${card.name}, back`} className="print-back-text" face={face}>
      {card.back.map((section) => (
        <section key={section.heading}>
          <h3>{section.heading}</h3>
          <ul>
            {section.lines.map((line, at) => (
              <li key={at}>{line}</li>
            ))}
          </ul>
        </section>
      ))}
    </Face>
  )
}

/** `index` is the card's place in its deck, from 1: it names the card for the print check. */
export function DeckFront({
  deck,
  card,
  index,
  face,
}: {
  deck: Deck
  card: DeckEntryCard
  index: number
  face: FaceStyle
}) {
  return (
    <Face name={`${deck.name} ${String(index)}, front`} className="print-deck-front" face={face}>
      {card.range !== undefined && <p className="print-range">{card.range}</p>}
      <p className="print-entry">{card.text}</p>
      {card.starts !== null && <p className="print-label">Starts: {card.starts}</p>}
    </Face>
  )
}

export function DeckBack({ deck, index, face }: { deck: Deck; index: number; face: FaceStyle }) {
  return (
    <Face name={`${deck.name} ${String(index)}, back`} className="print-deck-back" face={face}>
      <p>{deck.name}</p>
    </Face>
  )
}
