import { Link } from '@tanstack/react-router'
import {
  ArrowRight,
  BookOpen,
  CircleAlert,
  Clapperboard,
  Eye,
  HeartPulse,
  Library,
  Route,
  RotateCcw,
  ShieldCheck,
  TriangleAlert,
  UserRound,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { Button } from '@/shared/ui/button'
import { DieShape } from '@/shared/ui/die-shape'
import { IconTile } from '@/shared/ui/icon-tile'

import { useGameSystemValidation } from './useGameSystems'
import {
  locateError,
  splitWarning,
  useNames,
  type ErrorLocation,
  type Target,
} from './validationLocation'

const plural = (count: number, one: string, many: string) =>
  `${String(count)} ${count === 1 ? one : many}`

const tabIcons: Record<string, ReactNode> = {
  overview: <BookOpen />,
  oracles: <Eye />,
  narrative: <HeartPulse />,
  flows: <Route />,
  sceneTypes: <Clapperboard />,
  sheet: <UserRound />,
  checks: <DieShape sides={20} inner className="text-current" />,
  library: <Library />,
}

function groupIcon(target: Target): ReactNode {
  switch (target.kind) {
    case 'flow':
      return <Route />
    case 'sceneType':
      return <Clapperboard />
    case 'table':
    case 'likelihood':
      return <Eye />
    case 'tab':
      return tabIcons[target.tab] ?? <BookOpen />
  }
}

interface Row {
  path: string
  message: string
  where: ErrorLocation
}

/** Errors in the order the API gave them, grouped by the section and item they are in. */
function groupRows(rows: Row[]): { title: string; icon: ReactNode; rows: Row[] }[] {
  const groups = new Map<string, { title: string; icon: ReactNode; rows: Row[] }>()
  for (const row of rows) {
    // The node of a flow is the field's place, not the item's: a flow is one group.
    const id = `${row.where.group}|${JSON.stringify({ ...row.where.target, node: undefined })}`
    const group = groups.get(id) ?? {
      title: row.where.group,
      icon: groupIcon(row.where.target),
      rows: [],
    }
    group.rows.push(row)
    groups.set(id, group)
  }
  return [...groups.values()]
}

function GoToField({ gameSystemKey: key, target }: { gameSystemKey: string; target: Target }) {
  const label = (
    <>
      Go to field
      <ArrowRight aria-hidden="true" data-icon="inline-end" />
    </>
  )
  const link = (() => {
    switch (target.kind) {
      case 'flow':
        return (
          <Link
            to="/studio/game-systems/$key/flows/$flow"
            params={{ key, flow: target.flow }}
            search={{ node: target.node }}
          >
            {label}
          </Link>
        )
      case 'sceneType':
        return (
          <Link
            to="/studio/game-systems/$key/scene-types/$sceneType"
            params={{ key, sceneType: target.sceneType }}
          >
            {label}
          </Link>
        )
      case 'table':
        return (
          <Link
            to="/studio/game-systems/$key/oracle-tables/$table"
            params={{ key, table: target.table }}
          >
            {label}
          </Link>
        )
      case 'likelihood':
        return (
          <Link
            to="/studio/game-systems/$key/likelihood-oracles/$oracle"
            params={{ key, oracle: target.oracle }}
          >
            {label}
          </Link>
        )
      case 'tab':
        return (
          <Link to="/studio/game-systems/$key" params={{ key }} search={{ tab: target.tab }}>
            {label}
          </Link>
        )
    }
  })()
  return (
    <Button asChild variant="outline" size="sm" className="shrink-0">
      {link}
    </Button>
  )
}

/** One error or warning: its icon, the message, where it is in words and the way to the field. */
function IssueRow({
  gameSystemKey: key,
  icon,
  message,
  detail,
  target,
}: {
  gameSystemKey: string
  icon: ReactNode
  message: string
  detail?: string
  target?: Target
}) {
  return (
    <li className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
      {icon}
      <div className="min-w-0 flex-1">
        <p>{message}</p>
        {detail !== undefined && <p className="text-xs text-muted-foreground">{detail}</p>}
      </div>
      {target !== undefined && <GoToField gameSystemKey={key} target={target} />}
    </li>
  )
}

/**
 * The Validation section: whether the draft would be accepted as a release, every error grouped by
 * the section and item it is in with a link to the field, then the warnings.
 */
export function ValidationTab({ gameSystemKey, pack }: { gameSystemKey: string; pack: boolean }) {
  const validation = useGameSystemValidation(gameSystemKey)
  const names = useNames(gameSystemKey)
  const noun = pack ? 'pack' : 'draft'
  if (validation.isPending) {
    return <p className="text-muted-foreground">Validating the {noun}…</p>
  }
  if (validation.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {validation.error.message}
      </p>
    )
  }
  const { valid, errors, warnings } = validation.data
  const rows = errors.map(({ path, message }) => ({
    path,
    message,
    where: locateError(path, names),
  }))

  return (
    <div className="space-y-4">
      <section aria-label="Validation" className="panel">
        <div className="flex flex-wrap items-center gap-4 panel-body">
          <IconTile size="lg" className={valid ? undefined : 'text-destructive'}>
            <ShieldCheck />
          </IconTile>
          <div className="min-w-0 flex-1" role={valid ? undefined : 'alert'}>
            <h2 className="font-heading text-panel-title">
              {valid
                ? pack
                  ? "The pack's items are valid."
                  : 'The draft is valid: it could be published as a release.'
                : `The ${noun} is not valid yet`}
            </h2>
            {!valid && (
              <p className="text-sm text-muted-foreground">
                {pack
                  ? `${plural(errors.length, 'error', 'errors')} to fix.`
                  : `${plural(errors.length, 'error blocks', 'errors block')} publishing.`}{' '}
                {plural(warnings.length, 'warning does', 'warnings do')} not.
              </p>
            )}
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={validation.isFetching}
            onClick={() => {
              void validation.refetch()
            }}
          >
            <RotateCcw aria-hidden="true" data-icon="inline-start" />
            Check again
          </Button>
        </div>
      </section>
      {groupRows(rows).map((group) => (
        <section
          key={`${group.title}|${group.rows[0]?.path ?? ''}`}
          aria-label={group.title}
          className="panel"
        >
          <div className="panel-head">
            <IconTile size="sm">{group.icon}</IconTile>
            <h3 className="font-heading text-panel-title">{group.title}</h3>
            <span className="ml-auto count-pill count-pill-error">{group.rows.length}</span>
          </div>
          <ul className="divide-y panel-body">
            {group.rows.map((row, index) => (
              <IssueRow
                key={`${String(index)}:${row.path}`}
                gameSystemKey={gameSystemKey}
                icon={
                  <CircleAlert
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-destructive"
                  />
                }
                message={row.message}
                detail={row.where.trail.length > 0 ? row.where.trail.join(' › ') : row.path}
                target={row.where.target}
              />
            ))}
          </ul>
        </section>
      ))}
      {errors.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer text-muted-foreground">Technical paths</summary>
          <ul className="mt-2 space-y-1 font-mono text-xs">
            {errors.map(({ path, message }, index) => (
              <li key={`${String(index)}:${path}`}>
                {path}: {message}
              </li>
            ))}
          </ul>
        </details>
      )}
      {warnings.length > 0 && (
        <section aria-label="Warnings" className="panel">
          <div className="panel-head">
            <IconTile size="sm">
              <TriangleAlert />
            </IconTile>
            <h2 className="font-heading text-panel-title">Warnings</h2>
            <span className="ml-auto count-pill">{warnings.length}</span>
          </div>
          <ul className="divide-y panel-body text-sm">
            {warnings.map((warning, index) => {
              const { path, reason } = splitWarning(warning)
              const where = path === undefined ? undefined : locateError(path, names)
              return (
                <IssueRow
                  // Warnings can repeat, so the position is part of the key.
                  key={`${String(index)}:${warning}`}
                  gameSystemKey={gameSystemKey}
                  icon={
                    <TriangleAlert
                      aria-hidden="true"
                      className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                  }
                  message={reason}
                  detail={where && [where.group, ...where.trail].join(' › ')}
                  target={where?.target}
                />
              )
            })}
          </ul>
        </section>
      )}
    </div>
  )
}
