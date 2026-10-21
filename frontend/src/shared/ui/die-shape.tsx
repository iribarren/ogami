import { cn } from '@/shared/lib/utils'

/** The outline of each die, drawn on a 24-unit square. */
const outlines = {
  4: 'M12 3 21.5 20h-19z',
  6: 'M4 4h16v16H4z',
  8: 'M12 2 22 12 12 22 2 12z',
  10: 'M12 2 21 10 12 22 3 10z',
  12: 'M12 2.5 21.5 9.4 17.9 20.5H6.1L2.5 9.4z',
  20: 'M12 2 21 7v10l-9 5-9-5V7z',
} as const

export type DieSides = keyof typeof outlines

/**
 * A die drawn as an outline in the theme's icon color. Decorative (`aria-hidden`); `data-die`
 * names the shape for tests. A d20 can show its inner triangle, as the Dice panel icon does.
 */
export function DieShape({
  sides,
  inner = false,
  className,
}: {
  sides: DieSides
  inner?: boolean
  className?: string
}) {
  return (
    <svg
      aria-hidden="true"
      data-die={sides}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      strokeLinejoin="round"
      className={cn('size-5 shrink-0 text-icon', className)}
    >
      <path d={outlines[sides]} />
      {inner && sides === 20 && <path d="M12 7 16.5 15h-9z" />}
    </svg>
  )
}
