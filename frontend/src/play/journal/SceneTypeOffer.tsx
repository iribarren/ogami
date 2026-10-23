import { Button } from '@/shared/ui/button'

import { useSwitchSceneType, type Campaign } from '../campaigns/useCampaigns'
import type { JournalEntry } from './useJournal'

/** A scene as the campaign lists it, which tells a hook Scene and a Scene Type. */
export type ListedScene = Campaign['sessions'][number]['scenes'][number]

/**
 * "Switch to <Scene Type>" for the Scene Type the latest step of a rolled oracle table offers,
 * unless the scene already has it. Only offered in the current scene (a hook Scene has no type).
 */
export function SceneTypeOffer({
  campaignId,
  entry,
  scene,
  sceneTypes,
}: {
  campaignId: string
  entry: JournalEntry
  scene: ListedScene | undefined
  sceneTypes: Campaign['sceneTypes']
}) {
  const switchSceneType = useSwitchSceneType(campaignId)
  if (entry.content.kind !== 'oracle-table' || scene?.kind !== 'scene') {
    return null
  }
  const offered = entry.content.steps.findLast((step) => step.sceneType !== null)?.sceneType
  const target = sceneTypes.find(({ key }) => key === offered)
  if (target === undefined || target.key === scene.sceneType) {
    return null
  }

  return (
    <div className="space-y-1">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={switchSceneType.isPending}
        onClick={() => {
          switchSceneType.mutate(target.key)
        }}
      >
        Switch to {target.name}
      </Button>
      {switchSceneType.error && (
        <p role="alert" className="text-sm text-destructive">
          {switchSceneType.error.message}
        </p>
      )}
    </div>
  )
}
