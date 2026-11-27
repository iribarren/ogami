import { createFileRoute } from '@tanstack/react-router'

import { SceneTypeEditorPage } from '@/studio/narrative/sceneTypes/SceneTypeEditorPage'
import { carrierParam, effectParam, stepParam } from '@/studio/narrative/sceneTypes/sceneTypeParams'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/scene-types/$sceneType')({
  staticData: { layout: 'wide' },
  // The step the flow Map points at, and a transition to add to it. An unknown value must come back
  // as an explicit undefined: the router merges the validated search over the raw one.
  validateSearch: (
    search: Record<string, unknown>,
  ): { step?: string | undefined; carrier?: string | undefined; effect?: string | undefined } => ({
    step: stepParam(search.step),
    carrier: carrierParam(search.carrier),
    effect: effectParam(search.effect),
  }),
  component: SceneTypeEditorRoute,
})

function SceneTypeEditorRoute() {
  const { key, sceneType } = Route.useParams()
  const { step, carrier, effect } = Route.useSearch()
  return (
    <SceneTypeEditorPage
      gameSystemKey={key}
      sceneType={sceneType}
      step={step}
      carrier={carrier}
      effect={effect}
    />
  )
}
