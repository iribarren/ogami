<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * Publishing a draft would repeat the latest release: its content is the same.
 */
final class GameSystemDraftUnchanged extends \DomainException
{
    public static function for(string $gameSystemKey, int $latestVersion): self
    {
        return new self(\sprintf('No changes: "%s" is the same as its release v%d.', $gameSystemKey, $latestVersion));
    }
}
