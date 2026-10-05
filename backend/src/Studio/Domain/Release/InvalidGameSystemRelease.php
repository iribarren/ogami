<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

final class InvalidGameSystemRelease extends \DomainException
{
    public static function versionBelowOne(int $version): self
    {
        return new self(\sprintf('A release version starts at 1, %d given.', $version));
    }
}
