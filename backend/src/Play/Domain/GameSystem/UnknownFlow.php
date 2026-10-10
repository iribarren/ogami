<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * The release declares no Flow with this key.
 */
final class UnknownFlow extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Flow "%s" not found.', $key));
    }
}
