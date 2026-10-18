<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvitationNotPending extends \DomainException
{
    public static function cannotRevoke(InvitationStatus $status): self
    {
        return new self(\sprintf('Only a pending invitation can be revoked, this one is %s.', $status->value));
    }
}
