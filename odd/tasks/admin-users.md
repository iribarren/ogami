# Feature: admin-users

- **Locator:** `odd/tasks/admin-users.md` · Engram topic `odd/admin-users/tasks`
- **Issue:** #197 · **Branch:** `feat/admin-users-1-user-lifecycle` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-library` (PRs #186–#194)

## Objective

Roadmap feature 19 (milestone M5). The owner manages users in Admin: lists them, invites by email (Mailpit in dev), sets roles, disables and enables accounts, and sends password reset links. Anyone can ask for a password reset from the login page. Admin calls Identity & Access application services ([ADR 0034](../../docs/adr/0034-admin-user-management.md), ADR 0006, context map).

Out of scope:

- public registration (invite-only);
- deleting accounts, changing emails;
- app settings;
- email verification beyond the invitation link;
- async mail transport (mail is sent synchronously after commit).

## Problem and why

- An account exists only through the console (`app:user:create`), and nothing can change a user: roles, password or access. The owner's Admin area is a placeholder.
- `identity-auth` deferred registration, user management and password reset to this feature.

## Model summary (today)

- **User** (`backend/src/Identity/Domain/User.php`): `final readonly`, only `register()`; fields id, email, passwordHash, roles (list of `Role`). Value objects `Email` (trimmed, lowercased), `UserId`; `Role` enum `SOLO_PLAYER`/`GAME_MANAGER`/`OWNER`, no hierarchy.
- **Port** `UserRepository`: `save`, `ofId`, `ofEmail` only (no listing). Doctrine table `identity_user` (id guid, email unique, password_hash, roles json), XML mapping `Infrastructure/Persistence/Doctrine/Mapping/User.orm.xml`. `save` flushes and maps the unique violation to `EmailAlreadyInUse` (known follow-up: the EntityManager is closed afterwards).
- **Application:** `CreateUser`/`GetUser` + handlers, `UserView` (id, email, roles), ports `PasswordHasher` (hash only), `UserIdGenerator`, `AuthenticatedUser`. The command bus wraps each command in a Doctrine transaction. Every other context has its own `Clock` port (`Play/Application/Clock.php`, `Studio/Application/Clock.php`); Identity has none yet.
- **Security** (`config/packages/security.yaml`): session cookie, `json_login` with `login_throttling` (5/min, `NormalizedLoginRateLimiter`), `IdentityUserProvider::refreshUser` reloads the user by id on every request, `SecurityUser` (roles → `ROLE_*`; no `EquatableInterface`, so a changed hash or roles ends the session), no UserChecker. `access_control` has no `/api/admin` rule.
- **HTTP:** `AuthController` (`/api/auth/login|logout|me`). No Admin backend (`backend/src/Admin/` does not exist, but PHPat already lists it and lets it use only `Identity\Application`).
- **Mail:** none. `MAILER_DSN=smtp://mailpit:1025` is set on the php service in `compose.yaml`; `symfony/mailer` is not installed. `symfony/rate-limiter` and `symfony/uid` are installed.
- **Frontend:** `/admin` route guarded by `requireRole('OWNER')`, placeholder `AdminHomePage`. `LoginPage`, `shared/auth/` (`useCurrentUser`, `requireRole`, `safeRedirect`). UI kit: badge, button, card, dialog, input, label, native-select, segmented-control…; no table, checkbox or form library.
- **Tests:** Behat suite `identity` on in-memory fakes (`tests/Support/Identity/`), API tests with `WebTestCase` and a per-file `signIn()`. `make e2e-seed` creates a player and a game manager, no owner. Each e2e spec copies its own `signIn`.

## Decisions

| Question | Decision |
|---|---|
| Sign-up (user, 2026-10-18) | **Invite-only.** No public registration; the console stays for the first owner |
| Reset (user, 2026-10-18) | **Self-service + owner.** "Forgot password" on the login page, and the owner can send a reset link from the user list |
| Removal (user, 2026-10-18) | **Disable only.** No delete; a disabled user's content stays |
| User lifecycle (Claude) | `User` stops being readonly. New fields `status` (`UserStatus` enum `active`/`disabled`) and `createdAt`. Methods `changeRoles(list<Role>)` (at least one, de-duplicated, `UserMustHaveARole`), `disable()`, `enable()` (idempotent), `changePassword(string $hash)` (non-empty). Migration adds `status` (default `active`) and `created_at` (existing rows get the migration time); legacy rows read as active |
| Own account (Claude) | `ChangeUserRoles` and `DisableUser` take the acting user's id. The owner cannot disable themselves or remove their own `OWNER` role: `OwnAccountProtected` (409). That keeps at least one active owner |
| Disabled sign-in (Claude) | An Identity `UserChecker` refuses a disabled user before authentication with the same generic 401 as bad credentials. `IdentityUserProvider::refreshUser` treats a disabled user as gone, so the session ends on the next request |
| User queries (Claude) | `UserRepository::all()` (ordered by email). `ListUsers` returns `UserView` gaining `status` and `createdAt`. `GET /api/auth/me` is unchanged |
| Invitation (Claude) | Aggregate `Invitation` in Identity: id, email, roles, tokenHash, invitedBy (UserId), createdAt, expiresAt (createdAt + 7 days), status `pending`/`accepted`/`revoked`. Commands `InviteUser {email, roles, invitedBy}` (refuses an email with an account: `EmailAlreadyInUse` 409; with a pending, unexpired invitation: `InvitationAlreadyPending` 409; an expired pending one is replaced), `ResendInvitation` (new token and expiry; only pending, else `InvitationNotFound` 404), `RevokeInvitation` (pending → revoked; else 404), `AcceptInvitation {token, password}` (creates the active user with the roles, marks accepted, all in one transaction). Queries `ListInvitations` (pending, with `expired` flag), `LookupInvitation {token}` (email, roles, expiresAt) |
| Password reset (Claude) | Aggregate `PasswordReset`: id, userId, tokenHash, createdAt, expiresAt (1 hour), usedAt. `RequestPasswordReset {email}` (self-service; unknown or disabled email does nothing, silently), `SendPasswordReset {userId}` (owner; 404 unknown, `UserDisabled` 409). A new reset deletes the user's older ones. `ResetPassword {token, password}` changes the hash, marks it used, deletes the user's other resets; refused for a disabled user |
| Tokens (Claude) | Port `TokenGenerator` (Application) returns 32 random bytes as base64url; only `sha256` hex of the token is stored (unique index). Lookup is by hash. Unknown, expired, used, accepted or revoked → `InvalidAccountLink` (one generic error, 422 "This link is invalid or has expired") |
| Password policy (Claude) | Through a link: 8–4,096 characters, else `WeakPassword` (422). `app:user:create` keeps its current rule |
| Mail (Claude) | Install `symfony/mailer` only (no Twig). Identity Application port `AccountMailer` (`sendInvitation(email, link, roles, expiresAt)`, `sendPasswordReset(email, link, expiresAt)`); Infrastructure adapter builds `Symfony\Component\Mime\Email` text + inline HTML. Links use env `APP_PUBLIC_URL` (default `http://localhost:8080`): `/accept-invitation#token=…`, `/reset-password#token=…`. Mail is sent after the command's transaction commits (handler dispatches a mail message with `DispatchAfterCurrentBusStamp`, or equivalent). A transport failure answers 503 `MailUnavailable`; the invitation or reset stays |
| Clock (Claude) | Identity gets its own `Clock` port and adapter, like Play and Studio |
| Rate limits (Claude) | `RequestPasswordReset` endpoint: 3 per 15 minutes per normalized email and 10 per hour per IP (`framework.rate_limiter`); over the limit still answers 202 and sends nothing. Token endpoints need no limiter (256-bit tokens) |
| Public API (Claude) | Identity `AuthController` (public in `access_control`): `POST /api/auth/password-reset-requests {email}` → 202; `POST /api/auth/password-resets {token, password}` → 204; `POST /api/auth/invitations/lookup {token}` → 200 `{email, roles, expiresAt}`; `POST /api/auth/invitations/accept {token, password}` → 204. The SPA then signs in with the email and password (accept) or shows the login page (reset). 400/415/422 follow the existing error shape |
| Admin API (Claude) | New `backend/src/Admin/Infrastructure/Http/` controllers dispatching Identity commands and queries; `access_control` `^/api/admin` → `ROLE_OWNER`. `GET /api/admin/users`; `PUT /api/admin/users/{id}/roles {roles}`; `POST /api/admin/users/{id}/disable`, `/enable`; `POST /api/admin/users/{id}/password-reset` → 202; `GET /api/admin/invitations`; `POST /api/admin/invitations {email, roles}` → 201; `POST /api/admin/invitations/{id}/resend`; `DELETE /api/admin/invitations/{id}`. 404 unknown user/invitation, 409 `OwnAccountProtected`/`EmailAlreadyInUse`/`InvitationAlreadyPending`/`UserDisabled`, 422 unknown role or no role, 503 mail. No optimistic lock: user rows are tiny and last write wins |
| Frontend (Claude) | `/admin` becomes the Users page: users table (email, role badges, status, created), Edit roles dialog (three checkboxes), Disable/Enable with confirm, Send reset link; the owner's own row has no Disable and a locked `OWNER`. Invitations section: pending list (email, roles, expires, expired badge), Invite dialog (email + roles), Resend, Revoke. Public routes `/forgot-password` (linked from the login page), `/reset-password`, `/accept-invitation` read the token from the fragment. Add the shadcn checkbox (radix is already a dependency) |
| e2e (Claude) | `make e2e-seed` adds an owner. A Mailpit API helper (from the playwright container) reads the latest email to an address. Scenarios: invite → accept → sign in; disable → sign-in refused; forgot password → reset → sign in |

## Design

### Backend (slices 1–4)

- Slice 1: user lifecycle (status, roles, password, createdAt), migration, `all()`, `UserChecker`, provider refresh, `ListUsers`, `ChangeUserRoles`, `DisableUser`, `EnableUser`, Identity `Clock`; Behat scenarios.
- Slice 2: mailer install and port, `TokenGenerator`, `APP_PUBLIC_URL`, `Invitation` aggregate and persistence, invitation commands and queries; Behat scenarios.
- Slice 3: `PasswordReset` aggregate and persistence, reset commands, rate limiters, the four public `/api/auth/…` endpoints, API tests, `make api`.
- Slice 4: Admin controllers, `^/api/admin` access control, API tests for every status, `make api`.

### Frontend (slices 5–6)

- Slice 5: Admin Users page and Invitations section, hooks, tests, stories.
- Slice 6: public forgot/reset/accept pages, login link, e2e seed owner, Mailpit helper, Playwright scenarios, feature close.

## Constraints

- Domain stays framework-free; Admin uses only `Identity\Application` (PHPat). Admin never imports the `Role` enum: roles cross as strings.
- Tokens never logged, never stored in clear, never in a query string.
- Every new command is all-or-nothing on the command bus transaction; mail goes out only after commit.
- Frontend uses only the generated client; new UI component only the shadcn checkbox (ADR 0004).

## Slice plan

Forecasts include tests, docs and generated files. The hard ceiling is about 1,700 lines per slice; the reviewer refuses about 1,850. Each slice's first commit carries the previous slice's review fixes. A slice with API changes commits the regenerated `openapi.json` / `schema.d.ts` on their own.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/admin-users-1-user-lifecycle` | `feat(identity): admin-users 1/6 user lifecycle` | T1, T2, T3 | ~1,200 |
| 2 | `feat/admin-users-2-invitations` | `feat(identity): admin-users 2/6 invitations and mail` | T4, T5 | ~1,500 |
| 3 | `feat/admin-users-3-password-reset` | `feat(identity): admin-users 3/6 password reset and public account links` | T6, T7 | ~1,600 incl. ~350 generated |
| 4 | `feat/admin-users-4-admin-api` | `feat(admin): admin-users 4/6 admin API` | T8 | ~1,500 incl. ~450 generated |
| 5 | `feat/admin-users-5-admin-ui` | `feat(admin): admin-users 5/6 users and invitations in Admin` | T9 | ~1,500 |
| 6 | `feat/admin-users-6-account-pages-e2e` | `feat(identity): admin-users 6/6 account pages and end to end` | T10, T11 | ~1,300 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0034 and index row; glossary User (active/disabled), Invitation, Password reset; context map Identity & Access and Admin | inline (parent: design authored this session) | [x] | |
| T2 | 1 | Domain + persistence: mutable `User` with `UserStatus`, `createdAt`, `changeRoles`, `disable`/`enable`, `changePassword`; migration (`status` default active, `created_at`); mapping; `UserRepository::all()`; Identity `Clock` port + adapter; legacy rows read as active; fakes updated | delegated writer (slice 1) | [ ] | |
| T3 | 1 | Application + security: `ListUsers`, `ChangeUserRoles`, `DisableUser`, `EnableUser` (actor id, `OwnAccountProtected`, `UserNotFound`, `UnknownRole`); `UserView` gains status and createdAt; `UserChecker` refuses disabled users (generic 401); `refreshUser` drops disabled users; Behat scenarios; integration test that a disabled user's session ends | delegated writer (slice 1) | [ ] | |
| T4 | 2 | Mail + tokens: install `symfony/mailer`, `AccountMailer` port and adapter (text + HTML), `APP_PUBLIC_URL`, `TokenGenerator` port and adapter, after-commit mail dispatch, `MailUnavailable` | delegated writer (slice 2) | [ ] | |
| T5 | 2 | Invitations: `Invitation` aggregate, repository + migration (token hash unique), `InviteUser`, `ResendInvitation`, `RevokeInvitation`, `AcceptInvitation`, `ListInvitations`, `LookupInvitation`, `InvalidAccountLink`, `WeakPassword`; Behat scenarios; Mailpit-free mailer test with a spy | delegated writer (slice 2) | [ ] | |
| T6 | 3 | Password reset: `PasswordReset` aggregate, repository + migration, `RequestPasswordReset`, `SendPasswordReset`, `ResetPassword`; rate limiters; Behat scenarios | delegated writer (slice 3) | [ ] | |
| T7 | 3 | Public HTTP: `/api/auth/password-reset-requests`, `/password-resets`, `/invitations/lookup`, `/invitations/accept`; `access_control`; API tests for every status (incl. rate limit and disabled user); `make api` | delegated writer (slice 3) | [ ] | |
| T8 | 4 | Admin HTTP: users (list, roles, disable, enable, password reset) and invitations (list, invite, resend, revoke) controllers; `^/api/admin` → `ROLE_OWNER`; API tests for every status incl. 403 for non-owners; `make api` | delegated writer (slice 4) | [ ] | |
| T9 | 5 | Frontend Admin: Users page (table, roles dialog, disable/enable confirm, send reset link, own-row guards), Invitations section (list, invite dialog, resend, revoke), shadcn checkbox, hooks, tests, stories | delegated writer (slice 5) | [ ] | |
| T10 | 6 | Frontend public pages: `/forgot-password`, `/reset-password`, `/accept-invitation` (token from the fragment), login page link; tests, stories | delegated writer (slice 6) | [ ] | |
| T11 | 6 | e2e: seed owner, Mailpit helper, invite → accept → sign in, disable → refused, forgot → reset → sign in; feature doc close | delegated writer (slice 6) | [ ] | |

## Progress

- 2026-10-18: Feature started. Explored Identity (User, repository, security config, provider), the empty Admin context and its PHPat rules, mail (Mailpit only), the SPA auth code and test helpers. The user chose invite-only accounts, self-service plus owner password reset, and disable without delete. Issue #197.
- 2026-10-18: T1 done. Added ADR 0034 and its index row, the glossary entries User (status), Invitation and Password reset, and updated the context map.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T2–T3 on `feat/admin-users-1-user-lifecycle`.
