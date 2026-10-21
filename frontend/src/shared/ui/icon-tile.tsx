import type { ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'

const sizes = {
  xs: 'size-6 [&>svg]:size-3.5',
  rail: 'size-7 [&>svg]:size-4',
  sm: 'size-8 [&>svg]:size-4',
  md: 'size-9 [&>svg]:size-5',
  lg: 'size-11 [&>svg]:size-6',
} as const

interface IconTileProps {
  /**
   * 24px on rows and menus (`xs`), 28px on the Studio rail (`rail`), 32px on panel headers, 36px on
   * journal entries, 44px on the step card and page titles.
   */
  size?: keyof typeof sizes
  /** The icon, a lucide icon or a `DieShape`. */
  children: ReactNode
  className?: string
}

/**
 * The themed tile behind an icon. Its shape and colors come from the theme's `--tile-*` tokens.
 * Decorative: the tile is hidden from assistive technology, so the text beside it names the thing.
 */
export function IconTile({ size = 'md', children, className }: IconTileProps) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'inline-flex shrink-0 items-center justify-center icon-tile',
        sizes[size],
        className,
      )}
    >
      {children}
    </span>
  )
}
