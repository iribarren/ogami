import { useId } from 'react'

import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

/** A labelled text input that flags its own error. */
export function TextField({
  label,
  value,
  onChange,
  error,
  help,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
  help?: string
}) {
  const id = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        value={value}
        autoComplete="off"
        aria-invalid={error !== undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {help && <p className="text-sm text-muted-foreground">{help}</p>}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}
