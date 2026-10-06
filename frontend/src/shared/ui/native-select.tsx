import * as React from 'react'
import { cn } from '@/shared/lib/utils'

/**
 * A native `<select>` styled like the shadcn Input; no select primitive is installed yet
 * (ADR 0004: libraries come with the feature that needs them).
 */
function NativeSelect({ className, ...props }: React.ComponentProps<'select'>) {
  return (
    <select
      data-slot="native-select"
      className={cn(
        'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30',
        className,
      )}
      {...props}
    />
  )
}

export { NativeSelect }
