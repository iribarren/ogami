import { useId, useLayoutEffect, useRef, useState } from 'react'

import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

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
  const [caret, setCaret] = useState<number | null>(null)

  // Put the caret after an inserted reference once the input shows the new text.
  useLayoutEffect(() => {
    if (caret !== null && inputRef.current !== null) {
      inputRef.current.focus()
      inputRef.current.setSelectionRange(caret, caret)
      setCaret(null)
    }
  }, [caret])

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
    setCaret(start + text.length)
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
        <NativeSelect
          aria-label="Insert reference"
          value=""
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
        </NativeSelect>
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
