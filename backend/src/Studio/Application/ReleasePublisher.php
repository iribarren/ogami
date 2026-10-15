<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidGameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;

/**
 * The one place that writes a GameSystem release: the next version of its GameSystem key, for the
 * console publisher and for publishing a Studio draft alike.
 */
final readonly class ReleasePublisher
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @param bool $unlessUnchanged publish nothing when the latest release has the same content
     *
     * @return bool whether a release was published
     *
     * @throws InvalidGameSystemRelease       when the notes are blank or too long; nothing is published
     * @throws GameSystemReleaseAlreadyExists when another publish took the version first
     */
    public function publish(ReleaseId $id, ReleaseContent $content, ?string $notes, bool $unlessUnchanged): bool
    {
        $latest = $this->releases->latestFor($content->gameSystemKey());
        if ($unlessUnchanged && $latest?->contentHash() === $content->hash()) {
            return false;
        }

        $this->releases->add(GameSystemRelease::publish(
            $id,
            $content,
            ($latest?->version() ?? 0) + 1,
            $this->clock->now(),
            $notes,
        ));

        return true;
    }
}
