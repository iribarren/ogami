<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A GameSystem with releases cannot be discarded: campaigns may be pinned to them.
 */
final class GameSystemHasReleases extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('GameSystem "%s" has releases and cannot be discarded.', $key));
    }
}
