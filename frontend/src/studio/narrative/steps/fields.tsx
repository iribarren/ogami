import { useId } from 'react'

import { FieldError } from '@/shared/ui/alert'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'
import { Textarea } from '@/shared/ui/textarea'

/**
 * Checkboxes over `options`; a selected key the options no longer have stays listed. With
 * `variant="pills"` each one is a round pill (the checkbox itself stays, hidden behind it).
 */
export function CheckList({
  legend,
  options,
  value,
  error,
  variant = 'list',
  onChange,
}: {
  legend: string
  options: { key: string; name: string }[]
  value: string[]
  error?: string
  variant?: 'list' | 'pills'
  onChange: (value: string[]) => void
}) {
  const errorId = useId()
  const pills = variant === 'pills'
  const known = options.map((option) => option.key)
  const all = [
    ...options,
    ...[...new Set(value)].filter((key) => !known.includes(key)).map((key) => ({ key, name: key })),
  ]

  return (
    <fieldset className="space-y-1" aria-describedby={error ? errorId : undefined}>
      <legend className="text-sm font-medium">{legend}</legend>
      {all.length === 0 && <p className="text-sm text-muted-foreground">Nothing to pick yet.</p>}
      <div className={pills ? 'flex flex-wrap gap-2' : 'space-y-1'}>
        {all.map((option) => (
          <label
            key={option.key}
            className={pills ? 'pill-check' : 'flex items-center gap-2 text-sm'}
          >
            <input
              type="checkbox"
              // A pill's checkbox covers the whole pill, unseen, so a click anywhere on it lands on the box.
              className={
                pills ? 'absolute inset-0 cursor-pointer rounded-full opacity-0' : undefined
              }
              checked={value.includes(option.key)}
              aria-invalid={error !== undefined}
              onChange={(event) => {
                onChange(
                  event.target.checked
                    ? [...value, option.key]
                    : value.filter((key) => key !== option.key),
                )
              }}
            />
            {option.name}
          </label>
        ))}
      </div>
      {error && <FieldError id={errorId}>{error}</FieldError>}
    </fieldset>
  )
}

export function Area({
  label,
  value,
  error,
  help,
  onChange,
}: {
  label: string
  value: string
  error?: string
  /** A hint under the field. */
  help?: string
  onChange: (value: string) => void
}) {
  const id = useId()
  const described = [help ? `${id}-help` : null, error ? `${id}-error` : null]
    .filter((part) => part !== null)
    .join(' ')

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Textarea
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        aria-describedby={described === '' ? undefined : described}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {help && (
        <p id={`${id}-help`} className="hint">
          {help}
        </p>
      )}
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </div>
  )
}

/** A labelled picker over `options`; a value the options no longer have stays listed, to be seen and fixed. */
export function PickField({
  label,
  value,
  options,
  placeholder,
  error,
  onChange,
}: {
  label: string
  value: string
  options: { key: string; name: string }[]
  placeholder: string
  error?: string
  onChange: (value: string) => void
}) {
  const id = useId()
  const gone = value === '' || options.some((option) => option.key === value)
  const all = gone ? options : [{ key: value, name: value }, ...options]

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Select
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        <option value="">{placeholder}</option>
        {all.map((option) => (
          <option key={option.key} value={option.key}>
            {option.name}
          </option>
        ))}
      </Select>
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </div>
  )
}

/** A labelled picker over a fixed set of `options`: always one of them, never empty. */
export function SelectField({
  label,
  value,
  options,
  error,
  onChange,
}: {
  label: string
  value: string
  options: { key: string; name: string }[]
  error?: string
  onChange: (value: string) => void
}) {
  const id = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Select
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        {options.map((option) => (
          <option key={option.key} value={option.key}>
            {option.name}
          </option>
        ))}
      </Select>
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </div>
  )
}
