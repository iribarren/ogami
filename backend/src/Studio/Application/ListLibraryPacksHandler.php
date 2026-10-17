<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemRepository;

final readonly class ListLibraryPacksHandler implements QueryHandler
{
    /** The sections a pack holds, as SectionCounts names them. */
    private const array PACK_SECTIONS = ['oracleTables', 'likelihoodOracles', 'trackers', 'tags', 'factSlots', 'sceneTypes'];

    public function __construct(
        private GameSystemRepository $gameSystems,
    ) {
    }

    /**
     * @return list<LibraryPackSummary> the reader's packs first, then by name
     */
    public function __invoke(ListLibraryPacks $query): array
    {
        $summaries = array_map(static function (GameSystem $pack) use ($query): LibraryPackSummary {
            $metadata = $pack->document()['gameSystem'] ?? null;
            $description = \is_array($metadata) ? ($metadata['description'] ?? null) : null;
            $counts = SectionCounts::of($pack->document(), $pack->schemaVersion());

            return new LibraryPackSummary(
                $pack->key(),
                $pack->name(),
                \is_string($description) ? $description : null,
                $pack->isOwnedBy($query->userId),
                array_combine(self::PACK_SECTIONS, array_map(static fn (string $section): int => $counts[$section] ?? 0, self::PACK_SECTIONS)),
                $pack->updatedAt(),
            );
        }, $this->gameSystems->libraryPacks());

        usort($summaries, static fn (LibraryPackSummary $a, LibraryPackSummary $b): int => [!$a->mine, mb_strtolower($a->name), $a->key] <=> [!$b->mine, mb_strtolower($b->name), $b->key]);

        return $summaries;
    }
}
