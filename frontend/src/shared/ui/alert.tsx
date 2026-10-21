import type { ReactNode } from 'react'
import { CircleAlert, TriangleAlert } from 'lucide-react'

import { cn } from '@/shared/lib/utils'

/**
 * An inline error or warning: a tinted box with an icon, the message and, optionally, one action
 * button on the right. It carries no live-region role; add `role="alert"` where it appears late.
 */
function Alert({
  variant = 'error',
  action,
  className,
  children,
  ...props
}: Omit<React.ComponentProps<'div'>, 'children'> & {
  variant?: 'error' | 'warning'
  action?: ReactNode
  children: ReactNode
}) {
  const Icon = variant === 'error' ? CircleAlert : TriangleAlert
  return (
    <div
      data-slot="alert"
      className={cn('alert', variant === 'error' ? 'alert-error' : 'alert-warning', className)}
      {...props}
    >
      <Icon aria-hidden="true" />
      <div className="min-w-0 flex-1">{children}</div>
      {action}
    </div>
  )
}

/** A message under a field; link it to the input with `aria-describedby` and mark it invalid. */
function FieldError({ className, children, ...props }: React.ComponentProps<'p'>) {
  return (
    <p data-slot="field-error" className={cn('field-error', className)} {...props}>
      <CircleAlert aria-hidden="true" />
      {children}
    </p>
  )
}

export { Alert, FieldError }
