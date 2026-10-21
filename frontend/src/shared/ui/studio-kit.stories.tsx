import type { Meta, StoryObj } from '@storybook/react-vite'
import { BookOpen, Copy, Pencil, Trash2 } from 'lucide-react'

import { onTheme } from '@/shared/theme/themeStories'

import { Alert, FieldError } from './alert'
import { Button } from './button'
import { IconButton } from './icon-button'
import { IconTile } from './icon-tile'
import { SaveState } from './save-state'
import { Tooltip } from './tooltip'

/** The Studio kit in one place: panel, list row, icon buttons, save state, alerts, pills. */
function Kit() {
  return (
    <div className="max-w-xl space-y-6 text-card-foreground">
      <section className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <BookOpen />
          </IconTile>
          <h2 className="font-heading text-panel-title">Details</h2>
          <span className="ml-auto count-pill">3</span>
        </div>
        <ul>
          <li className="list-row">
            <div className="min-w-0 flex-1">
              <p className="list-row-title">Wits check</p>
              <p className="list-row-key">wits</p>
            </div>
            <IconButton label="Duplicate" dense>
              <Copy />
            </IconButton>
            <IconButton label="Edit">
              <Pencil />
            </IconButton>
            <IconButton label="Delete" destructive>
              <Trash2 />
            </IconButton>
          </li>
          <li className="list-row" aria-current="true">
            <div className="min-w-0 flex-1">
              <p className="list-row-title">Selected check</p>
              <p className="list-row-key">selected</p>
            </div>
            <span className="count-pill count-pill-error">2</span>
          </li>
        </ul>
        <div className="space-y-3 panel-body">
          <p className="hint">Lower case letters, digits and hyphens.</p>
          <FieldError>Use lower case letters, digits and hyphens.</FieldError>
          <div className="readout readout-sm">42</div>
          <p className="group-label">Setup</p>
        </div>
        <div className="action-bar">
          <SaveState state={{ kind: 'saved', at: new Date(2026, 9, 22, 9, 30) }} />
          <Button>Save</Button>
        </div>
      </section>
      <div className="flex flex-wrap items-center gap-4">
        <SaveState state={{ kind: 'saving' }} />
        <SaveState state={{ kind: 'failed', errors: 2, onGoToFirst: () => undefined }} />
        <SaveState state={{ kind: 'unsaved' }} />
        <Tooltip content="Hover or focus me">
          <Button variant="outline">Tooltip</Button>
        </Tooltip>
      </div>
      <Alert>The draft is not valid yet.</Alert>
      <Alert variant="warning" action={<Button size="sm">Enable on flow</Button>}>
        This step uses a tracker the flow does not enable.
      </Alert>
      <div className="empty-state">
        <IconTile size="sm">
          <BookOpen />
        </IconTile>
        <p>No items yet.</p>
        <p className="hint">Add the first one with the button above.</p>
      </div>
    </div>
  )
}

const meta = {
  title: 'Shared/UI/Studio kit',
  component: Kit,
} satisfies Meta<typeof Kit>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Parchment: Story = onTheme('parchment', {})
export const Neon: Story = onTheme('neon', {})
