# 0034. Admin user management, invitations and password reset

- **Status:** Accepted
- **Date:** 2026-10-18

## Context

Ogami is for one to a few people ([vision](../vision.md)). Today a user exists only through the console command `app:user:create`. The `User` aggregate holds an email, a password hash and roles, and nothing can change it ([ADR 0006](0006-session-cookie-auth-roles.md)). The owner has an empty Admin area. The context map makes Admin a customer of Identity & Access: Admin use cases call Identity application services. No code sends email, though Mailpit runs in the dev stack.

## Decision

- **Invite-only.** There is no public registration. The owner invites an email with one or more roles. The invitee opens the emailed link, sees the email and roles, and sets a password; that creates an active account with those roles. An invitation is *pending*, *accepted* or *revoked*, and expires after 7 days. The owner resends (new link, new expiry) or revokes a pending invitation. An email that already has an account, or a pending invitation, cannot be invited again.
- **Disable, never delete.** A user is *active* or *disabled*. A disabled user cannot sign in, and their session ends on the next request. The owner can enable them again. Their campaigns, drafts and library packs stay. There is no delete.
- **Roles.** The owner sets a user's roles (at least one). The owner cannot disable their own account or remove their own `OWNER` role, so there is always an active owner.
- **Password reset.** "Forgot password" on the login page emails a reset link valid for one hour. The answer is the same whether the email has an account or not, and requests are rate-limited per email and per client IP. The owner can also send a reset link from the user list. A new link replaces older ones. Setting the password through the link uses it up and ends the user's other sessions. A disabled user gets no link, and a link cannot be used while the user is disabled.
- **Links and tokens.** Every link carries a random 256-bit token in the URL fragment (`/accept-invitation#token=…`, `/reset-password#token=…`), so it reaches neither server logs nor `Referer`. Only the token's SHA-256 hash is stored. An unknown, expired, used or revoked token gets one generic "invalid or expired link" answer.
- **Passwords.** A password set through a link has 8 to 4,096 characters.
- **Where it lives.** Identity & Access owns users, invitations, password resets, the mail port and the public endpoints under `/api/auth/…`. Admin exposes `/api/admin/…` for `OWNER` only and calls Identity application services (commands and queries); it never touches Identity's domain or infrastructure ([ADR 0012](0012-phpat-boundary-enforcement.md)).
- **Mail.** Symfony Mailer sends plain text and simple HTML emails, built in code with no template engine, after the command's transaction commits. In dev every email lands in Mailpit. A mail failure leaves the invitation or reset in place, so the owner can resend.

## Consequences

- The first owner is still created with `app:user:create`. After that, every account comes from an invitation.
- Identity gains its first email dependency and its first public, unauthenticated write endpoints, so it needs rate limits.
- A user's roles or password changing ends their current session, as Symfony Security does today. A role change therefore signs the user out.
- Emails cannot be changed and accounts cannot be deleted. Either needs a new decision.
