<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * No published release matches the GameSystem key (and version) Play asked for.
 */
final class GameSystemReleaseNotFound extends \RuntimeException
{
    public static function for(string $gameSystemKey, ?int $version, ?\Throwable $previous = null): self
    {
        return new self(null === $version
            ? \sprintf('No published release of GameSystem "%s" is available to Play.', $gameSystemKey)
            : \sprintf('No published release v%d of GameSystem "%s" is available to Play.', $version, $gameSystemKey), previous: $previous);
    }
}
