import { useId } from 'react'

import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import type { FieldErrors } from '../narrativeDraft'
import { TextField } from '../TextField'
import type { NewFlowDraft } from './flowDraft'

/** The fields of the New flow dialog: its key, its name and the Scene Type its first phase starts with. */
export function NewFlowFields({
  draft,
  onChange,
}: {
  draft: NewFlowDraft
  onChange: (draft: NewFlowDraft) => void
  errors: FieldErrors
}) {
  const sceneTypeId = useId()

  return (
    <>
      <TextField
        label="Key"
        keyField
        value={draft.key}
        onChange={(key) => {
          onChange({ ...draft, key })
        }}
      />
      <TextField
        label="Name"
        value={draft.name}
        onChange={(name) => {
          onChange({ ...draft, name })
        }}
      />
      <div className="space-y-2">
        <Label htmlFor={sceneTypeId}>First Scene Type</Label>
        <Select
          id={sceneTypeId}
          value={draft.sceneType}
          onChange={(event) => {
            onChange({ ...draft, sceneType: event.target.value })
          }}
        >
          {draft.sceneTypes.map(({ key, name }) => (
            <option key={key} value={key}>
              {name}
            </option>
          ))}
        </Select>
        <p className="text-sm text-muted-foreground">
          The flow starts with one phase that plays it; the rest comes in the flow editor.
        </p>
      </div>
    </>
  )
}
