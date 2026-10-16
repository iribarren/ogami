<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Deletes a likelihood oracle of the owner's GameSystem draft. $expectedVersion is the draft
 * version the client read.
 */
final readonly class DeleteLikelihoodOracle implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $oracle,
        public int $expectedVersion,
    ) {
    }
}
