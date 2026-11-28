import { miniRelease } from '@/shared/print/testRelease'
import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import type { Campaign } from '../campaigns/useCampaigns'

const mini = miniRelease()

// The release plays the flow "tour" of the campaign fixtures, with the phases "road" and "end".
const release = {
  gameSystemKey: 'free-journal',
  version: 2,
  publishedAt: '2026-10-01T12:00:00+00:00',
  content: { ...mini, flows: mini.flows.map((flow) => ({ ...flow, key: 'tour', name: 'Tour' })) },
  cardImages: [],
}

/**
 * The table of `campaign`. `commands` answer FlowRun requests by their path under the campaign;
 * `sent` collects each request body (undefined without one); `journal` is what the journal holds, or the handler that answers it.
 */
export function renderTableOf(
  campaign: Campaign,
  commands: Record<string, FakeHandler> = {},
  journal: unknown[] | FakeHandler = [],
) {
  const path = `/api/campaigns/${campaign.id}`
  const sent: Record<string, unknown> = {}
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/release`]: () => Response.json(release),
    [`GET ${path}/journal`]: typeof journal === 'function' ? journal : () => Response.json(journal),
  }
  for (const [name, handler] of Object.entries(commands)) {
    handlers[`POST ${path}/${name}`] = async (request) => {
      const text = await request.clone().text()
      sent[name] = text === '' ? undefined : JSON.parse(text)
      return handler(request)
    }
  }
  return { ...renderAppAt(`${path.replace('/api', '/play')}/table`, handlers), sent }
}
