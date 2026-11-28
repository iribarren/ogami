import { cn } from '@/shared/lib/utils'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import type { Mat as MatModel, MatCard, MatPile } from './mat'
import './tableMotion.css'

/**
 * A scene card as it lies on the mat: only what identifies it, its title and Scene Type (D3). The
 * current scene is larger, a hook scene smaller. Reading and playing happen in the scene panel,
 * which the current card opens (see `Mat`). A new current scene turns face up, unless the player
 * prefers reduced motion.
 */
export function SceneCardView({ card, className }: { card: MatCard; className?: string }) {
  return (
    <div
      data-testid="mat-card"
      data-current={card.current ? 'true' : undefined}
      data-hook={card.hook ? 'true' : undefined}
      aria-current={card.current ? 'step' : undefined}
      className={cn(
        'flex shrink-0 flex-col justify-between rounded-lg border bg-card p-3 text-card-foreground shadow-sm',
        'aspect-[63/88]',
        card.current
          ? 'table-flip w-40 ring-2 ring-primary'
          : card.hook
            ? 'w-20 p-2 text-xs'
            : 'w-28 text-sm',
        className,
      )}
    >
      <p className="font-heading leading-tight font-semibold break-words">{card.title}</p>
      <p className="text-xs text-muted-foreground">{card.sceneType ?? (card.hook ? 'Hook' : '')}</p>
    </div>
  )
}

/**
 * An earlier session as a pile at the edge of the mat. Opening it fans its scenes out in a dialog:
 * a button and a dialog, so the keyboard reaches both and Escape closes it.
 */
function PileButton({ pile }: { pile: MatPile }) {
  const label = `Session ${String(pile.sessionNumber)}, ${String(pile.sceneCount)} ${pile.sceneCount === 1 ? 'scene' : 'scenes'}`
  return (
    <Dialog>
      <DialogTrigger asChild>
        <button
          type="button"
          aria-label={`${label}, top card ${pile.top.title}`}
          className="group relative shrink-0 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <span
            aria-hidden="true"
            className="absolute inset-0 translate-x-1 translate-y-1 rounded-lg border bg-card"
          />
          <SceneCardView card={pile.top} className="relative text-left" />
          <span className="absolute -top-2 -right-2 rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground">
            {pile.sceneCount}
          </span>
          <span className="mt-1 block text-center text-xs text-muted-foreground">
            Session {pile.sessionNumber}
          </span>
        </button>
      </DialogTrigger>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Session {pile.sessionNumber}</DialogTitle>
          <DialogDescription>
            {pile.sceneCount} {pile.sceneCount === 1 ? 'scene' : 'scenes'}, in play order.
          </DialogDescription>
        </DialogHeader>
        <ol
          aria-label={`Scenes of session ${String(pile.sessionNumber)}`}
          className="flex flex-wrap gap-3 py-2"
        >
          {pile.cards.map((card) => (
            <li key={card.number}>
              <SceneCardView
                card={card}
                className="motion-safe:transition-transform motion-safe:hover:-translate-y-1"
              />
            </li>
          ))}
        </ol>
      </DialogContent>
    </Dialog>
  )
}

/**
 * The mat: this session's scenes in a row in play order, and the earlier sessions as piles at its
 * edge. A horizontal strip when the table is narrow, a wrapping row when it is wide.
 */
export function Mat({
  mat,
  className,
  onOpenScene,
}: {
  mat: MatModel
  className?: string
  /** Opens the scene panel: the current card, or a "Play" card when there is none, is its button. */
  onOpenScene?: (opener: HTMLElement) => void
}) {
  const empty = mat.row.length === 0 && mat.piles.length === 0
  const noCurrent = !mat.row.some(({ current }) => current)
  return (
    <section
      aria-label="Mat"
      className={cn(
        'flex min-w-0 flex-col gap-4 rounded-2xl border bg-[var(--table-mat,var(--muted))] p-4',
        className,
      )}
    >
      {empty && (
        <p className="m-auto text-center text-sm text-muted-foreground">
          No scene has been played yet. Start one on the Play page, and it lies here.
        </p>
      )}
      {mat.row.length > 0 && (
        <ol
          aria-label="This session"
          className="flex items-end gap-3 overflow-x-auto pb-2 @3xl:flex-wrap @3xl:overflow-visible"
        >
          {mat.row.map((card) => (
            <li key={`${String(card.sessionNumber)}-${String(card.number)}`} className="shrink-0">
              {card.current && onOpenScene !== undefined ? (
                <button
                  type="button"
                  aria-label={`Open scene ${card.title}`}
                  onClick={(event) => {
                    onOpenScene(event.currentTarget)
                  }}
                  className="rounded-lg text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <SceneCardView card={card} />
                </button>
              ) : (
                <SceneCardView card={card} />
              )}
            </li>
          ))}
        </ol>
      )}
      {noCurrent && onOpenScene !== undefined && (
        <button
          type="button"
          aria-label="Play"
          onClick={(event) => {
            onOpenScene(event.currentTarget)
          }}
          className="flex aspect-[63/88] w-40 items-center justify-center rounded-lg border-2 border-dashed bg-card/60 font-heading text-lg font-semibold outline-none hover:bg-card focus-visible:ring-2 focus-visible:ring-ring"
        >
          Play
        </button>
      )}
      {mat.row.length === 0 && mat.piles.length > 0 && (
        <p className="text-sm text-muted-foreground">No session is under way.</p>
      )}
      {mat.piles.length > 0 && (
        <ul
          aria-label="Earlier sessions"
          className="mt-auto flex gap-4 overflow-x-auto border-t pt-4 pr-2 pb-1"
        >
          {mat.piles.map((pile) => (
            <li key={pile.sessionNumber} className="shrink-0">
              <PileButton pile={pile} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
