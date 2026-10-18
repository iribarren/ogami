import type { AdminInvitation, AdminUser } from './types'

// Shared by the Admin tests and stories. `signedInAs` (src/test/renderApp.tsx) signs in as `ada`.

/** The signed-in owner: the one row with no Disable action and a locked OWNER role. */
export const ada: AdminUser = {
  id: '0190a8f0-0000-7000-8000-000000000001',
  email: 'ada@example.com',
  roles: ['OWNER', 'GAME_MANAGER'],
  status: 'active',
  createdAt: '2026-10-01T09:00:00+00:00',
}

/** An active solo player. */
export const bob: AdminUser = {
  id: '0190a8f0-0000-7000-8000-000000000002',
  email: 'bob@example.com',
  roles: ['SOLO_PLAYER'],
  status: 'active',
  createdAt: '2026-10-05T09:00:00+00:00',
}

/** A disabled game manager. */
export const cleo: AdminUser = {
  id: '0190a8f0-0000-7000-8000-000000000003',
  email: 'cleo@example.com',
  roles: ['GAME_MANAGER'],
  status: 'disabled',
  createdAt: '2026-10-07T09:00:00+00:00',
}

export const users: AdminUser[] = [ada, bob, cleo]

/** A pending invitation whose link still works. */
export const dan: AdminInvitation = {
  id: '0190a8f0-0000-7000-8000-000000000011',
  email: 'dan@example.com',
  roles: ['SOLO_PLAYER', 'GAME_MANAGER'],
  createdAt: '2026-10-17T09:00:00+00:00',
  expiresAt: '2026-10-24T09:00:00+00:00',
  expired: false,
}

/** A pending invitation whose link has expired: resending renews it. */
export const erin: AdminInvitation = {
  id: '0190a8f0-0000-7000-8000-000000000012',
  email: 'erin@example.com',
  roles: ['SOLO_PLAYER'],
  createdAt: '2026-10-01T09:00:00+00:00',
  expiresAt: '2026-10-08T09:00:00+00:00',
  expired: true,
}

export const invitations: AdminInvitation[] = [dan, erin]
