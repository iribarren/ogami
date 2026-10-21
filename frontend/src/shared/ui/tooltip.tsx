import type { ReactNode } from 'react'
import { Tooltip as TooltipPrimitive } from 'radix-ui'

import { cn } from '@/shared/lib/utils'

/**
 * A text tooltip on hover and keyboard focus. The trigger keeps its own accessible name: the
 * tooltip repeats it for sighted pointer users, so use it on controls that show no text.
 */
function Tooltip({
  content,
  children,
  className,
}: {
  content: ReactNode
  /** One element that takes a ref and props: the tooltip trigger. */
  children: ReactNode
  className?: string
}) {
  return (
    <TooltipPrimitive.Provider delayDuration={300}>
      <TooltipPrimitive.Root>
        <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
        <TooltipPrimitive.Portal>
          <TooltipPrimitive.Content
            data-slot="tooltip-content"
            sideOffset={6}
            className={cn(
              'z-50 rounded-md bg-foreground px-2 py-1 text-xs text-background shadow-md',
              className,
            )}
          >
            {content}
          </TooltipPrimitive.Content>
        </TooltipPrimitive.Portal>
      </TooltipPrimitive.Root>
    </TooltipPrimitive.Provider>
  )
}

export { Tooltip }
