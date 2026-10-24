<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

/**
 * @phpstan-import-type SectionChanges from ReleaseDiff
 */
final readonly class GetGameSystemReleaseHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound        when the user has no such GameSystem
     * @throws GameSystemReleaseNotFound when it has no such release
     */
    public function __invoke(GetGameSystemRelease $query): GameSystemReleaseView
    {
        $this->owned->get($query->ownerId, $query->key);
        $release = $this->releases->get($query->key, $query->version);
        if (!$release instanceof GameSystemRelease) {
            throw GameSystemReleaseNotFound::for($query->key, $query->version);
        }

        $document = $release->content()->toArray();
        $gameSystem = $document['gameSystem'];
        \assert(\is_array($gameSystem) && \is_string($gameSystem['name']));
        $schemaVersion = $release->schemaVersion();

        return new GameSystemReleaseView(
            GameSystemReleaseSummary::of($release),
            $gameSystem['name'],
            SectionCounts::of($document, $schemaVersion),
            ReleaseOutline::of($document, $schemaVersion),
            $this->changes($query, $document, $schemaVersion),
        );
    }

    /**
     * What the release changed against version N-1, null for the first release (or when N-1 is gone).
     *
     * @param array<string, mixed> $document
     *
     * @return array{previousVersion: int, previousSchemaVersion: int, sections: array<string, SectionChanges>|null}|null
     */
    private function changes(GetGameSystemRelease $query, array $document, int $schemaVersion): ?array
    {
        $previous = $query->version > 1 ? $this->releases->get($query->key, $query->version - 1) : null;
        if (!$previous instanceof GameSystemRelease) {
            return null;
        }

        return [
            'previousVersion' => $previous->version(),
            'previousSchemaVersion' => $previous->schemaVersion(),
            'sections' => ReleaseDiff::of($document, $schemaVersion, $previous->content()->toArray(), $previous->schemaVersion()),
        ];
    }
}
