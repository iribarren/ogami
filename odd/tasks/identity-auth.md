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
| T3b | T3 review follow-ups: login timing enumeration, role enum from `Role`, throttle key normalization, trusted proxies | 2 | inline (bounded writer of T3) | [x] | `79990f4` |
| T4 | SPA: current-user query, `/login` page, `beforeLoad` role guards on `/play`, `/studio`, `/admin`, forbidden view, logout in `AppShell`; Vitest | 3 | delegated writer (multi-file) | [x] | `7a515a0` |
| T4b | T4 review follow-ups: logout clears all cached queries, logout failure reported (401 counts as signed out), `safeRedirect` rejects control characters/whitespace and checks the resolved origin, `/login` renders when `/api/auth/me` fails | 3 | inline (bounded writer of T4) | [x] | `adf17cd` |
| T5 | E2E + CI + docs: seeded e2e user, Playwright login and guard specs (smoke updated), CI seeding step, README/context docs | 3 | delegated writer (multi-file) | [x] | `bb85303` (+ docs commit) |

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

- **T3 review**: approved (native review, 4 non-blocking warnings → T3b); reviewed boundary `8fd8f82`.
- **T3b** (`79990f4`):
  - R1 login timing enumeration: `UnknownUserPasswordCheckListener` on `CheckPassportEvent` (main firewall, priority 512: after the user loader is set, before `UserCheckerListener` at 256, the first listener that loads the user). On `UserNotFoundException` it verifies the presented password against a dummy hash from the `SecurityUser` hasher, then rethrows. The dummy hash is made once and cached in `cache.app` (hashing costs as much as verifying). Dev curl: unknown email ~0.5 s, wrong password ~0.5 s (before: 0.04 s vs 0.5 s). A first attempt at priority 1 never ran, because `UserCheckerListener` already threw; the behavioral integration test caught it.
  - R2 role enum: `CurrentUserResponse.roles` items reference `new Model(type: Role::class)`; Nelmio describes the backed enum as a named `Role` schema (`swagger-php` `enum: Role::class` was dumped as a literal class string by Nelmio, so not used). `schema.d.ts`: `components["schemas"]["Role"]` = `"SOLO_PLAYER" | "GAME_MANAGER" | "OWNER"`, `roles: components["schemas"]["Role"][]`.
  - R3 throttle key: Symfony's `DefaultLoginRateLimiter` lowercases but does not trim, so `" ada@example.com"` got a fresh allowance (RED: 200 instead of 429). `NormalizedLoginRateLimiter` decorates `security.login_throttling.main.limiter` and trims + lowercases the username like `Email`.
  - R4 shared proxy IP: `framework.trusted_proxies: '%env(default::TRUSTED_PROXIES)%'`, `trusted_headers` X-Forwarded-For/Host/Proto/Port/Prefix; `TRUSTED_PROXIES=` (empty) in `backend/.env` with a comment. Production behind a proxy must set it (deployment requirement).
  - RED: 4 unit errors (listener missing) + 1 integration failure (throttle bypass), then the dummy-hash integration test failing at priority 1 → GREEN. `make backend-qa`: PHPStan + PHPat no errors, CS-Fixer 0 files, Rector clean. `make backend-test`: PHPUnit `OK (85 tests, 2657 assertions)`, Behat 8 scenarios passed. `make api-check`: up to date.

- **T3b review**: approved (native review); reviewed boundary `8d8254f`. Non-blocking follow-ups:
  - R2-normalization-duplicated: `NormalizedLoginRateLimiter` duplicates `Email` normalization.
  - R4-stale-dummy-hash: the cached dummy hash isn't refreshed if the hasher config changes (clear the cache on hasher change).
- **T4** (`7a515a0`), branch `feat/identity-auth-3-spa`:
  - `shared/auth/`: `currentUserQueryOptions(api)` (`GET /api/auth/me`, 401 → `null`, other errors throw), `loadCurrentUser` for guards (`queryClient.query` with `staleTime: 'static'`; `ensureQueryData` is deprecated), `useCurrentUser`, `useLogin` (sets the cached user; throws `LoginError` with the API `error` message), `useLogout` (removes every other query, sets the user to `null`), `requireRole(options, role)` (anonymous → redirect `/login?redirect=<location.href>`; missing role → `ForbiddenError`), `safeRedirect` (only paths starting with `/`, not `//` or `/\`).
  - Router context gains `api` (same client as `ApiClientProvider`, created once in `main.tsx`); `defaultErrorComponent: RouteErrorPage` renders `ForbiddenPage` ("Access denied" / "You don't have access to <Area>.") inside the shell, without redirecting. Guards on `/play` (SOLO_PLAYER), `/studio` (GAME_MANAGER), `/admin` (OWNER).
  - `/login` (`routes/login.tsx` + `app/LoginPage.tsx`, shadcn `input` and `label`): `validateSearch` always returns the `redirect` key (`undefined` when unsafe), because the router merges validated search over the raw one and an omitted key keeps the raw value; signed-in visitors are redirected to `redirect` or their first area. `AppShell` links only permitted areas, shows "Sign in" to anonymous visitors, the email and "Sign out" (→ `/login`) when signed in.
  - shadcn CLI again wrote `import { cn } from "cn"` and added a bogus `cn` dependency; imports fixed to `@/shared/lib/utils`, `package.json`/lock reverted.
  - RED: 20 failed tests + `safeRedirect` module missing → GREEN: `make frontend-test` 5 files, 40 tests passed. `make frontend-qa`: ESLint, Prettier, tsc clean. `make frontend-build`: built. `make api-check`: up to date. Manual: `make console ARGS="app:user:create t4@example.com --role=SOLO_PLAYER --password=…"` OK; curl http://localhost:8080/login and `/studio` 200 serving the SPA; API login 200, me 200, wrong password 401. No browser walk-through (curl only).
  - About 760 authored lines including tests (about half); above the ~400 heuristic because the guards, login page and shell only make sense together.
  - Expected breakage: `frontend/e2e` smoke spec (areas now need a signed-in user) → T5.

- **T4 review**: approved (native review, 5 non-blocking warnings → T4b); reviewed boundary `014837b`.
- **T4b** (`adf17cd`):
  - R2 logout scope: `useLogout` now calls `queryClient.clear()` (all API data is user-scoped), then caches the user as `null`; test seeds a user-scoped query and checks it is gone.
  - R3/R4 silent logout failure: `AppShell` shows the logout error in `role=alert` and keeps the user; a `401` from logout counts as signed out.
  - R3 `safeRedirect`: rejects whitespace and control characters (`/\t/evil.com`, `/\n/evil.com`, leading/trailing spaces, NUL) and accepts only when `new URL(value, origin).origin` matches.
  - R4 `/login` with a failing `/api/auth/me` (500/network): the route treats the visitor as anonymous and renders the form; the current-user query no longer retries (`retry: false`) so guards fail fast.
  - RED: 8 failed tests → GREEN: `make frontend-test` 5 files, 50 tests passed. `make frontend-qa`: ESLint, Prettier, tsc clean.

- **T5** (`bb85303`):
  - `app:user:create --if-missing`: an existing email prints a note ("… already exists. Left unchanged.") and exits 0; otherwise unchanged behavior. RED: 2 errors (option missing) → GREEN: `CreateUserConsoleCommandTest` OK (9 tests).
  - `make e2e-seed` (dev migrations + `e2e-player@example.test` SOLO_PLAYER, `e2e-manager@example.test` GAME_MANAGER, `--if-missing`, password `E2E_PASSWORD ?= e2e-password-123`, exported); `make e2e` depends on it. `compose.yaml` passes `E2E_PASSWORD` to the `playwright` service. CI e2e step renamed; it still runs `make e2e`, which now seeds.
  - Playwright: smoke keeps the public landing page + healthy API, then "Go to Play" → `/login?redirect=%2Fplay`. `auth.spec.ts`: anonymous `/play` → sign in → `/play`; wrong credentials (unknown email, so seeded users never get throttled) → "Invalid credentials."; player nav shows only Play; `/studio` → "Access denied"; sign out → `/login`, `/play` guarded again.
  - `frontend/.gitignore` ignores `.tanstack/`. README: users and sign-in (create user, roles table, `--if-missing`, e2e seed, `TRUSTED_PROXIES`). Context map: Identity & Access published API row.
  - Checks: `make backend-qa` PHPStan + PHPat no errors, CS-Fixer 0 files, Rector clean; `make backend-test` PHPUnit OK (87 tests, 2666 assertions), Behat 8 scenarios passed; `make frontend-qa` clean; `make frontend-test` 50 passed; `make api-check` up to date; `make e2e` 6 passed (users created), second run 6 passed (seed notes "already exists. Left unchanged.").

## Next step
PRs: tracker draft PR + chained child PRs (user decision).
