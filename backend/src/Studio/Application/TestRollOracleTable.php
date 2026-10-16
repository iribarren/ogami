<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Application\OracleTableResultView;
use App\Shared\Application\Bus\Query;

/**
 * Rolls on an oracle table of the owner's saved GameSystem draft, following nested tables. A test
 * roll never changes the draft.
 *
 * @implements Query<OracleTableResultView>
 */
final readonly class TestRollOracleTable implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $table,
    ) {
    }
}
