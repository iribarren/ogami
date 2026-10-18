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
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvitationNotFound when the id is blank, unknown, or the invitation is not pending
     */
    public function pending(string $id): Invitation
    {
        try {
            $invitation = $this->invitations->ofId(InvitationId::fromString($id));
        } catch (\InvalidArgumentException) {
            $invitation = null;
        }

        return $invitation instanceof Invitation && $invitation->isPending() ? $invitation : throw InvitationNotFound::withId($id);
    }

    /**
     * The pending, unexpired invitation behind a link.
     *
     * @throws InvalidAccountLink when the token is blank or unknown, or its invitation is accepted, revoked or expired
     */
    public function usableForToken(#[\SensitiveParameter] string $token): Invitation
    {
        try {
            $invitation = $this->invitations->ofTokenHash(AccountToken::fromString($token)->hash());
        } catch (\InvalidArgumentException) {
            throw InvalidAccountLink::create();
        }

        if (!$invitation instanceof Invitation || !$invitation->isPending() || $invitation->isExpiredAt($this->clock->now())) {
            throw InvalidAccountLink::create();
        }

        return $invitation;
    }
}
