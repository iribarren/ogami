<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another field of the draft's sheet already uses this key: field keys are unique within the sheet.
 */
final class SheetFieldKeyTaken extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The sheet already has a field "%s".', $key));
    }
}
