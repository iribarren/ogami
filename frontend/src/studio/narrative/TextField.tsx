import { useId } from 'react'

import { FieldError } from '@/shared/ui/alert'
import { CharCount } from '@/shared/ui/char-count'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { KeyHint } from '../KeyHint'

/**
 * A labelled text input that flags its own error. A key field (`keyField`) shows the key rule
 * first; the hint, the help and the error all describe the input, in that order.
 */
export function TextField({
  label,
  value,
  onChange,
  error,
  help,
  keyField = false,
  limit,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
  help?: string
  keyField?: boolean
  /** The cap of the text: it gets a live counter, and typing past it is not blocked. */
  limit?: number
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const helpId = `${id}-help`
  const errorId = `${id}-error`
  const countId = `${id}-count`
  const described = [
    keyField ? hintId : null,
    help ? helpId : null,
    error ? errorId : null,
    limit === undefined ? null : countId,
  ]
    .filter((part) => part !== null)
    .join(' ')

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        value={value}
        autoComplete="off"
        aria-invalid={error !== undefined}
        aria-describedby={described === '' ? undefined : described}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {keyField && <KeyHint id={hintId} />}
      {help && (
        <p id={helpId} className="hint">
          {help}
        </p>
      )}
      {limit !== undefined && <CharCount id={countId} length={value.length} max={limit} />}
      {error && <FieldError id={errorId}>{error}</FieldError>}
    </div>
  )
}
