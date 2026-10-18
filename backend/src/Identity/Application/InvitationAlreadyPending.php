<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;

/**
 * The email already has a pending invitation that has not expired. Also raised when a
 * concurrent request wins the race (the unique index on pending invitations).
 */
final class InvitationAlreadyPending extends \RuntimeException
{
    public static function for(Email $email): self
    {
        return new self(\sprintf('"%s" already has a pending invitation.', $email->toString()));
    }
}
