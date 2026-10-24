# 0037. API error codes

- **Status:** Accepted
- **Date:** 2026-10-24

## Context

Most error bodies are `{error}`: a human-readable message. That is enough while the SPA only shows the message. It is not enough when the SPA must do something different per cause.

Identity already solved this: its public account endpoints answer `{error, code}`, with `code` an OpenAPI enum (`AccountErrorCode`, `AccountErrorResponse`, feature `identity-account-error-codes`). Two other places still fail to tell causes apart:

- Studio's publish 409 covers three causes (no changes since the latest release, the version race, a library pack) and the restore 409 covers two (a concurrent save, a library pack). `PublishDialog` shows one fixed message for all of them, and that message never covers library packs.
- Admin's SPA matches message text (`frontend/src/admin/adminError.ts`), which breaks when a message is reworded.

## Decision

- **When the SPA needs to branch on the cause of an error, the body is `{error, code}`.** `error` stays a human-readable message; the SPA never branches on it.
- **`code` is a string enum, and it is part of the HTTP contract.** The OpenAPI enum is the contract: the generated client types it, and a value never changes with the wording of a message.
- **Follow Identity's pattern:** a backed enum and a response schema per area (`required: ['error', 'code']`), mapped in the controller from each domain exception.
- **Adopt it when an endpoint is next touched.** No sweep over existing endpoints. Admin's message matching moves to codes when Admin next changes.
- **Studio publish and restore adopt it now:** publish 409 is `no_changes`, `release_exists` or `library_pack`; restore 409 is `modified_concurrently` or `library_pack`.

## Consequences

- Each area owns its codes; there is no global code registry.
- Adding a code is additive; renaming or removing one is a breaking API change.
- Endpoints that stay on `{error}` are unchanged, so the SPA keeps two error shapes until each area is touched.
- Every code needs an API test that provokes it.
