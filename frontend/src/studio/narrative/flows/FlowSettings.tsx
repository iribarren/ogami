import { useId } from 'react'

import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { TextField } from '../TextField'
import { Area, CheckList } from '../steps/fields'
import type { StepContext } from '../steps/stepContext'
import { flowViews, type FlowDraft } from './flowDraft'

/**
 * The fields of the flow itself: key, name, texts, default view and flag, the oracle shortcuts and
 * the trackers it shows. `errors` are the messages by field name.
 */
export function FlowSettings({
  draft,
  context,
  errors,
  onChange,
}: {
  draft: FlowDraft
  context: StepContext
  errors: Record<string, string>
  onChange: (draft: FlowDraft) => void
}) {
  const viewId = useId()
  const options = [...context.tables, ...context.likelihood]

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Key"
          keyField
          value={draft.key}
          error={errors.key}
          onChange={(key) => {
            onChange({ ...draft, key })
          }}
        />
        <TextField
          label="Name"
          value={draft.name}
          error={errors.name}
          onChange={(name) => {
            onChange({ ...draft, name })
          }}
        />
      </div>
      <Area
        label="Description"
        value={draft.description}
        error={errors.description}
        onChange={(description) => {
          onChange({ ...draft, description })
        }}
      />
      <Area
        label="Introduction"
        value={draft.introduction}
        error={errors.introduction}
        onChange={(introduction) => {
          onChange({ ...draft, introduction })
        }}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor={viewId}>Default view</Label>
          <Select
            id={viewId}
            value={draft.defaultView}
            onChange={(event) => {
              onChange({
                ...draft,
                defaultView: flowViews.find((view) => view === event.target.value) ?? 'focus',
              })
            }}
          >
            <option value="focus">Focus</option>
            <option value="journal">Journal</option>
          </Select>
          {errors.defaultView && <p className="text-sm text-destructive">{errors.defaultView}</p>}
        </div>
        <div className="space-y-1">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={draft.default}
              onChange={(event) => {
                onChange({ ...draft, default: event.target.checked })
              }}
            />
            Default flow
          </label>
          <p className="text-sm text-muted-foreground">
            Saving it as the default clears the flag on the other flows.
          </p>
          {errors.default && <p className="text-sm text-destructive">{errors.default}</p>}
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1">
          <CheckList
            legend="Oracle shortcuts"
            options={options}
            value={draft.oracles}
            onChange={(oracles) => {
              onChange({ ...draft, oracles })
            }}
          />
          {errors.oracles && <p className="text-sm text-destructive">{errors.oracles}</p>}
        </div>
        <div className="space-y-1">
          <CheckList
            legend="Trackers"
            options={context.trackers}
            value={draft.trackers}
            onChange={(trackers) => {
              onChange({ ...draft, trackers })
            }}
          />
          {errors.trackers && <p className="text-sm text-destructive">{errors.trackers}</p>}
        </div>
      </div>
    </div>
  )
}
