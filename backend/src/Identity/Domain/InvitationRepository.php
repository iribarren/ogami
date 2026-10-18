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
}
