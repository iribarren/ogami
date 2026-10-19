# Feature: identity-account-error-codes

- **Locator:** `odd/tasks/identity-account-error-codes.md` · Engram topic `odd/identity-account-error-codes/tasks`
- **Issue:** #216 · **Branch:** `feat/identity-account-error-codes-1-codes` · **Status:** slice 1 implemented, awaiting review
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-atomic-write-read` (PR #214)

## Objective

The public account endpoints answer every error with a machine-readable `code` next to the human-readable `error` message, documented in OpenAPI as an enum. The SPA account pages branch on `code === 'invalid_account_link'` instead of matching the English text "invalid or has expired". Follow-up of `admin-users` (slice 8 review); roadmap backlog B2.

Out of scope:

- localizing messages (the `error` texts stay as they are);
- codes on any other endpoint: login, current user, Admin, Play, Studio keep the shared `ErrorResponse`;
- new error cases or status codes.

## Problem and why

`frontend/src/app/account/accountApi.ts` decides whether a 422 means "dead link" by `reason.includes('invalid or has expired')`. The wording is pinned on both sides by comments only (`INVALID_LINK` in `PasswordResetApiTest` and `InvitationApiTest`), so a copy edit on the backend silently turns a dead link into a form error. A code is a contract the typed client checks.

## Model summary (today)

- `backend/src/Identity/Infrastructure/Http/PublicAccountController.php`: four public endpoints, all errors through `error(string $message, int $status)` → `ErrorResponse::withMessage()` (`{error}`).

| Endpoint | Errors |
|---|---|
| `POST /api/auth/password-reset-requests` | 400 malformed body, 415 not JSON, 422 `InvalidEmail` |
| `POST /api/auth/password-resets` | 400, 415, 422 `WeakPassword` or `InvalidAccountLink` |
| `POST /api/auth/invitations/lookup` | 400, 415, 422 `InvalidAccountLink` |
| `POST /api/auth/invitations/accept` | 400, 415, 409 `EmailAlreadyInUse`, 422 `WeakPassword` or `InvalidAccountLink` |

- `backend/src/Shared/Infrastructure/Http/ErrorResponse.php`: `{error}` only, used by ~35 files across contexts.
- SPA: `AccountError.invalidLink` read by `ResetPasswordPage.tsx` and `AcceptInvitationPage.tsx`; tests and stories mock `{ error: '…' }` bodies.

## Decisions

| Question | Decision |
|---|---|
| Shared shape or account endpoints only | **Account endpoints only.** A new Identity schema `AccountErrorResponse` `{error, code}` with `code` required, used by every error response of the four public account endpoints. The shared `ErrorResponse` stays `{error}`. Why: a `code` on the shared shape would be optional everywhere (35 call sites, no codes defined for them) and type to `string | undefined` in the client; a dedicated schema gives these endpoints a required, closed enum the client can branch on, and no other endpoint has a consumer that needs codes today. A later feature can promote the pattern if one appears |
| Code values | Backed string enum `AccountErrorCode` (Identity `Infrastructure/Http`, the HTTP contract, not the domain): `invalid_account_link` (422 `InvalidAccountLink`), `weak_password` (422 `WeakPassword`), `invalid_email` (422 `InvalidEmail`), `email_already_in_use` (409 `EmailAlreadyInUse`), `malformed_request` (400), `not_json` (415). Every error body of these endpoints carries one, so the response schema is uniform |
| OpenAPI | `code` documented as an enum of the six values; each endpoint's error responses reference `AccountErrorResponse`; descriptions say which codes each status can carry |
| Messages | Unchanged texts; `error` stays the message meant for people |
| SPA | `AccountError` gains `code` (`AccountErrorCode | null`, null for generic failures); `invalidLink` is `code === 'invalid_account_link'` on a 422; the `INVALID_LINK` text constant and its comment go. An unknown or missing code fails closed to "not a dead link" and shows the message as today |
| Tests | Backend API test per code per endpoint that can raise it (body equals `{error, code}` exactly); the `INVALID_LINK` constants include the code. Frontend: page tests and stories mock bodies with `code`; one test that a 422 whose message mentions "invalid or has expired" but has another code is not treated as a dead link |

## Constraints

- Framework-free Domain; the change stays in `Identity/Infrastructure/Http` and the SPA account area.
- Status codes and messages per endpoint unchanged.
- `make api` regenerates `openapi.json` and `schema.d.ts`; both committed.
- Planning heuristic only: the forecast below is not a cap.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `feat/identity-account-error-codes-1-codes` | T1–T2 | ~600–900 changed lines (controller + enum + schema ~120, API tests ~250, generated `openapi.json` / `schema.d.ts` ~150, SPA + tests + stories ~200, this doc ~100) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Backend: `AccountErrorCode` enum, `AccountErrorResponse` schema, every error of the four public account endpoints answers `{error, code}`; OpenAPI documented; API tests per code; `make api` | delegated writer (slice 1; feature doc inline by parent) | [x] | |
| T2 | 1 | SPA: `AccountError.code`, dead-link branch on `invalid_account_link`, text matching removed; page tests and stories with codes | delegated writer (slice 1) | [x] | |

## Progress

- 2026-10-19: feature doc created (parent); four endpoints and six error cases mapped; decision: account-only `AccountErrorResponse`; one slice.
- 2026-10-19: T1 done (RED: 23 API test failures on the new bodies; GREEN: 35 tests); `AccountErrorCode` is rendered by NelmioApiDoc as its own string-enum schema, `AccountErrorResponse.code` references it; `openapi.json` lives in `frontend/src/shared/api/`.
- 2026-10-19: T2 done (RED: 1 failing test, a 422 worded as a dead link with `weak_password` was treated as one; GREEN: 34 account tests); `accountApi.ts` keeps only codes it knows, an unknown or missing code is `null`.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1 review.
