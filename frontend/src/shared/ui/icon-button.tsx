import * as React from 'react'

import { cn } from '@/shared/lib/utils'

import { Tooltip } from './tooltip'

/**
 * A 30px icon-only button. `label` is its accessible name and the text of its tooltip, so it
 * keeps the name a text button had. The icon is passed as children and hidden from assistive
 * technology.
 */
function IconButton({
  label,
  dense = false,
  destructive = false,
  className,
  children,
  type = 'button',
  ...props
}: Omit<React.ComponentProps<'button'>, 'aria-label'> & {
  label: string
  /** 14px icon, for rows. */
  dense?: boolean
  /** Hover in the destructive color, for Delete and Remove. */
  destructive?: boolean
}) {
  return (
    <Tooltip content={label}>
      <button
        type={type}
        aria-label={label}
        data-slot="icon-button"
        className={cn(
          'icon-button outline-none focus-visible:ring-3 focus-visible:ring-ring/50 [&>svg]:pointer-events-none',
          dense && '[&>svg]:size-3.5',
          destructive && 'hover:text-destructive',
          className,
        )}
        {...props}
      >
        {React.Children.map(children, (child) =>
          React.isValidElement<{ 'aria-hidden'?: boolean }>(child)
            ? React.cloneElement(child, { 'aria-hidden': true })
            : child,
        )}
      </button>
    </Tooltip>
  )
}

export { IconButton }
