<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A Studio GameSystem owns this key, so its releases come from Studio only; a release file cannot
 * add to its line (ADR 0028).
 */
final class GameSystemManagedInStudio extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The GameSystem key "%s" belongs to a GameSystem authored in Studio; publish it from Studio.', $key));
    }
}
