<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvitationExpired extends \DomainException
{
    public static function at(\DateTimeImmutable $expiresAt): self
    {
        return new self(\sprintf('The invitation expired on %s.', $expiresAt->format(\DATE_ATOM)));
    }
}
