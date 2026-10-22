import { Eye, List, Plus, Trash2 } from 'lucide-react'
import { useId, useRef, useState, type ReactNode, type SubmitEvent } from 'react'
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
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { KeyHint } from '../KeyHint'
import { StudioError } from '../useGameSystems'
import { newLikelihood } from './likelihoodDraft'
import { newTable } from './tableDraft'
import {
  OracleError,
  useCreateLikelihoodOracle,
  useCreateOracleTable,
  useDeleteLikelihoodOracle,
  useDeleteOracleTable,
  useGameSystemOracles,
  type GameSystemOracles,
  type LikelihoodOracleItem,
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
      <LikelihoodList gameSystemKey={gameSystemKey} oracles={oracles.data} />
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
    <section aria-label="Oracle tables" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <List />
        </IconTile>
        <h2 className="font-heading text-panel-title">Oracle tables</h2>
        <div className="ml-auto">
          <CreateTableDialog gameSystemKey={gameSystemKey} oracles={oracles} />
        </div>
      </div>
      {oracles.tables.length === 0 ? (
        <div className="panel-body">
          <div className="empty-state">
            <IconTile size="sm">
              <List />
            </IconTile>
            <p>This draft has no oracle tables yet.</p>
            <p className="hint">Use New table to add one.</p>
          </div>
        </div>
      ) : (
        <ul>
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
    <li className="list-row">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <Link
            to="/studio/game-systems/$key/oracle-tables/$table"
            params={{ key: gameSystemKey, table: definition.key }}
            className="list-row-title underline-offset-4 hover:underline"
          >
            {definition.name}
          </Link>
          <span className="list-row-key break-all">{definition.key}</span>
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
      <DeleteOracleDialog
        gameSystemKey={gameSystemKey}
        kind="table"
        oracleKey={definition.key}
        version={version}
        usedBy={usedBy}
        reasonId={reasonId}
        useRemove={useDeleteOracleTable}
        request={(expectedVersion) => ({ expectedVersion, table: definition.key })}
      />
    </li>
  )
}

/** A mutation as the dialogs use it. */
interface Write<Variables> {
  error: Error | null
  isPending: boolean
  reset: () => void
  mutate: (variables: Variables, options?: { onSuccess?: () => void }) => void
}

/** Delete is refused while the draft uses the oracle; the button says so instead of failing. */
function DeleteOracleDialog<Variables>({
  gameSystemKey,
  kind,
  oracleKey,
  version,
  usedBy,
  reasonId,
  useRemove,
  request,
}: {
  gameSystemKey: string
  kind: 'table' | 'likelihood oracle'
  oracleKey: string
  version: number
  usedBy: string[]
  reasonId: string
  useRemove: (gameSystemKey: string) => Write<Variables>
  request: (expectedVersion: number) => Variables
}) {
  const [open, setOpen] = useState(false)
  const remove = useRemove(gameSystemKey)
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
        <IconButton
          label={`Delete ${oracleKey}`}
          dense
          destructive
          disabled={usedBy.length > 0}
          aria-describedby={reasonId}
        >
          <Trash2 />
        </IconButton>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete {oracleKey}?</DialogTitle>
          <DialogDescription>The {kind} is removed from the draft.</DialogDescription>
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
              remove.mutate(request(version), {
                onSuccess: () => {
                  setOpen(false)
                },
              })
            }}
          >
            Delete the {kind}
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** Likelihood oracles are listed here, each linking to its editor. */
function LikelihoodList({
  gameSystemKey,
  oracles,
}: {
  gameSystemKey: string
  oracles: GameSystemOracles
}) {
  return (
    <section aria-label="Likelihood oracles" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <Eye />
        </IconTile>
        <h2 className="font-heading text-panel-title">Likelihood oracles</h2>
        <div className="ml-auto">
          <CreateLikelihoodDialog gameSystemKey={gameSystemKey} oracles={oracles} />
        </div>
      </div>
      {oracles.likelihood.length === 0 ? (
        <div className="panel-body">
          <div className="empty-state">
            <IconTile size="sm">
              <Eye />
            </IconTile>
            <p>This draft has no likelihood oracles.</p>
            <p className="hint">Use New likelihood oracle to add one.</p>
          </div>
        </div>
      ) : (
        <ul>
          {oracles.likelihood.map((item) => (
            <LikelihoodRow
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

function LikelihoodRow({
  gameSystemKey,
  item,
  version,
}: {
  gameSystemKey: string
  item: LikelihoodOracleItem
  version: number
}) {
  const { definition, usedBy } = item
  const reasonId = useId()

  return (
    <li className="list-row">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <Link
            to="/studio/game-systems/$key/likelihood-oracles/$oracle"
            params={{ key: gameSystemKey, oracle: definition.key }}
            className="list-row-title underline-offset-4 hover:underline"
          >
            {definition.name}
          </Link>
          <span className="list-row-key break-all">{definition.key}</span>
          <span className="text-sm text-muted-foreground">
            {definition.levels.length === 1
              ? '1 level'
              : `${String(definition.levels.length)} levels`}
          </span>
        </p>
        <p id={reasonId} className="text-sm">
          <UsedBy paths={usedBy} />
        </p>
      </div>
      <DeleteOracleDialog
        gameSystemKey={gameSystemKey}
        kind="likelihood oracle"
        oracleKey={definition.key}
        version={version}
        usedBy={usedBy}
        reasonId={reasonId}
        useRemove={useDeleteLikelihoodOracle}
        request={(expectedVersion) => ({ expectedVersion, oracle: definition.key })}
      />
    </li>
  )
}

type ReloadState = 'idle' | 'pending' | 'failed'

/**
 * "Reload the latest version" for a dialog that creates something. The typed values stay; the
 * conflict is cleared only once the latest version is in, because until then a retry would carry
 * the stale one. A reload belongs to the dialog session it started in: closing or reopening the
 * dialog ends the session, and a late answer is ignored.
 */
function useReloadLatest(gameSystemKey: string, clearConflict: () => void) {
  const latest = useGameSystemOracles(gameSystemKey)
  const [state, setState] = useState<ReloadState>('idle')
  const session = useRef(0)

  return {
    state,
    endSession: () => {
      session.current += 1
      setState('idle')
    },
    reload: () => {
      const started = session.current
      setState('pending')
      void latest.refetch().then((result) => {
        if (started !== session.current) {
          return
        }
        if (result.isError) {
          setState('failed')
        } else {
          clearConflict()
          setState('idle')
        }
      })
    },
  }
}

/** The dialog around a create form: it owns the open state and the reload session. */
function CreateOracleDialog({
  gameSystemKey,
  create,
  trigger,
  title,
  description,
  children,
}: {
  gameSystemKey: string
  create: Write<never>
  trigger: string
  title: string
  description: string
  children: (form: {
    reload: ReloadState
    onReload: () => void
    onCreated: () => void
  }) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const latest = useReloadLatest(gameSystemKey, create.reset)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        latest.endSession()
        create.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <Plus aria-hidden="true" />
          {trigger}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {children({
          reload: latest.state,
          onReload: latest.reload,
          onCreated: () => {
            setOpen(false)
          },
        })}
      </DialogContent>
    </Dialog>
  )
}

function CreateTableDialog({
  gameSystemKey,
  oracles,
}: {
  gameSystemKey: string
  oracles: GameSystemOracles
}) {
  const create = useCreateOracleTable(gameSystemKey)

  return (
    <CreateOracleDialog
      gameSystemKey={gameSystemKey}
      create={create}
      trigger="New table"
      title="New oracle table"
      description="It starts with one entry; edit it to add the rest."
    >
      {(form) => <CreateTableForm create={create} version={oracles.version} {...form} />}
    </CreateOracleDialog>
  )
}

function CreateLikelihoodDialog({
  gameSystemKey,
  oracles,
}: {
  gameSystemKey: string
  oracles: GameSystemOracles
}) {
  const create = useCreateLikelihoodOracle(gameSystemKey)

  return (
    <CreateOracleDialog
      gameSystemKey={gameSystemKey}
      create={create}
      trigger="New likelihood oracle"
      title="New likelihood oracle"
      description="It starts with two levels on a d100; edit it to change them and add chaos."
    >
      {(form) => <CreateLikelihoodForm create={create} version={oracles.version} {...form} />}
    </CreateOracleDialog>
  )
}

/** Key and name, the errors of a create and its conflict button, around the kind's own fields. */
function CreateForm({
  label,
  submit,
  create,
  reload,
  onReload,
  oracleKey,
  name,
  onKey,
  onName,
  valid,
  onSubmit,
  children,
}: {
  label: string
  submit: string
  create: Write<never>
  reload: ReloadState
  onReload: () => void
  oracleKey: string
  name: string
  onKey: (key: string) => void
  onName: (name: string) => void
  valid: boolean
  onSubmit: () => void
  children?: ReactNode
}) {
  const keyId = useId()
  const nameId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    onSubmit()
  }

  return (
    <form aria-label={label} className="space-y-4" onSubmit={handleSubmit}>
      <div className="space-y-2">
        <Label htmlFor={keyId}>Key</Label>
        <Input
          id={keyId}
          value={oracleKey}
          autoComplete="off"
          aria-describedby={`${keyId}-hint`}
          onChange={(event) => {
            onKey(event.target.value)
          }}
        />
        <KeyHint id={`${keyId}-hint`} />
      </div>
      <div className="space-y-2">
        <Label htmlFor={nameId}>Name</Label>
        <Input
          id={nameId}
          value={name}
          autoComplete="off"
          onChange={(event) => {
            onName(event.target.value)
          }}
        />
      </div>
      {children}
      {create.error && (
        <p role="alert" className="text-sm text-destructive">
          {create.error.message}
        </p>
      )}
      {reload === 'failed' && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t reload the latest version. Try again.
        </p>
      )}
      {create.error instanceof StudioError && create.error.status === 409 ? (
        <Button type="button" disabled={reload === 'pending'} onClick={onReload}>
          Reload the latest version
        </Button>
      ) : (
        <Button type="submit" disabled={!valid || create.isPending}>
          {submit}
        </Button>
      )}
    </form>
  )
}

interface CreateFormProps {
  version: number
  reload: ReloadState
  onReload: () => void
  onCreated: () => void
}

function CreateLikelihoodForm({
  create,
  version,
  reload,
  onReload,
  onCreated,
}: CreateFormProps & { create: ReturnType<typeof useCreateLikelihoodOracle> }) {
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const valid = key.trim() !== '' && name.trim() !== ''

  return (
    <CreateForm
      label="New likelihood oracle"
      submit="Create likelihood oracle"
      create={create}
      reload={reload}
      onReload={onReload}
      oracleKey={key}
      name={name}
      onKey={setKey}
      onName={setName}
      valid={valid}
      onSubmit={() => {
        if (valid) {
          create.mutate(
            { expectedVersion: version, oracle: newLikelihood(key.trim(), name.trim()) },
            { onSuccess: onCreated },
          )
        }
      }}
    />
  )
}

function CreateTableForm({
  create,
  version,
  reload,
  onReload,
  onCreated,
}: CreateFormProps & { create: ReturnType<typeof useCreateOracleTable> }) {
  const [key, setKey] = useState('')
  const [name, setName] = useState('')
  const [ranged, setRanged] = useState(true)
  const [dice, setDice] = useState('1d6')
  const kindId = useId()
  const diceId = useId()
  const table = newTable(key.trim(), name.trim(), ranged, dice)
  const valid = key.trim() !== '' && name.trim() !== '' && table !== null

  return (
    <CreateForm
      label="New oracle table"
      submit="Create table"
      create={create}
      reload={reload}
      onReload={onReload}
      oracleKey={key}
      name={name}
      onKey={setKey}
      onName={setName}
      valid={valid}
      onSubmit={() => {
        if (table) {
          create.mutate({ expectedVersion: version, table }, { onSuccess: onCreated })
        }
      }}
    >
      <div className="space-y-2">
        <Label htmlFor={kindId}>Kind</Label>
        <Select
          id={kindId}
          value={ranged ? 'ranged' : 'weighted'}
          onChange={(event) => {
            setRanged(event.target.value === 'ranged')
          }}
        >
          <option value="ranged">Ranged (rolls dice)</option>
          <option value="weighted">Weighted (entries have a weight)</option>
        </Select>
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
    </CreateForm>
  )
}
