<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft's sheet has no field with this key.
 */
final class SheetFieldNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The sheet has no field "%s".', $key));
    }
}
