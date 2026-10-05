<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

final class InvalidPinnedRelease extends \DomainException
{
    public static function blankKey(): self
    {
        return new self('A pinned release needs a GameSystem key.');
    }

    public static function versionBelowOne(int $version): self
    {
        return new self(\sprintf('A pinned release version must be at least 1, got %d.', $version));
    }

    public static function blankName(): self
    {
        return new self('A pinned release needs a GameSystem name.');
    }
}
