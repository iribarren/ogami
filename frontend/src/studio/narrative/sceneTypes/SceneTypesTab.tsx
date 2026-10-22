import { Clapperboard, Plus } from 'lucide-react'
import { useId, useState } from 'react'
import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { IconTile } from '@/shared/ui/icon-tile'

import { ItemDialog } from '../ItemDialog'
import { sceneTypeForm } from '../itemForms'
import { NarrativeGate } from '../NarrativeGate'
import { DeleteItemDialog, UsedBy } from '../NarrativeTab'
import { useGameSystemNarrative, type NarrativeItem } from '../useGameSystemNarrative'
import { sceneTypeToDraft, scenePartNames } from './sceneTypeDraft'

/** The Scene Types of a draft in stored order, with where each is used; each opens its editor page. */
export function SceneTypesTab({ gameSystemKey }: { gameSystemKey: string }) {
  const narrative = useGameSystemNarrative(gameSystemKey)
  // The Scene Type just created: it stays selected on the list, like other creates.
  const [selected, setSelected] = useState<string | null>(null)

  return (
    <NarrativeGate narrative={narrative}>
      {(data) => (
        <section aria-label="Scene Types" className="panel">
          <div className="panel-head">
            <IconTile size="sm">
              <Clapperboard />
            </IconTile>
            <h2 className="font-heading text-panel-title">Scene Types</h2>
            <div className="ml-auto">
            <ItemDialog
              gameSystemKey={gameSystemKey}
              form={sceneTypeForm}
              version={data.version}
              original={null}
              initial={sceneTypeForm.blank()}
              trigger={
                <Button variant="outline" size="sm">
                  <Plus aria-hidden="true" />
                  New Scene Type
                </Button>
              }
              onSaved={(item) => {
                setSelected(String(item.key))
              }}
            />
            </div>
          </div>
          {data.sceneTypes.length === 0 ? (
            <div className="panel-body">
              <div className="empty-state">
                <IconTile size="sm">
                  <Clapperboard />
                </IconTile>
                <p>This draft has no Scene Types yet.</p>
                <p className="hint">Use New Scene Type to add one.</p>
              </div>
            </div>
          ) : (
            <ul>
              {data.sceneTypes.map((item) => (
                <SceneTypeRow
                  key={item.key}
                  gameSystemKey={gameSystemKey}
                  item={item}
                  version={data.version}
                  selected={item.key === selected}
                />
              ))}
            </ul>
          )}
        </section>
      )}
    </NarrativeGate>
  )
}

function SceneTypeRow({
  gameSystemKey,
  item,
  version,
  selected,
}: {
  gameSystemKey: string
  item: NarrativeItem
  version: number
  selected: boolean
}) {
  const reasonId = useId()
  const draft = sceneTypeToDraft(item.definition)
  const { name } = item.definition
  const title = typeof name === 'string' && name !== '' ? name : item.key

  return (
    <li className="list-row" aria-current={selected ? 'true' : undefined}>
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          {draft === null ? (
            <span className="list-row-title">{title}</span>
          ) : (
            <Link
              to="/studio/game-systems/$key/scene-types/$sceneType"
              params={{ key: gameSystemKey, sceneType: item.key }}
              className="list-row-title underline-offset-4 hover:underline"
            >
              {title}
            </Link>
          )}
          <span className="list-row-key break-all">{item.key}</span>
          {draft === null ? (
            <Badge variant="outline">Can&apos;t be edited here</Badge>
          ) : (
            <span className="text-sm text-muted-foreground">
              {scenePartNames
                .map((part) => `${part} ${String(draft.steps[part].length)}`)
                .join(' · ')}
            </span>
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
        section={sceneTypeForm.section}
        noun={sceneTypeForm.noun}
        itemKey={item.key}
        version={version}
        usedBy={item.usedBy}
        reasonId={reasonId}
      />
    </li>
  )
}
