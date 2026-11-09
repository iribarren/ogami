import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import { CreateForm } from '../../oracles/OraclesTab'
import { newTable } from '../../oracles/tableDraft'
import type { NewTableCapability } from './stepContext'

/**
 * "New table" under a table picker: Key and Name as in the Oracles tab, a weighted table with its
 * first entry, then `onCreated` with its key so the step selects it, and a link to fill it in.
 * `disabledReason` keeps the button but says why no table can be added.
 */
export function NewTableButton({
  capability,
  disabledReason,
  onCreated,
}: {
  capability: NewTableCapability
  disabledReason?: string
  onCreated: (key: string) => void
}) {
  const [open, setOpen] = useState(false)
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<Error | null>(null)
  const [pending, setPending] = useState(false)
  const [created, setCreated] = useState<{ key: string; name: string } | null>(null)
  const table = newTable(key.trim(), name.trim(), false, '')
  const valid = key.trim() !== '' && name.trim() !== '' && table !== null

  async function submit() {
    if (!table) {
      return
    }
    setPending(true)
    setError(null)
    try {
      await capability.create(table)
    } catch (failure) {
      setError(failure instanceof Error ? failure : new Error('Creating the table failed.'))
      return
    } finally {
      setPending(false)
    }
    setCreated({ key: table.key, name: table.name })
    setOpen(false)
    setKey('')
    setName('')
    onCreated(table.key)
  }

  return (
    <div className="space-y-2">
      <Dialog
        open={open}
        onOpenChange={(next) => {
          // Escape or a click outside while the create is in flight would hide its failure.
          if (!next && pending) {
            return
          }
          setOpen(next)
          setError(null)
        }}
      >
        <DialogTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabledReason !== undefined}
            aria-describedby={disabledReason === undefined ? undefined : 'new-table-reason'}
          >
            <Plus aria-hidden="true" />
            New table
          </Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New oracle table</DialogTitle>
            <DialogDescription>
              It starts with one entry; edit it to add the rest. It is selected in this step.
            </DialogDescription>
          </DialogHeader>
          <CreateForm
            label="New oracle table"
            submit="Create table"
            create={{ error, isPending: pending }}
            oracleKey={key}
            name={name}
            onKey={setKey}
            onName={setName}
            valid={valid}
            onSubmit={() => {
              void submit()
            }}
          />
        </DialogContent>
      </Dialog>
      {disabledReason !== undefined && (
        <p id="new-table-reason" className="text-sm text-muted-foreground">
          {disabledReason}
        </p>
      )}
      {created !== null && (
        <p role="status" className="text-sm">
          Created {created.name}.{' '}
          <Link
            to="/studio/game-systems/$key/oracle-tables/$table"
            params={{ key: capability.gameSystemKey, table: created.key }}
            className="underline underline-offset-4"
          >
            Fill in {created.name}
          </Link>
        </p>
      )}
    </div>
  )
}
