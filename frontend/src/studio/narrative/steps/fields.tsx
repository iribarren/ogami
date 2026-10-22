import { useId } from 'react'

import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'
import { Textarea } from '@/shared/ui/textarea'

/** Checkboxes over `options`; a selected key the options no longer have stays listed. */
export function CheckList({
  legend,
  options,
  value,
  onChange,
}: {
  legend: string
  options: { key: string; name: string }[]
  value: string[]
  onChange: (value: string[]) => void
}) {
  const known = options.map((option) => option.key)
  const all = [
    ...options,
    ...[...new Set(value)].filter((key) => !known.includes(key)).map((key) => ({ key, name: key })),
  ]

  return (
    <fieldset className="space-y-1">
      <legend className="text-sm font-medium">{legend}</legend>
      {all.length === 0 && <p className="text-sm text-muted-foreground">Nothing to pick yet.</p>}
      {all.map((option) => (
        <label key={option.key} className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={value.includes(option.key)}
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
    </fieldset>
  )
}

export function Area({
  label,
  value,
  error,
  onChange,
}: {
  label: string
  value: string
  error?: string
  onChange: (value: string) => void
}) {
  const id = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Textarea
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {error && <p className="text-sm text-destructive">{error}</p>}
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
      {error && <p className="text-sm text-destructive">{error}</p>}
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
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}
