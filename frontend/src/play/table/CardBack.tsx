import { cn } from '@/shared/lib/utils'

import './tableMotion.css'

/**
 * The back of a card, for the deck: the release's pinned back image when it pinned one, else a
 * plain card in the Theme's colours.
 */
export function CardBack({ image, className }: { image: string | null; className?: string }) {
  return (
    <div
      aria-hidden="true"
      data-testid="card-back"
      className={cn(
        'aspect-[63/88] rounded-lg border bg-primary/20 bg-cover bg-center shadow-[var(--table-card-shadow)] ring-1 ring-primary/30 ring-inset',
        className,
      )}
      style={image === null ? undefined : { backgroundImage: `url("${image}")` }}
    />
  )
}
