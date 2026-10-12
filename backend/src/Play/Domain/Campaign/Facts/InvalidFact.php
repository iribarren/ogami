<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * A Campaign fact was given values that break its rules (see Fact), or a slot a value that does not
 * fit it; nothing is changed.
 */
final class InvalidFact extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
