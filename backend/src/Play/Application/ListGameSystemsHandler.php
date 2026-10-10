<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ListGameSystemsHandler implements QueryHandler
{
    public function __construct(
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @return list<GameSystemSummary> ordered by name ignoring case, then key, each with the Flows
     *                                 of its release (none when Play cannot read the release, which
     *                                 is listed as before; creating a campaign with it fails)
     */
    public function __invoke(ListGameSystems $query): array
    {
        return array_map($this->withFlows(...), $this->releases->latest());
    }

    private function withFlows(GameSystemSummary $summary): GameSystemSummary
    {
        try {
            $flows = $this->releases->get($summary->gameSystemKey, $summary->version)->flows();
        } catch (GameSystemReleaseNotFound|UnsupportedReleaseSchemaVersion|InvalidGameSystemRelease) {
            return $summary;
        }

        return new GameSystemSummary(
            $summary->gameSystemKey,
            $summary->name,
            $summary->description,
            $summary->version,
            array_map(FlowSummaryView::of(...), $flows),
        );
    }
}
