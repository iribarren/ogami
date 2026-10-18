<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetGameSystemHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystem $query): GameSystemView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $publication = GameSystemPublication::of($gameSystem, $this->releases);
        $metadata = $gameSystem->document()['gameSystem'] ?? null;
        $metadata = \is_array($metadata) ? $metadata : [];
        $credits = $metadata['credits'] ?? null;
        $theme = $gameSystem->document()['theme'] ?? null;

        return new GameSystemView(
            $gameSystem->key(),
            $gameSystem->kind(),
            $gameSystem->name(),
            \is_string($metadata['description'] ?? null) ? $metadata['description'] : null,
            \is_array($credits) ? $credits : null,
            \is_string($theme) ? $theme : null,
            $gameSystem->schemaVersion(),
            SectionCounts::of($gameSystem->document(), $gameSystem->schemaVersion()),
            $publication->latestReleaseVersion,
            $publication->hasUnpublishedChanges,
            $gameSystem->createdAt(),
            $gameSystem->updatedAt(),
        );
    }
}
