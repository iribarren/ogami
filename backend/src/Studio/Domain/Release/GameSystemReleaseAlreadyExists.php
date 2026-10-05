<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * A release with this GameSystem key and version is already kept, e.g. because another publish
 * of the same GameSystem took that version at the same time. Releases are never overwritten.
 */
final class GameSystemReleaseAlreadyExists extends \DomainException
{
    private function __construct(
        public readonly string $gameSystemKey,
        public readonly int $version,
        ?\Throwable $previous,
    ) {
        parent::__construct(\sprintf('Release %s v%d already exists.', $gameSystemKey, $version), 0, $previous);
    }

    public static function for(string $gameSystemKey, int $version, ?\Throwable $previous = null): self
    {
        return new self($gameSystemKey, $version, $previous);
    }
}
