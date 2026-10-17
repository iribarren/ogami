import { useId, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'

import { useGameSystemValidation } from '../../useGameSystems'
import { ItemDialog } from '../ItemDialog'
import { flowForm } from '../itemForms'
import { NarrativeGate } from '../NarrativeGate'
import { DeleteItemDialog, UsedBy } from '../NarrativeTab'
import {
  useGameSystemNarrative,
  type GameSystemNarrative,
  type NarrativeItem,
} from '../useGameSystemNarrative'
import { flowToDraft, newFlow, refreshNewFlow } from './flowDraft'
import { flowIssueCount } from './flowIssues'

const sceneTypeOptions = (data: GameSystemNarrative) =>
  data.sceneTypes.map(({ key, definition }) => ({
    key,
    name: typeof definition.name === 'string' && definition.name !== '' ? definition.name : key,
  }))

/**
 * When the narrative last changed: the update time of its data once the data is a new value, 0
 * while it is the one first read. A refetch that finds the same data does not move it.
 */
function useChangedAt(data: GameSystemNarrative | undefined, updatedAt: number): number {
  const [seen, setSeen] = useState(data)
  const [changedAt, setChangedAt] = useState(0)
  if (seen !== data) {
    setSeen(data)
    if (seen !== undefined) {
      setChangedAt(updatedAt)
    }
  }
  return changedAt
}

/**
 * The flows of a draft in stored order, with their phases, the validation issues about each and
 * where each is used; each opens its editor page.
 */
export function FlowsTab({ gameSystemKey }: { gameSystemKey: string }) {
  const narrative = useGameSystemNarrative(gameSystemKey)
  const validation = useGameSystemValidation(gameSystemKey)
  const noSceneTypes = useId()
  const changedAt = useChangedAt(narrative.data, narrative.dataUpdatedAt)
  const navigate = useNavigate()

  return (
    <NarrativeGate narrative={narrative}>
      {(data) => {
        const sceneTypes = sceneTypeOptions(data)
        return (
          <section aria-label="Flows" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-heading text-lg font-semibold">Flows</h2>
              {sceneTypes.length === 0 ? (
                <div className="flex items-center gap-3">
                  <p id={noSceneTypes} className="text-sm text-muted-foreground">
                    Add a Scene Type first.
                  </p>
                  <Button size="sm" disabled aria-describedby={noSceneTypes}>
                    New flow
                  </Button>
                </div>
              ) : (
                <ItemDialog
                  gameSystemKey={gameSystemKey}
                  form={flowForm}
                  version={data.version}
                  original={null}
                  initial={newFlow(sceneTypes)}
                  onSaved={(item) => {
                    void navigate({
                      to: '/studio/game-systems/$key/flows/$flow',
                      params: { key: gameSystemKey, flow: String(item.key) },
                    })
                  }}
                  refresh={(draft, latest) => refreshNewFlow(draft, sceneTypeOptions(latest))}
                  trigger={<Button size="sm">New flow</Button>}
                />
              )}
            </div>
            {data.flows.length === 0 ? (
              <p className="text-muted-foreground">This draft has no flows yet.</p>
            ) : (
              <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
                {data.flows.map((item, index) => (
                  <FlowRow
                    key={item.key}
                    gameSystemKey={gameSystemKey}
                    item={item}
                    version={data.version}
                    // The validation numbers flows by their stored position, which is the order
                    // the narrative read model lists them in. A save refreshes both reads, the
                    // validation after the narrative: until it is read again its positions are
                    // the old ones, so no row shows a count then. A refresh that finds the
                    // narrative as it was keeps the counts.
                    issues={
                      validation.data && validation.dataUpdatedAt >= changedAt
                        ? flowIssueCount(validation.data, index)
                        : 0
                    }
                  />
                ))}
              </ul>
            )}
          </section>
        )
      }}
    </NarrativeGate>
  )
}

function FlowRow({
  gameSystemKey,
  item,
  version,
  issues,
}: {
  gameSystemKey: string
  item: NarrativeItem
  version: number
  issues: number
}) {
  const reasonId = useId()
  const draft = flowToDraft(item.definition)
  const { name } = item.definition
  const title = typeof name === 'string' && name !== '' ? name : item.key

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          {draft === null ? (
            <span className="font-medium">{title}</span>
          ) : (
            <Link
              to="/studio/game-systems/$key/flows/$flow"
              params={{ key: gameSystemKey, flow: item.key }}
              className="font-medium underline-offset-4 hover:underline"
            >
              {title}
            </Link>
          )}
          <span className="font-mono text-sm break-all text-muted-foreground">{item.key}</span>
          {draft === null ? (
            <Badge variant="outline">Can&apos;t be edited here</Badge>
          ) : (
            <>
              {draft.default && <Badge>Default</Badge>}
              <span className="text-sm text-muted-foreground">
                {draft.phases.length === 1 ? '1 phase' : `${String(draft.phases.length)} phases`}
              </span>
            </>
          )}
          {issues > 0 && (
            <Badge variant="outline">{issues === 1 ? '1 issue' : `${String(issues)} issues`}</Badge>
          )}
        </p>
        {draft === null && (
          <p className="text-sm text-muted-foreground">
            It has a shape this editor does not know; it stays as it is until it is deleted.
          </p>
        )}
        <p id={reasonId} className="text-sm">
          <UsedBy paths={item.usedBy} />
        </p>
      </div>
      <DeleteItemDialog
        gameSystemKey={gameSystemKey}
        section={flowForm.section}
        noun={flowForm.noun}
        itemKey={item.key}
        version={version}
        usedBy={item.usedBy}
        reasonId={reasonId}
      />
    </li>
  )
}
