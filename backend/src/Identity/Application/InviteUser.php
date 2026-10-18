<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Invites an email to create an account with the given roles, and mails the link.
 * The caller picks the invitation id (see InvitationIdGenerator) so it knows the new
 * invitation without the command returning a value.
 */
final readonly class InviteUser implements Command
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $invitationId,
        public string $email,
        public array $roles,
        public string $invitedBy,
    ) {
    }
}
