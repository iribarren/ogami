import * as React from 'react'
import { cn } from '@/shared/lib/utils'

/**
 * A native `<select>` styled like the shadcn Input. Play and the colour-mode toggle keep it
 * (the platform picker suits a phone at the table); Studio uses `Select`, the Radix select
 * with typeahead and a styled panel.
 */
function NativeSelect({ className, ...props }: React.ComponentProps<'select'>) {
  return (
    <select
      data-slot="native-select"
      className={cn(
        'h-8 w-full min-w-0 rounded-lg border border-input bg-well px-2.5 py-1 text-base shadow-well outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
        className,
      )}
      {...props}
    />
  )
}

export { NativeSelect }
