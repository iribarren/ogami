<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * An NPC and a Character do not agree on a promotion: each side of it must name the other. Only
 * stored data can break this; the promotion itself always writes both sides.
 */
final class InvalidPromotion extends \DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
