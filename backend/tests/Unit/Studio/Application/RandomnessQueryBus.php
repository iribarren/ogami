<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Randomness\Application\AskLikelihoodOracle;
use App\Randomness\Application\AskLikelihoodOracleHandler;
use App\Randomness\Application\ResolveOracleTable;
use App\Randomness\Application\ResolveOracleTableHandler;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;

/**
 * A query bus that answers the two Randomness queries Studio's test rolls dispatch, from a given
 * randomness source, and records them.
 */
final class RandomnessQueryBus implements QueryBus
{
    /** @var list<Query<mixed>> */
    public array $asked = [];

    public function __construct(
        private readonly RandomNumberGenerator $random,
    ) {
    }

    /**
     * @template TResult
     *
     * @param Query<TResult> $query
     *
     * @return TResult
     */
    public function ask(Query $query): mixed
    {
        $this->asked[] = $query;

        // Each handler answers with the result type its query declares.
        // @phpstan-ignore return.type
        return match (true) {
            $query instanceof ResolveOracleTable => (new ResolveOracleTableHandler($this->random))($query),
            $query instanceof AskLikelihoodOracle => (new AskLikelihoodOracleHandler($this->random))($query),
            default => throw new \LogicException('Unexpected query '.$query::class),
        };
    }
}
