<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * No published release matches the GameSystem key (and version) asked for.
 */
final class PublishedReleaseNotFound extends \RuntimeException
{
    public static function for(string $gameSystemKey, ?int $version): self
    {
        return new self(null === $version
            ? \sprintf('No published release of GameSystem "%s".', $gameSystemKey)
            : \sprintf('No published release v%d of GameSystem "%s".', $version, $gameSystemKey));
    }
}
