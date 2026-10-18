<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Port: where invitations are kept.
 */
interface InvitationRepository
{
    public function save(Invitation $invitation): void;

    public function ofId(InvitationId $id): ?Invitation;

    /**
     * Like ofId(), but locks the row until the command's transaction ends and returns its
     * current state, so two commands on the same invitation (accept and revoke) run one after the other.
     */
    public function ofIdForUpdate(InvitationId $id): ?Invitation;

    /**
     * The pending invitation for this email, expired or not.
     */
    public function pendingForEmail(Email $email): ?Invitation;

    /**
     * Every pending invitation, expired or not, ordered by email.
     *
     * @return list<Invitation>
     */
    public function pending(): array;

    /**
     * @param string $tokenHash see AccountToken::hash()
     */
    public function ofTokenHash(string $tokenHash): ?Invitation;

    /**
     * Like ofTokenHash(), with the lock and fresh state of ofIdForUpdate().
     *
     * @param string $tokenHash see AccountToken::hash()
     */
    public function ofTokenHashForUpdate(string $tokenHash): ?Invitation;
}
