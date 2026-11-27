import { screen } from '@testing-library/react'

import { miniRelease } from '@/shared/print/testRelease'
import { renderWithApi } from '@/test/renderWithApi'

import { useReleaseContent } from './useReleaseContent'

function Probe() {
  const { data } = useReleaseContent('mini', 3)
  return data === undefined ? null : (
    <p data-testid="probe">{`${data.release.systemName}|${JSON.stringify(data.content)}`}</p>
  )
}

describe('useReleaseContent', () => {
  it('gives the release the reader read, and the raw content it was read from', async () => {
    const raw = miniRelease()
    renderWithApi(<Probe />, {
      'GET /api/studio/game-systems/mini/releases/3/content': () =>
        Response.json({ gameSystemKey: 'mini', version: 3, content: raw, cardImages: [] }),
    })

    expect((await screen.findByTestId('probe')).textContent).toBe(`Mini|${JSON.stringify(raw)}`)
  })
})
