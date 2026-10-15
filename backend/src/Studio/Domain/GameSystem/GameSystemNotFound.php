<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The user has no GameSystem with this key. Also raised for another owner's GameSystem, so a key's
 * owner is never revealed (ADR 0028).
 */
final class GameSystemNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('No GameSystem "%s".', $key));
    }
}
