import { createFileRoute } from '@tanstack/react-router'

import { FlowEditorPage } from '@/studio/narrative/flows/FlowEditorPage'
import { nodeParam } from '@/studio/narrative/flows/flowNode'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/flows/$flow')({
  staticData: { layout: 'wide' },
  // The selected outline node. An unknown value must come back as an explicit undefined: the
  // router merges the validated search over the raw one, so omitting the key would keep the raw value.
  validateSearch: (search: Record<string, unknown>): { node?: string | undefined } => ({
    node: nodeParam(search.node),
  }),
  component: FlowEditorRoute,
})

function FlowEditorRoute() {
  const { key, flow } = Route.useParams()
  const { node } = Route.useSearch()
  return <FlowEditorPage gameSystemKey={key} flow={flow} node={node} />
}
