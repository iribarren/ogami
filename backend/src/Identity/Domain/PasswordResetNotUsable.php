<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class PasswordResetNotUsable extends \DomainException
{
    public static function alreadyUsed(\DateTimeImmutable $usedAt): self
    {
        return new self(\sprintf('This password reset was already used at %s.', $usedAt->format(\DATE_ATOM)));
    }

    public static function expired(\DateTimeImmutable $expiresAt): self
    {
        return new self(\sprintf('This password reset expired at %s.', $expiresAt->format(\DATE_ATOM)));
    }
}
