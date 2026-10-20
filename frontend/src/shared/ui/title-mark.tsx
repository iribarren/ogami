import { markInitial } from '@/shared/theme/markInitial'
import { cn } from '@/shared/lib/utils'

/**
 * The themed mark before a campaign's title: a seal, hexagon, sigil or radar, or nothing (noir).
 * Its shape and colors come from the theme's `--mark-*` tokens; the wax seal shows the first
 * letter of `name`. Decorative: hidden from assistive technology, the title beside it names it.
 */
export function TitleMark({ name, className }: { name: string; className?: string }) {
  return (
    <span aria-hidden="true" className={cn('title-mark', className)}>
      <span className="title-mark-art">
        <span className="title-mark-initial font-heading text-3xl leading-none font-bold">
          {markInitial(name)}
        </span>
      </span>
    </span>
  )
}
