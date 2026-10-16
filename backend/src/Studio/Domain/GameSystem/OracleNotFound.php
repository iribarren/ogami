<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft has no oracle table or likelihood oracle with this key.
 */
final class OracleNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The draft has no oracle "%s".', $key));
    }
}
