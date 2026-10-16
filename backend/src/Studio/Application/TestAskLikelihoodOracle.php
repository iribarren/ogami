<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks a likelihood oracle of the owner's saved GameSystem draft a question with a likelihood
 * level and an optional chaos factor (the oracle's neutral one when null). A test roll never
 * changes the draft.
 *
 * @implements Query<LikelihoodTestView>
 */
final readonly class TestAskLikelihoodOracle implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $oracle,
        public string $likelihood,
        public ?int $chaosFactor,
    ) {
    }
}
