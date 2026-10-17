import { createFileRoute } from '@tanstack/react-router'

import { FlowEditorPage } from '@/studio/narrative/flows/FlowEditorPage'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/flows/$flow')({
  staticData: { layout: 'wide' },
  component: FlowEditorRoute,
})

function FlowEditorRoute() {
  const { key, flow } = Route.useParams()
  return <FlowEditorPage gameSystemKey={key} flow={flow} />
}
