import { createFileRoute } from '@tanstack/react-router'

import { SceneTypeEditorPage } from '@/studio/narrative/sceneTypes/SceneTypeEditorPage'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/scene-types/$sceneType')({
  staticData: { layout: 'wide' },
  component: SceneTypeEditorRoute,
})

function SceneTypeEditorRoute() {
  const { key, sceneType } = Route.useParams()
  return <SceneTypeEditorPage gameSystemKey={key} sceneType={sceneType} />
}
