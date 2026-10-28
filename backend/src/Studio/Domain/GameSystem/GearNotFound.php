<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft's gear catalog has no entry with this key.
 */
final class GearNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The gear catalog has no entry "%s".', $key));
    }
}
