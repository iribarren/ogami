<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;

/**
 * Finds the invitation a command or query targets, by id (the owner) or by link token
 * (the invited person). Anything unusable is the same refusal, never a leaked detail.
 */
final readonly class InvitationLoader
{
    public function __construct(
        private InvitationRepository $invitations,
    ) {
    }

    /**
     * Locks the invitation's row, so a resend or revoke cannot interleave with an accept.
     *
     * @throws InvitationNotFound when the id is blank, unknown, or the invitation is not pending
     */
    public function pending(string $id): Invitation
    {
        try {
            $invitation = $this->invitations->ofIdForUpdate(InvitationId::fromString($id));
        } catch (\InvalidArgumentException) {
            $invitation = null;
        }

        return $invitation instanceof Invitation && $invitation->isPending() ? $invitation : throw InvitationNotFound::withId($id);
    }

    /**
     * The pending invitation behind a link, locked, that is still valid at $now (the caller's
     * single reading of the clock, so the invitation cannot expire between this check and the command).
     *
     * @throws InvalidAccountLink when the token is blank or unknown, or its invitation is accepted, revoked or expired
     */
    public function usableForToken(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): Invitation
    {
        return $this->usable($token, $now, true);
    }

    /**
     * Like usableForToken(), for a read-only question: no lock.
     *
     * @throws InvalidAccountLink
     */
    public function usableForLookup(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): Invitation
    {
        return $this->usable($token, $now, false);
    }

    private function usable(#[\SensitiveParameter] string $token, \DateTimeImmutable $now, bool $lock): Invitation
    {
        try {
            $hash = AccountToken::fromString($token)->hash();
            $invitation = $lock ? $this->invitations->ofTokenHashForUpdate($hash) : $this->invitations->ofTokenHash($hash);
        } catch (\InvalidArgumentException) {
            throw InvalidAccountLink::create();
        }

        if (!$invitation instanceof Invitation || !$invitation->isPending() || $invitation->isExpiredAt($now)) {
            throw InvalidAccountLink::create();
        }

        return $invitation;
    }
}
