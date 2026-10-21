import type { ReactNode } from 'react'

/** The rule every Studio key follows; shown under the field before any error. */
export const KEY_HINT = 'Lower case letters, digits and hyphens.'

/**
 * The hint under a key field. Give the input `aria-describedby={id}`; extra text about this
 * particular key (it never changes, renaming is refused) follows the rule in the same paragraph.
 */
export function KeyHint({ id, children }: { id: string; children?: ReactNode }) {
  return (
    <p id={id} className="hint">
      {KEY_HINT}
      {children && <> {children}</>}
    </p>
  )
}
