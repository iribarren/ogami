import { cn } from '@/shared/lib/utils'

/**
 * A decorative divider whose ornament (diamond, double rule, neon bars, ticks…) comes from the
 * theme's `--ornament-*` tokens. Hidden from assistive technology; use it where a visual break
 * is wanted and no heading or separator role is.
 */
export function OrnamentDivider({ className }: { className?: string }) {
  return <div aria-hidden="true" className={cn('ornament', className)} />
}
