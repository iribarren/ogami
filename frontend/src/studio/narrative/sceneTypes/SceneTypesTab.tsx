import { useId } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'

import { ItemDialog } from '../ItemDialog'
import { sceneTypeForm } from '../itemForms'
import { NarrativeGate } from '../NarrativeGate'
import { DeleteItemDialog, UsedBy } from '../NarrativeTab'
import { useGameSystemNarrative, type NarrativeItem } from '../useGameSystemNarrative'
import { sceneTypeToDraft, scenePartNames } from './sceneTypeDraft'

/** The Scene Types of a draft in stored order, with where each is used; each opens its editor page. */
export function SceneTypesTab({ gameSystemKey }: { gameSystemKey: string }) {
  const narrative = useGameSystemNarrative(gameSystemKey)

  return (
    <NarrativeGate narrative={narrative}>
      {(data) => (
        <section aria-label="Scene Types" className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="font-heading text-lg font-semibold">Scene Types</h2>
            <ItemDialog
              gameSystemKey={gameSystemKey}
              form={sceneTypeForm}
              version={data.version}
              original={null}
              initial={sceneTypeForm.blank()}
              trigger={<Button size="sm">New Scene Type</Button>}
            />
          </div>
          {data.sceneTypes.length === 0 ? (
            <p className="text-muted-foreground">This draft has no Scene Types yet.</p>
          ) : (
            <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
              {data.sceneTypes.map((item) => (
                <SceneTypeRow
                  key={item.key}
                  gameSystemKey={gameSystemKey}
                  item={item}
                  version={data.version}
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
}: {
  gameSystemKey: string
  item: NarrativeItem
  version: number
}) {
  const reasonId = useId()
  const draft = sceneTypeToDraft(item.definition)
  const { name } = item.definition
  const title = typeof name === 'string' && name !== '' ? name : item.key

  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="font-medium">{title}</span>
          <span className="font-mono text-sm break-all text-muted-foreground">{item.key}</span>
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
