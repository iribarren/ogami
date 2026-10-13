<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * A Character was given values that break its rules or the pinned release's sheet; nothing is
 * changed.
 */
final class InvalidCharacter extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
