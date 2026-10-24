<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * A Character was given values that break its rules or the pinned release's sheet; nothing is
 * changed. $refusal says which rule of structured character creation (ADR 0040) it broke, null for
 * any other.
 */
final class InvalidCharacter extends \DomainException
{
    public function __construct(string $message, public readonly ?CreationRefusal $refusal = null)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function refused(CreationRefusal $refusal, string $reason): self
    {
        return new self($reason, $refusal);
    }
}
