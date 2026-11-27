import { createFileRoute } from '@tanstack/react-router'

import { FlowEditorPage } from '@/studio/narrative/flows/FlowEditorPage'
import { nodeParam, viewParam } from '@/studio/narrative/flows/flowNode'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/flows/$flow')({
  staticData: { layout: 'wide' },
  // The selected outline node, and the tab of the right-hand pane (`map`; absent is the Panel). An
  // unknown value must come back as an explicit undefined: the router merges the validated search
  // over the raw one, so omitting the key would keep the raw value.
  validateSearch: (
    search: Record<string, unknown>,
  ): { node?: string | undefined; view?: 'map' | undefined } => ({
    node: nodeParam(search.node),
    view: viewParam(search.view),
  }),
  component: FlowEditorRoute,
})

function FlowEditorRoute() {
  const { key, flow } = Route.useParams()
  const { node, view } = Route.useSearch()
  return <FlowEditorPage gameSystemKey={key} flow={flow} node={node} view={view} />
}
