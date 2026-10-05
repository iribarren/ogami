# Feature: identity-auth

- **Locator:** `odd/tasks/identity-auth.md` · Engram topic `odd/identity-auth/tasks`
- **Issue:** #6 · **Branch:** tracker `feat/identity-auth` (branch point `d87a622` on `main`)
- **Delivery strategy:** `ask-on-risk` → **feature-branch-chain** (user choice) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `bootstrap-monorepo`, delivered in PR #4 (closes #3)

## Objective
Users can sign in with a session cookie, and Play, Studio and Admin are reachable only by users holding the matching role.

## Scope
- In: Identity & Access context (`User` aggregate, roles `SOLO_PLAYER`, `GAME_MANAGER`, `OWNER`), Doctrine persistence + migration, console command to create users, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` (ADR 0006), SPA login page, role-guarded `/play`, `/studio`, `/admin`, logout in the app shell, e2e login flow.
- Out: registration, user management UI, password reset (feature `admin-users`).

## Constraints
- Follow `CLAUDE.md`, ADRs 0002, 0005, 0006, 0009, 0012.
- Roles are **independent** (user choice, ADR 0006): no role hierarchy; each area checks its own role.
- Domain stays framework-free: `UserId`, `Email`, `Role`, password hash as plain values; hashing and id generation are Application ports with Infrastructure adapters.
- Symfony Security lives in `Identity/Infrastructure` (security user adapter, user provider). Other contexts get the current user only through Identity's application layer.
- Unauthenticated API calls get JSON `401`, wrong role `403`; never an HTML redirect.
- Session cookie: HttpOnly, SameSite=Lax, Secure when served over HTTPS. Login accepts JSON only.

## Forecast
About 1,500 authored changed lines, generated files excluded: backend ~900, SPA ~450, e2e/CI/docs ~150.

Chain (feature-branch-chain, tracker PR to `main` stays draft until all slices integrate):

```text
main ← feat/identity-auth (tracker, draft)
         ← feat/identity-auth-1-backend   (T1, T2)
              ← feat/identity-auth-2-api  (T3)
                   ← feat/identity-auth-3-spa (T4, T5)
```

## Tasks
| ID | Task | Slice | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | Domain + Application: `User` aggregate, `UserId`, `Email`, `Role`; `CreateUser` command/handler with ports `UserRepository`, `PasswordHasher`, `UserIdGenerator`; unit tests RED→GREEN | 1 | delegated writer (multi-file) | [x] | `1a2ba38` |
| T2 | Infrastructure: security-bundle, uid, Doctrine XML mapping + migration, repository, hasher/id adapters, `app:user:create` console command; test DB migrated + isolated; integration tests | 1 | delegated writer (multi-file) | [ ] | |
| T3 | Auth API: firewall `json_login` `/api/auth/login`, logout `/api/auth/logout`, `GET /api/auth/me` (query), JSON 401/403, access control; OpenAPI docs + `make api`; integration tests + Behat scenario | 2 | delegated writer (multi-file) | [ ] | |
| T4 | SPA: current-user query, `/login` page, `beforeLoad` role guards on `/play`, `/studio`, `/admin`, forbidden view, logout in `AppShell`; Vitest | 3 | delegated writer (multi-file) | [ ] | |
| T5 | E2E + CI + docs: seeded e2e user, Playwright login and guard specs (smoke updated), CI seeding step, README/context docs | 3 | delegated writer (multi-file) | [ ] | |

## Acceptance criteria
- `make console ARGS="app:user:create <email> --role=SOLO_PLAYER"` creates a user with a hashed password; duplicate email fails clearly.
- Valid credentials on `POST /api/auth/login` return `200` with the current user and set a session cookie; invalid ones return `401` JSON.
- `GET /api/auth/me` returns id, email and roles when signed in, `401` otherwise; `POST /api/auth/logout` ends the session.
- Signed-out visitors to `/play`, `/studio`, `/admin` are sent to `/login` and back after signing in; signed-in users without the role see a forbidden view.
- PHPat rules stay green: Identity Domain is framework-free.
- `make qa`, `make test`, `make e2e` pass; OpenAPI spec and typed client regenerated and committed.

## Checks
- Backend: `make backend-qa`, `make backend-test`
- Frontend: `make frontend-qa`, `make frontend-test`
- Contract: `make api-check`
- E2E: `make e2e`

## Progress / Evidence
- Issue #6 created; tracker branch `feat/identity-auth` cut from `d87a622`.
- **T1** (`1a2ba38`): Identity Domain (`UserId`, `Email`, `Role`, `User`, `UserRepository`, domain exceptions) and Application (`CreateUser`/`CreateUserHandler`, `GetUser`/`GetUserHandler` → `?UserView`, ports `PasswordHasher`, `UserIdGenerator`, exceptions `EmailAlreadyInUse`, `PasswordMustNotBeEmpty`, `UnknownRole`). RED: 23 tests, 15 errors, 8 failures (classes missing) → GREEN: unit suite `OK (47 tests, 2539 assertions)`. `make backend-qa`: PHPStan max + PHPat no errors, CS-Fixer 0 files, Rector clean. `make backend-test`: PHPUnit OK (47 tests), Behat 4 scenarios passed. 863 lines (about half tests).
  - Design: `CommandBus::dispatch()` returns void, so `CreateUser` carries a caller-generated `userId`; the caller (T2 console command) uses `UserIdGenerator`, dispatches, then prints the id.
  - T2 note: the handlers are plain invokables in T1 (no adapters yet, so a bus-tagged handler breaks container compilation); **T2 re-adds `implements CommandHandler`/`QueryHandler` together with the adapters.** `User` is `final readonly` with scalar state for XML mapping: `string $id`, `string $email` (normalized), `string $passwordHash`, `array $roles` (list of role values → `json` column); add a unique constraint on `email`.

## Next step
T2 on `feat/identity-auth-1-backend`.
