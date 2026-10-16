import { createFileRoute } from '@tanstack/react-router'

import { LikelihoodOracleEditorPage } from '@/studio/oracles/LikelihoodOracleEditorPage'

// `$key_` opts out of nesting under the GameSystem page: the editor is a page of its own.
export const Route = createFileRoute('/studio/game-systems/$key_/likelihood-oracles/$oracle')({
  staticData: { layout: 'wide' },
  component: LikelihoodOracleEditorRoute,
})

function LikelihoodOracleEditorRoute() {
  const { key, oracle } = Route.useParams()
  return <LikelihoodOracleEditorPage gameSystemKey={key} oracle={oracle} />
}
