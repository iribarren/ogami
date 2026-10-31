<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * A Place was given values that break its rules (see ItemRules); nothing is changed.
 */
final class InvalidPlace extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
