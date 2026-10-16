<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\AskLikelihoodOracle;
use App\Randomness\Application\ResolveOracleTable;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\OracleNotFound;

final readonly class TestAskLikelihoodOracleHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private QueryBus $queries,
    ) {
    }

    /**
     * @throws GameSystemNotFound      when the user has no such GameSystem
     * @throws OracleNotFound          when the draft has no likelihood oracle with this key
     * @throws InvalidLikelihoodOracle when the oracle is invalid, the level is unknown or the chaos factor is not allowed
     * @throws InvalidOracleTable      when the random event table set is not one Randomness accepts, or a roll matches no entry
     */
    public function __invoke(TestAskLikelihoodOracle $query): LikelihoodTestView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $answer = $this->queries->ask(new AskLikelihoodOracle(StoredOracles::likelihoodOracle($gameSystem, $query->oracle), $query->likelihood, $query->chaosFactor));

        // The answer names the table only when the roll signalled a random event and the oracle has one.
        $randomEvent = null === $answer->randomEvent ? null : $this->queries->ask(new ResolveOracleTable(StoredOracles::tables($gameSystem), $answer->randomEvent));

        return new LikelihoodTestView($answer, $randomEvent);
    }
}
