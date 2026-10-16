import { useId, useState, type SubmitEvent } from 'react'
import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { StudioError } from '../useGameSystems'
import { newTable } from './tableDraft'
import {
  OracleError,
  useCreateOracleTable,
  useDeleteOracleTable,
  useGameSystemOracles,
  type GameSystemOracles,
  type OracleTableItem,
} from './useGameSystemOracles'

/** The oracle tables and likelihood oracles of a draft, with where each is used. */
export function OraclesTab({ gameSystemKey }: { gameSystemKey: string }) {
  const oracles = useGameSystemOracles(gameSystemKey)

  if (oracles.data === undefined) {
    if (oracles.isPending) {
      return <p className="text-muted-foreground">Loading the oracles…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {oracles.error instanceof StudioError && oracles.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : oracles.error.message}
      </p>
    )
  }

  return (
    <section aria-label="Oracles" className="space-y-8">
      {oracles.isRefetchError && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t refresh the oracles. {oracles.error.message}
        </p>
      )}
      <TablesList gameSystemKey={gameSystemKey} oracles={oracles.data} />
      <LikelihoodList oracles={oracles.data} />
    </section>
  )
}

function UsedBy({ paths }: { paths: string[] }) {
  return paths.length === 0 ? (
    <span className="text-muted-foreground">Not used</span>
  ) : (
    <span className="break-all text-muted-foreground">Used by {paths.join(', ')}</span>
  )
}

function TablesList({
  gameSystemKey,
  oracles,
}: {
  gameSystemKey: string
  oracles: GameSystemOracles
}) {
  return (
    <section aria-label="Oracle tables" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-heading text-lg font-semibold">Oracle tables</h2>
        <CreateTableDialog gameSystemKey={gameSystemKey} oracles={oracles} />
      </div>
      {oracles.tables.length === 0 ? (
        <p className="text-muted-foreground">This draft has no oracle tables yet.</p>
      ) : (
        <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
          {oracles.tables.map((item) => (
            <TableRow
              key={item.definition.key}
              gameSystemKey={gameSystemKey}
              item={item}
              version={oracles.version}
            />
          ))}
        </ul>
      )}
    </section>
  )
}

function TableRow({
  gameSystemKey,
  item,
  version,
}: {
  gameSystemKey: string
  item: OracleTableItem
  version: number
}) {
  const { definition, usedBy } = item
  const reasonId = useId()

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <Link
            to="/studio/game-systems/$key/oracle-tables/$table"
            params={{ key: gameSystemKey, table: definition.key }}
            className="font-medium underline-offset-4 hover:underline"
          >
            {definition.name}
          </Link>
          <span className="font-mono text-sm break-all text-muted-foreground">
            {definition.key}
          </span>
          <Badge variant="secondary">
            {typeof definition.dice === 'string' ? `Ranged ${definition.dice}` : 'Weighted'}
          </Badge>
          <span className="text-sm text-muted-foreground">
            {definition.entries.length === 1
              ? '1 entry'
              : `${String(definition.entries.length)} entries`}
          </span>
        </p>
        <p id={reasonId} className="text-sm">
          <UsedBy paths={usedBy} />
        </p>
      </div>
      <DeleteTableDialog
        gameSystemKey={gameSystemKey}
        table={definition.key}
        version={version}
        usedBy={usedBy}
        reasonId={reasonId}
      />
    </li>
  )
}

/** Delete is refused while the draft uses the table; the button says so instead of failing. */
function DeleteTableDialog({
  gameSystemKey,
  table,
  version,
  usedBy,
  reasonId,
}: {
  gameSystemKey: string
  table: string
  version: number
  usedBy: string[]
  reasonId: string
}) {
  const [open, setOpen] = useState(false)
  const remove = useDeleteOracleTable(gameSystemKey)
  const oracles = useGameSystemOracles(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        remove.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          disabled={usedBy.length > 0}
          aria-describedby={reasonId}
          aria-label={`Delete ${table}`}
        >
          Delete
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete {table}?</DialogTitle>
          <DialogDescription>The table is removed from the draft.</DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {remove.error.message}
            {remove.error instanceof OracleError && remove.error.paths.length > 0
              ? ` Used by ${remove.error.paths.join(', ')}.`
              : ''}
          </p>
        )}
        {remove.error instanceof StudioError && remove.error.status === 409 ? (
          <Button
            onClick={() => {
              void oracles.refetch()
              setOpen(false)
            }}
          >
            Reload the latest version
          </Button>
        ) : (
          <Button
            variant="destructive"
            disabled={remove.isPending}
            onClick={() => {
              remove.mutate(
                { expectedVersion: version, table },
                {
                  onSuccess: () => {
                    setOpen(false)
                  },
                },
              )
            }}
          >
            Delete the table
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** Likelihood oracles are listed here; their editor comes in the next slice. */
function LikelihoodList({ oracles }: { oracles: GameSystemOracles }) {
  return (
    <section aria-label="Likelihood oracles" className="space-y-3">
      <h2 className="font-heading text-lg font-semibold">Likelihood oracles</h2>
      {oracles.likelihood.length === 0 ? (
        <p className="text-muted-foreground">This draft has no likelihood oracles.</p>
      ) : (
        <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
          {oracles.likelihood.map(({ definition, usedBy }) => (
            <li key={definition.key} className="space-y-1 px-4 py-3">
              <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-medium">{definition.name}</span>
                <span className="font-mono text-sm break-all text-muted-foreground">
                  {definition.key}
                </span>
                <span className="text-sm text-muted-foreground">
                  {definition.levels.length === 1
                    ? '1 level'
                    : `${String(definition.levels.length)} levels`}
                </span>
              </p>
              <p className="text-sm">
                <UsedBy paths={usedBy} />
              </p>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function CreateTableDialog({
  gameSystemKey,
  oracles,
}: {
  gameSystemKey: string
  oracles: GameSystemOracles
}) {
  const [open, setOpen] = useState(false)
  const create = useCreateOracleTable(gameSystemKey)
  const latest = useGameSystemOracles(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        create.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm">New table</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New oracle table</DialogTitle>
          <DialogDescription>It starts with one entry; edit it to add the rest.</DialogDescription>
        </DialogHeader>
        <CreateTableForm
          create={create}
          version={oracles.version}
          onReload={() => {
            // The typed values stay: the next create carries the version read now.
            create.reset()
            void latest.refetch()
          }}
          onCreated={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function CreateTableForm({
  create,
  version,
  onReload,
  onCreated,
}: {
  create: ReturnType<typeof useCreateOracleTable>
  version: number
  onReload: () => void
  onCreated: () => void
}) {
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [ranged, setRanged] = useState(true)
  const [dice, setDice] = useState('1d6')
  const keyId = useId()
  const nameId = useId()
  const kindId = useId()
  const diceId = useId()
  const table = newTable(key.trim(), name.trim(), ranged, dice)
  const valid = key.trim() !== '' && name.trim() !== '' && table !== null

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (table) {
      create.mutate({ expectedVersion: version, table }, { onSuccess: onCreated })
    }
  }

  return (
    <form aria-label="New oracle table" className="space-y-4" onSubmit={handleSubmit}>
      <div className="space-y-2">
        <Label htmlFor={keyId}>Key</Label>
        <Input
          id={keyId}
          value={key}
          autoComplete="off"
          onChange={(event) => {
            setKey(event.target.value)
          }}
        />
        <p className="text-sm text-muted-foreground">Lower case letters, digits and hyphens.</p>
      </div>
      <div className="space-y-2">
        <Label htmlFor={nameId}>Name</Label>
        <Input
          id={nameId}
          value={name}
          autoComplete="off"
          onChange={(event) => {
            setName(event.target.value)
          }}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor={kindId}>Kind</Label>
        <NativeSelect
          id={kindId}
          value={ranged ? 'ranged' : 'weighted'}
          onChange={(event) => {
            setRanged(event.target.value === 'ranged')
          }}
        >
          <option value="ranged">Ranged (rolls dice)</option>
          <option value="weighted">Weighted (entries have a weight)</option>
        </NativeSelect>
      </div>
      {ranged && (
        <div className="space-y-2">
          <Label htmlFor={diceId}>Dice</Label>
          <Input
            id={diceId}
            value={dice}
            autoComplete="off"
            aria-invalid={table === null}
            onChange={(event) => {
              setDice(event.target.value)
            }}
          />
          {table === null && (
            <p className="text-sm text-destructive">Use dice like 1d6, 2d6 or d%.</p>
          )}
        </div>
      )}
      {create.error && (
        <p role="alert" className="text-sm text-destructive">
          {create.error.message}
        </p>
      )}
      {create.error instanceof StudioError && create.error.status === 409 ? (
        <Button type="button" onClick={onReload}>
          Reload the latest version
        </Button>
      ) : (
        <Button type="submit" disabled={!valid || create.isPending}>
          Create table
        </Button>
      )}
    </form>
  )
}
