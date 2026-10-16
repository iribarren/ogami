import { createFileRoute } from '@tanstack/react-router'

import { OracleTableEditorPage } from '@/studio/oracles/OracleTableEditorPage'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/oracle-tables/$table')({
  staticData: { layout: 'wide' },
  component: OracleTableEditorRoute,
})

function OracleTableEditorRoute() {
  const { key, table } = Route.useParams()
  return <OracleTableEditorPage gameSystemKey={key} table={table} />
}
