<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class ListPublishedGameSystemsHandler implements QueryHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @return list<PublishedGameSystemSummary> ordered by name ignoring case, then key
     */
    public function __invoke(ListPublishedGameSystems $query): array
    {
        $summaries = array_map($this->summary(...), $this->releases->latestOfEachKey());

        usort($summaries, static fn (PublishedGameSystemSummary $a, PublishedGameSystemSummary $b): int => [mb_strtolower($a->name), $a->gameSystemKey] <=> [mb_strtolower($b->name), $b->gameSystemKey]);

        return $summaries;
    }

    private function summary(GameSystemRelease $release): PublishedGameSystemSummary
    {
        $content = $release->content();
        $gameSystem = $content->toArray()['gameSystem'] ?? null;
        $description = \is_array($gameSystem) && \is_string($gameSystem['description'] ?? null) ? $gameSystem['description'] : null;

        return new PublishedGameSystemSummary(
            $release->gameSystemKey(),
            $content->gameSystemName(),
            $description,
            $release->version(),
            $release->publishedAt(),
        );
    }
}
