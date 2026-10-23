<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A published release was found but its stored content no longer parses. Studio validates a release
 * before publishing it, so this signals a broken contract, not a user error.
 */
final class PublishedReleaseUnreadable extends \RuntimeException
{
    public function __construct(
        public readonly string $gameSystemKey,
        public readonly int $version,
        public readonly string $path,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Published release v%d of GameSystem "%s" cannot be read: %s: %s', $version, $gameSystemKey, $path, $reason),
            previous: $previous,
        );
    }
}
