<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft has no check with this key.
 */
final class CheckNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The draft has no check "%s".', $key));
    }
}
