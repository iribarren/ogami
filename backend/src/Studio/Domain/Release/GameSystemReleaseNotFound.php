<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * The GameSystem has no release with this version.
 */
final class GameSystemReleaseNotFound extends \DomainException
{
    public static function for(string $gameSystemKey, int $version): self
    {
        return new self(\sprintf('No release %s v%d.', $gameSystemKey, $version));
    }
}
