<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;

/**
 * Where a draft stands against its releases: the latest release version, and whether publishing
 * now would add something (no release yet, an invalid draft, or content that differs from the
 * latest release's hash).
 */
final readonly class GameSystemPublication
{
    private function __construct(
        public ?int $latestReleaseVersion,
        public bool $hasUnpublishedChanges,
    ) {
    }

    public static function of(GameSystem $gameSystem, GameSystemReleaseRepository $releases): self
    {
        $latest = $releases->latestFor($gameSystem->key());
        if (!$latest instanceof \App\Studio\Domain\Release\GameSystemRelease) {
            return new self(null, true);
        }

        try {
            $changed = ReleaseContent::fromArray($gameSystem->document())->hash() !== $latest->contentHash();
        } catch (InvalidReleaseContent) {
            $changed = true;
        }

        return new self($latest->version(), $changed);
    }
}
