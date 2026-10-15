<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another GameSystem, or an existing release (a preset, for one), already uses this key.
 */
final class GameSystemKeyTaken extends \DomainException
{
    public static function withKey(string $key, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The GameSystem key "%s" is already taken.', $key), 0, $previous);
    }
}
