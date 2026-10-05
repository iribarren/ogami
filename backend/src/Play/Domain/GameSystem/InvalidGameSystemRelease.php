<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A published release of a supported schema version that Play still cannot read. Studio validates
 * every release before publishing it, so this signals a broken contract, not a user error.
 */
final class InvalidGameSystemRelease extends \RuntimeException
{
    public static function of(string $gameSystemKey, int $version, string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('GameSystem "%s" v%d cannot be read by Play: %s: %s', $gameSystemKey, $version, $path, $reason), previous: $previous);
    }
}
