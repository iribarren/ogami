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
| T2 | Infrastructure: security-bundle, uid, Doctrine XML mapping + migration, repository, hasher/id adapters, `app:user:create` console command; test DB migrated + isolated; integration tests | 1 | delegated writer (multi-file) | [x] | `0a55cc9` |
| T3 | Auth API: firewall `json_login` `/api/auth/login`, logout `/api/auth/logout`, `GET /api/auth/me` (query), JSON 401/403, access control; OpenAPI docs + `make api`; integration tests + Behat scenario | 2 | delegated writer (multi-file) | [x] | `52d872c` |
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

- **Slice 1 reviews**: approved — T1 `74d356e`, T2 `cbbee0c` (native review).
- **T2** (`0a55cc9`): `symfony/security-bundle`, `symfony/uid`, dev `dama/doctrine-test-bundle` (PHPUnit extension in `phpunit.dist.xml`, each test rolled back). `security.yaml`: `auto` hasher keyed on `App\Identity\Infrastructure\Security\SecurityUser` (low cost `when@test`), placeholder in-memory provider and lazy `main` firewall only. Adapters: `DoctrineUserRepository`, `SymfonyPasswordHasher`, `UuidUserIdGenerator` (UUID v7), `SecurityUser` (`ROLE_<VALUE>`, identifier = email). XML mapping `identity_user` (`id` UUID PK, `email` unique `uniq_identity_user_email`, `password_hash`, `roles` JSON); migration `Version20261005090401` generated with `doctrine:migrations:diff` and reviewed; `doctrine:schema:validate` OK. Handlers re-tagged (`CommandHandler`/`QueryHandler`). Console `app:user:create` (email, repeatable required `--role`, `--password` or hidden prompt asked twice; non-interactive without password fails). `make backend-test` now migrates the test DB. About 725 authored lines (lock files and generated `config/reference.php` excluded).
  - Review-finding fixes: (1) `CreateUserHandler` rejects an id already in use (`UserIdAlreadyInUse`) instead of overwriting; (2) `DoctrineUserRepository::save` flushes immediately and translates the unique-index violation on email into `EmailAlreadyInUse`, so the translation happens inside the handler, not in the `doctrine_transaction` middleware's flush; proven through the command bus with a racing repository that skips the email pre-check.
  - RED: 39 Identity tests, 13 errors, 1 failure → GREEN. `make backend-qa`: PHPStan max + PHPat no errors, CS-Fixer 0 files, Rector clean. `make backend-test`: PHPUnit `OK (61 tests, 2577 assertions)`, Behat 4 scenarios passed. Manual: `app:user:create demo@example.com --role=SOLO_PLAYER --password=secret123` → created with UUID v7 id, bcrypt hash stored; rerun → `[ERROR] A user with email "demo@example.com" already exists.` (exit 1).

  - Follow-up (non-blocking, from the T2 review) **R3-closed-entity-manager**: after a unique-constraint violation in `DoctrineUserRepository::save` the EntityManager is closed; fine per request/console run, revisit if a long-running worker ever creates users.
- **T3** (`52d872c`, branch `feat/identity-auth-2-api`): `symfony/rate-limiter`. `IdentityUserProvider` (load by normalized email, malformed email → not found; refresh reloads by id so deleted users lose the session and role changes end it). `main` firewall: `json_login` on route `api_auth_login` (`email`/`password`), JSON success handler (200 + current user) and failure handler (401 `{"error":"Invalid credentials."}` for wrong password, unknown or malformed email; 429 `{"error":"Too many login attempts. Try again later."}`), `login_throttling` 5 failures/min per email+IP (25 per IP), logout on route `api_auth_logout` (POST only) answered 204 by `JsonLogoutListener`, JSON entry point 401 `{"error":"Authentication required."}`, access-denied handler 403 `{"error":"Access denied."}`. `access_control`: login, health and `doc.json` public; every other `^/api` path `IS_AUTHENTICATED`; non-API paths unguarded. Session cookie HttpOnly, SameSite=Lax, Secure=auto. `AuthController`: `login` (only reached for non-JSON bodies → 415 `{"error":"Send the credentials as JSON."}`), documented `logout` stub, `GET /api/auth/me` → `GetUser` → `CurrentUserResponse` (401 if the user vanished). OpenAPI: operationIds `login`, `logout`, `getCurrentUser`; schemas `LoginRequest`, `CurrentUserResponse`, `ErrorResponse`; spec and `schema.d.ts` regenerated. Behat suite `identity` (`features/identity/user_account.feature`, `IdentityContext` on in-memory fakes).
  - Note: unknown `/api/*` paths 404 at routing before the firewall runs, so secure-by-default is proven on the access map (`security.access_map`) plus `/api/auth/me`.
  - RED: `AuthApiTest` 11 tests, 10 failures (no routes, no access control) → GREEN. `make backend-qa`: PHPStan max + PHPat no errors, CS-Fixer 0 files, Rector clean. `make backend-test`: PHPUnit `OK (79 tests, 2642 assertions)`, Behat 8 scenarios passed. `make api-check`: up to date. Manual curl on http://localhost:8080 with `t3@example.com` (GAME_MANAGER): login 200 + `Set-Cookie: PHPSESSID=…; path=/; httponly; samesite=lax` + user body → me 200 → logout 204 (cookie deleted) → me 401; wrong password 401; form-encoded login 415; health 200.
  - About 880 authored lines (lock files and generated API files excluded), about half tests; above the ~400 heuristic because the firewall, its JSON handlers and the endpoint contract only make sense together.

## Next step
T4 on `feat/identity-auth-3-spa` (cut from `feat/identity-auth-2-api`).
