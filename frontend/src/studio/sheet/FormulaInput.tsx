import { useId, useRef } from 'react'

import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import type { FormulaDiagnostics } from './formulaDiagnostics'

/** A reference the formula may read: `reference` is what goes between the braces. */
export interface ReferenceOption {
  reference: string
  label: string
}

/**
 * A formula input with live diagnostics: the error and where it is (the offending character is
 * marked), the value over the sample values when there is no error, and a menu that inserts a
 * reference at the caret. Diagnostics are advice: nothing here blocks saving, the publisher stays
 * the authority. `diagnostics` and `references` come from the caller, which knows the sheet;
 * `error` is the publisher's own answer, shown as it is.
 */
export function FormulaInput({
  label,
  value,
  onChange,
  diagnostics,
  references,
  error,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  /** Null while there is nothing to say yet, e.g. a blank formula. */
  diagnostics: FormulaDiagnostics | null
  references: ReferenceOption[]
  error?: string
}) {
  const id = useId()
  const messageId = `${id}-message`
  const inputRef = useRef<HTMLInputElement>(null)
  // Where the caret goes after an inserted reference, once the menu has closed.
  const caretRef = useRef<number | null>(null)

  const problem = diagnostics?.error ?? null
  const result = problem === null ? (diagnostics?.result ?? null) : null
  // The position counts in the text without its outer whitespace.
  const at =
    problem?.position === undefined
      ? null
      : value.length - value.trimStart().length + problem.position - 1

  function insert(reference: string) {
    const input = inputRef.current
    const start = input?.selectionStart ?? value.length
    const end = input?.selectionEnd ?? start
    const text = `{${reference}}`
    onChange(value.slice(0, start) + text + value.slice(end))
    caretRef.current = start + text.length
  }

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        ref={inputRef}
        id={id}
        value={value}
        autoComplete="off"
        spellCheck={false}
        className="font-mono"
        aria-invalid={error !== undefined || problem !== null}
        aria-describedby={problem === null ? undefined : messageId}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {references.length > 0 && (
        <Select
          aria-label="Insert reference"
          value=""
          // After an insert the caret goes back to the formula, not to this menu. Focusing the
          // input earlier loses to the menu's focus trap, which is still active until it closes.
          onCloseAutoFocus={(event) => {
            const caret = caretRef.current
            caretRef.current = null
            if (caret !== null && inputRef.current !== null) {
              event.preventDefault()
              inputRef.current.focus()
              inputRef.current.setSelectionRange(caret, caret)
            }
          }}
          onChange={(event) => {
            if (event.target.value !== '') {
              insert(event.target.value)
            }
          }}
        >
          <option value="">Insert reference…</option>
          {references.map((option) => (
            <option key={option.reference} value={option.reference}>
              {option.label} {`{${option.reference}}`}
            </option>
          ))}
        </Select>
      )}
      {problem !== null && (
        <div id={messageId} className="space-y-1 text-sm text-destructive">
          {at !== null && at >= 0 && at < value.length && (
            <p className="font-mono break-all whitespace-pre-wrap" aria-hidden="true">
              {value.slice(0, at)}
              <mark className="rounded bg-destructive/20 text-destructive">{value[at]}</mark>
              {value.slice(at + 1)}
            </p>
          )}
          <p>{problem.message}</p>
        </div>
      )}
      {result !== null && (
        <p className="text-sm text-muted-foreground">
          {'value' in result
            ? `Value with the sample values: ${String(result.value)}`
            : `No value with the sample values: ${result.reason}`}
        </p>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}
