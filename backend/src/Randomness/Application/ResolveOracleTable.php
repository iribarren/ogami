<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for a roll on one oracle table of a table set, following nested tables.
 * Fails with InvalidOracleTable when the set is invalid, has no table with this
 * key, or a roll matches no entry.
 *
 * @implements Query<OracleTableResultView>
 */
final readonly class ResolveOracleTable implements Query
{
    /**
     * @param array<mixed> $tables the table set, as decoded JSON (see OracleTableSet::fromArray)
     * @param string       $table  the key of the table to roll on
     */
    public function __construct(
        public array $tables,
        public string $table,
    ) {
    }
}
