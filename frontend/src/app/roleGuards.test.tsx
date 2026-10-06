import { screen, waitFor } from '@testing-library/react'

import { renderAppAt, signedInAs, type FakeHandler } from '@/test/renderApp'

// What each area's home page requests besides the signed-in user.
const areaRequests: Record<string, Record<string, FakeHandler>> = {
  '/play': {
    'GET /api/campaigns': () => Response.json([]),
    'GET /api/play/game-systems': () => Response.json([]),
  },
}

describe('role-guarded areas', () => {
  it.each([
    ['/play', 'Play'],
    ['/studio', 'Studio'],
    ['/admin', 'Admin'],
  ])(
    'sends anonymous visitors of %s to the sign-in page and remembers where they were',
    async (path) => {
      const { router } = renderAppAt(path)

      expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
      expect(router.state.location.pathname).toBe('/login')
      expect(router.state.location.search).toEqual({ redirect: path })
    },
  )

  it.each([
    ['/play', 'SOLO_PLAYER', 'Play'],
    ['/studio', 'GAME_MANAGER', 'Studio'],
    ['/admin', 'OWNER', 'Admin'],
  ] as const)('opens %s for a user with the %s role', async (path, role, heading) => {
    const { fakeApi } = renderAppAt(path, {
      ...areaRequests[path],
      'GET /api/auth/me': signedInAs(role),
    })

    expect(await screen.findByRole('heading', { level: 1, name: heading })).toBeInTheDocument()
    await waitFor(() => {
      expect(fakeApi.requests).toEqual(
        expect.arrayContaining(Object.keys(areaRequests[path] ?? {})),
      )
    })
  })

  it.each([
    ['/play', 'GAME_MANAGER', 'Play'],
    ['/studio', 'SOLO_PLAYER', 'Studio'],
    ['/admin', 'GAME_MANAGER', 'Admin'],
  ] as const)(
    'shows a forbidden page on %s to a user with only the %s role',
    async (path, role, area) => {
      const { router } = renderAppAt(path, { 'GET /api/auth/me': signedInAs(role) })

      expect(
        await screen.findByRole('heading', { level: 1, name: 'Access denied' }),
      ).toBeInTheDocument()
      expect(screen.getByText(`You don't have access to ${area}.`)).toBeInTheDocument()
      expect(router.state.location.pathname).toBe(path)
    },
  )

  it('keeps the landing page public', async () => {
    renderAppAt('/')

    expect(await screen.findByRole('heading', { level: 1, name: 'Ogami' })).toBeInTheDocument()
  })
})
