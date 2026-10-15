<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystem;
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
            $gameSystem->name(),
            \is_string($metadata['description'] ?? null) ? $metadata['description'] : null,
            \is_array($credits) ? $credits : null,
            \is_string($theme) ? $theme : null,
            $gameSystem->schemaVersion(),
            $this->sectionCounts($gameSystem),
            $publication->latestReleaseVersion,
            $publication->hasUnpublishedChanges,
            $gameSystem->createdAt(),
            $gameSystem->updatedAt(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function sectionCounts(GameSystem $gameSystem): array
    {
        $document = $gameSystem->document();
        if (1 === $gameSystem->schemaVersion()) {
            return [
                'oracles' => $this->count($document, 'oracles', 'tables') + $this->count($document, 'oracles', 'likelihood'),
                'flowSteps' => $this->count($document, 'flow', 'steps'),
            ];
        }

        return [
            'oracleTables' => $this->count($document, 'oracles', 'tables'),
            'likelihoodOracles' => $this->count($document, 'oracles', 'likelihood'),
            'trackers' => $this->count($document, 'trackers'),
            'factSlots' => $this->count($document, 'factSlots'),
            'tags' => $this->count($document, 'tags'),
            'sceneTypes' => $this->count($document, 'sceneTypes'),
            'flows' => $this->count($document, 'flows'),
            'sheetFields' => $this->count($document, 'sheet', 'fields'),
            'checks' => $this->count($document, 'checks'),
        ];
    }

    /**
     * The number of items of the list at the path, 0 when the draft has none there (or something else).
     *
     * @param array<string, mixed> $document
     */
    private function count(array $document, string ...$path): int
    {
        $node = $document;
        foreach ($path as $segment) {
            $node = \is_array($node) ? ($node[$segment] ?? null) : null;
        }

        return \is_array($node) ? \count($node) : 0;
    }
}
