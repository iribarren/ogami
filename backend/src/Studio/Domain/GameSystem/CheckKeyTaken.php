<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another check of the draft already uses this key: check keys are unique.
 */
final class CheckKeyTaken extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The draft already has a check "%s".', $key));
    }
}
