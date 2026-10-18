<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvitationId;

final class InvitationIdAlreadyInUse extends \RuntimeException
{
    public static function for(InvitationId $id): self
    {
        return new self(\sprintf('An invitation with id "%s" already exists.', $id->toString()));
    }
}
