import { cn } from '@/shared/lib/utils'

/**
 * A live "37/100" under a text input with a cap, turning destructive past it. The figures are
 * also in text for assistive technology; give the input `aria-describedby={id}`. It never blocks
 * typing: the server's refusal stays the authority.
 */
export function CharCount({ id, length, max }: { id: string; length: number; max: number }) {
  const over = length - max

  return (
    <span id={id} className="block text-right text-xs text-muted-foreground">
      <span aria-hidden="true" className={cn(over > 0 && 'text-destructive')}>
        {length}/{max}
      </span>
      <span className="sr-only">
        {length} of {max} characters{over > 0 && ` (${String(over)} over)`}
      </span>
    </span>
  )
}
