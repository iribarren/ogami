<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * An NPC was given values that break its rules (see ItemRules); nothing is changed.
 */
final class InvalidNpc extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
