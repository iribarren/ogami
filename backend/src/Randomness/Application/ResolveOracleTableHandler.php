<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ResolveOracleTableHandler implements QueryHandler
{
    public function __construct(
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws InvalidOracleTable when the table set is invalid, has no such table, or a roll matches no entry
     */
    public function __invoke(ResolveOracleTable $query): OracleTableResultView
    {
        return OracleTableResultView::fromResult(
            $query->table,
            OracleTableSet::fromArray($query->tables)->resolve($query->table, $this->random),
        );
    }
}
