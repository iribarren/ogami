# 0006. Session cookie authentication, same origin, role model

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The SPA and API are served from the same origin. There is no third-party API client and no mobile app. Users have clearly separated roles.

## Decision

- **Symfony Security with a session cookie** (HttpOnly, Secure, SameSite). No JWT.
- SPA and API share **one origin**, so no CORS setup is needed.
- **Roles:**

| Role | Access |
|---|---|
| `SOLO_PLAYER` | Play |
| `GAME_MANAGER` | Studio (game systems, sheets, checks, flows, oracles) |
| `OWNER` | Admin (users, roles, app settings) |

- A user may hold several roles. Roles are managed in Identity & Access.

## Consequences

- Simple, well-understood security with built-in CSRF protection options.
- No token refresh logic in the SPA.
- A future external client would need another auth mechanism (new ADR).
