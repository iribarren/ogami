<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class GetGameSystemOraclesHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemOracles $query): GameSystemOraclesView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $document = $gameSystem->document();
        $oracles = \is_array($document['oracles'] ?? null) ? $document['oracles'] : [];

        return new GameSystemOraclesView(
            $gameSystem->version(),
            $gameSystem->schemaVersion(),
            $this->views($document, $oracles['tables'] ?? null, true),
            $this->views($document, $oracles['likelihood'] ?? null, false),
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<OracleView>
     */
    private function views(array $document, mixed $items, bool $tables): array
    {
        $views = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item) || !\is_string($item['key'] ?? null)) {
                continue;
            }

            // A table's own entries nesting itself do not count as use, as when it is renamed or deleted.
            $views[] = new OracleView($item['key'], $item, DraftReferences::oraclePathsTo($document, $item['key'], $tables ? $item['key'] : null));
        }

        return $views;
    }
}
