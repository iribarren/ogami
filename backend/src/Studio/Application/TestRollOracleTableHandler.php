<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\OracleTableResultView;
use App\Randomness\Application\ResolveOracleTable;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\OracleNotFound;

final readonly class TestRollOracleTableHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private QueryBus $queries,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     * @throws OracleNotFound     when the draft has no table with this key
     * @throws InvalidOracleTable when the draft's table set is not one Randomness accepts, or a roll matches no entry
     */
    public function __invoke(TestRollOracleTable $query): OracleTableResultView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        StoredOracles::assertTable($gameSystem, $query->table);

        // The whole set, so that nested tables resolve.
        return $this->queries->ask(new ResolveOracleTable(StoredOracles::tables($gameSystem), $query->table));
    }
}
